<?php
/**
 * REST controller handling conversation and message endpoints for the chat feature.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Modules\Chat;

use WP_AI_Mind\DB\ConversationStore;
use WP_AI_Mind\Providers\ProviderFactory;
use WP_AI_Mind\Providers\CompletionRequest;
use WP_AI_Mind\Providers\CompletionResponse;
use WP_AI_Mind\Providers\ProviderException;
use WP_AI_Mind\Settings\ProviderSettings;
use WP_AI_Mind\Tools\ToolRegistry;
use WP_AI_Mind\Tools\ToolExecutor;
use WP_AI_Mind\Voice\VoiceInjector;

/**
 * REST controller for chat conversations, providers, and post search.
 *
 * Routes:
 *   GET  /wp-ai-mind/v1/conversations               — list conversations
 *   POST /wp-ai-mind/v1/conversations               — create conversation
 *   GET  /wp-ai-mind/v1/conversations/{id}/messages — get messages
 *   POST /wp-ai-mind/v1/conversations/{id}/messages — send message (AI turn)
 *   DELETE /wp-ai-mind/v1/conversations/{id}        — delete conversation
 *   GET  /wp-ai-mind/v1/providers                   — list available providers
 *   GET  /wp-ai-mind/v1/search-posts                — search posts
 *
 * All routes require the edit_posts capability except delete, which also
 * allows manage_options to delete any conversation.
 */
class ChatRestController {

	private const NAMESPACE = 'wp-ai-mind/v1';

	/**
	 * Inject the tool registry and executor used during AI tool-call loops.
	 *
	 * @since 1.0.0
	 * @param ToolRegistry $tool_registry Registry of available tools.
	 * @param ToolExecutor $tool_executor Executor that dispatches tool calls.
	 */
	public function __construct(
		private readonly ToolRegistry $tool_registry,
		private readonly ToolExecutor $tool_executor,
	) {}

	/**
	 * Register all chat REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			'/conversations',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_conversations' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_conversation' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'title'   => [
							'type'    => 'string',
							'default' => '',
						],
						'post_id' => [
							'type'    => 'integer',
							'default' => 0,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)/messages',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_messages' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send_message' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'content'         => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'provider'        => [
							'type'    => 'string',
							'default' => '',
						],
						'model'           => [
							'type'    => 'string',
							'default' => '',
						],
						'context_post_id' => [
							'type'    => 'integer',
							'default' => 0,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_conversation' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/providers',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_providers' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/search-posts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_posts' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'q' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Return all conversations for the current user.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request (unused).
	 * @return \WP_REST_Response
	 */
	public function list_conversations( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_REST_Server callback signature.
		$store         = $this->make_store();
		$conversations = $store->list_for_user( get_current_user_id() );
		$response      = array_map(
			fn( $c ) => [
				'id'         => (int) $c['id'],
				'title'      => $c['title'],
				'updated_at' => $c['updated_at'],
			],
			$conversations
		);
		return rest_ensure_response( $response );
	}

	/**
	 * Create a new conversation for the current user.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response 201 on success; 500 if the row could not be inserted.
	 */
	public function create_conversation( \WP_REST_Request $request ): \WP_REST_Response {
		$store         = $this->make_store();
		$post_id_param = $request->get_param( 'post_id' );
		$id            = $store->create(
			$request->get_param( 'title' ),
			! empty( $post_id_param ) ? (int) $post_id_param : null
		);
		if ( 0 === $id ) {
			return new \WP_REST_Response( [ 'message' => __( 'Failed to create conversation.', 'wp-ai-mind' ) ], 500 );
		}
		$conversation = $store->get_conversation( $id );
		if ( null === $conversation ) {
			return new \WP_REST_Response( [ 'message' => __( 'Failed to retrieve conversation.', 'wp-ai-mind' ) ], 500 );
		}
		return new \WP_REST_Response(
			[
				'id'         => (int) $conversation['id'],
				'title'      => $conversation['title'],
				'updated_at' => $conversation['updated_at'],
			],
			201
		);
	}

	/**
	 * Return all messages for the given conversation.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request; must contain 'id' route parameter.
	 * @return \WP_REST_Response
	 */
	public function get_messages( \WP_REST_Request $request ): \WP_REST_Response {
		$store = $this->make_store();
		return rest_ensure_response( $store->get_messages( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Append a user message and run an AI completion turn, including tool-call loops.
	 *
	 * Handles multi-step tool-call agentic loops up to 5 iterations.
	 * Provider 401/403 errors are mapped to 502 so the client cannot distinguish
	 * a plugin auth failure from a provider auth failure.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response 201 on success; 403 if the conversation is not owned by the caller;
	 *                           429 with a `Retry-After` header (seconds until next month UTC) on
	 *                           provider rate-limit; 502 when the provider returns 401/403; 500 on
	 *                           other provider errors or iteration-limit breach.
	 */
	public function send_message( \WP_REST_Request $request ): \WP_REST_Response {
		$conv_id        = (int) $request->get_param( 'id' );
		$content        = $request->get_param( 'content' );
		$provider_param = $request->get_param( 'provider' );
		$provider_slug  = ! empty( $provider_param ) ? $provider_param : \get_option( 'wp_ai_mind_default_provider', 'claude' );
		$model          = $request->get_param( 'model' );

		$store = $this->make_store();

		// Ownership guard.
		$conv = $store->get_conversation( $conv_id );
		if ( ! $conv || \get_current_user_id() !== (int) $conv['user_id'] ) {
			return new \WP_REST_Response( [ 'message' => 'Forbidden.' ], 403 );
		}

		$store->add_message( $conv_id, 'user', $content );
		$history = $store->get_messages( $conv_id );

		$messages = array_map(
			fn( $m ) => [
				'role'    => $m['role'],
				'content' => $m['content'],
			],
			$history
		);

		$injector = $this->make_voice_injector();
		$system   = $injector->build_system_prompt( '', \get_current_user_id() );

		$context_post_id = absint( $request->get_param( 'context_post_id' ) );
		if ( $context_post_id > 0 ) {
			$context_post = get_post( $context_post_id );
			if ( $context_post instanceof \WP_Post && \current_user_can( 'read_post', $context_post_id ) ) {
				$system .= "\n\nCurrent context: You are working on a WordPress post titled '"
					. esc_attr( $context_post->post_title )
					. "' (ID: {$context_post_id}). Use the get_post_content tool with ID {$context_post_id} to read its full content when needed.";
			}
		}

		try {
			$factory  = $this->make_provider_factory();
			$provider = $factory->make( $provider_slug );

			if ( ! $provider->is_available() ) {
				return new \WP_REST_Response(
					[
						'message' => sprintf(
												/* translators: %s: provider slug */
							__( 'No API key configured for "%s". Please add one in WP AI Mind → Settings.', 'wp-ai-mind' ),
							$provider_slug
						),
					],
					422
				);
			}

			$tools = $provider->supports_tools()
				? $this->tool_registry->get_for_provider( $provider_slug )
				: [];

			$max_iterations = 5;
			$iteration      = 0;
			$final_response = null;

			while ( $iteration < $max_iterations ) {
				++$iteration;

				$req = new CompletionRequest(
					messages:    $messages,
					system:      $system,
					model:       $model,
					metadata:    [
						'feature' => 'chat',
						'post_id' => null,
					],
					tools:       $tools,
				);

				$response = $provider->complete( $req );

				if ( \is_wp_error( $response ) ) {
					return new \WP_REST_Response(
						[ 'message' => $response->get_error_message() ],
						502
					);
				}

				if ( ! $response->is_tool_call() ) {
					$final_response = $response;
					break;
				}

				// Collect ALL tool_use blocks from the raw response (Claude may request multiple in one turn).
				$all_tool_uses = [];
				foreach ( $response->raw['content'] ?? [] as $block ) {
					if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
						$all_tool_uses[] = $block;
					}
				}

				// Fall back to the single tool_call extracted by the provider if raw parsing found nothing.
				if ( empty( $all_tool_uses ) ) {
					$tc              = $response->tool_call;
					$all_tool_uses[] = [
						'id'    => $tc['id'],
						'name'  => $tc['name'],
						'input' => $tc['arguments'],
					];
				}

				// Execute every tool and collect results keyed by tool_use id.
				$tool_results = [];
				foreach ( $all_tool_uses as $tu ) {
					$arguments                 = $tu['input'] ?? [];
					$tool_results[ $tu['id'] ] = $this->tool_executor->execute(
						$tu['name'],
						$arguments,
						\get_current_user_id()
					);
				}

				$messages = $this->append_tool_exchange( $messages, $provider_slug, $response, $tool_results );
			}

			if ( null === $final_response ) {
				return new \WP_REST_Response(
					[ 'message' => 'Tool call limit reached without a final response.' ],
					500
				);
			}

			$store->add_message( $conv_id, 'assistant', $final_response->content, $final_response->model, $final_response->total_tokens );

			return rest_ensure_response(
				[
					'content'  => $final_response->content,
					'model'    => $final_response->model,
					'tokens'   => $final_response->total_tokens,
					'cost_usd' => $final_response->cost_usd,
				]
			);
		} catch ( ProviderException $e ) {
			$provider_status = $e->get_http_status();
			// Never forward a provider 401/403 as-is — it would look like a WP auth failure to the client.
			// Map those (and anything outside 4xx/5xx) to 502.
			if ( in_array( $provider_status, [ 401, 403 ], true ) || $provider_status < 400 || $provider_status >= 600 ) {
				$status = 502;
			} else {
				$status = $provider_status;
			}
			$response = new \WP_REST_Response( [ 'message' => $e->getMessage() ], $status );
			if ( 429 === $status ) {
				$next_month = new \DateTimeImmutable( 'first day of next month midnight UTC' );
				$response->header( 'Retry-After', (string) max( 0, $next_month->getTimestamp() - time() ) );
			}
			return $response;
		}
	}

	/**
	 * Delete a conversation owned by the current user (or any conversation for admins).
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request; must contain 'id' route parameter.
	 * @return \WP_REST_Response|\WP_Error 200 on success; 404 if not found; 403 if forbidden.
	 */
	public function delete_conversation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store   = $this->make_store();
		$conv_id = (int) $request->get_param( 'id' );
		$conv    = $store->get_conversation( $conv_id );

		if ( ! $conv ) {
			return new \WP_Error( 'not_found', __( 'Not found.', 'wp-ai-mind' ), [ 'status' => 404 ] );
		}
		if ( get_current_user_id() !== (int) $conv['user_id'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot delete this conversation.', 'wp-ai-mind' ), [ 'status' => 403 ] );
		}

		$store->delete( $conv_id );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * Return all configured providers with their models and availability status.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request (unused).
	 * @return \WP_REST_Response
	 */
	public function list_providers( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_REST_Server callback signature.
		$factory = $this->make_provider_factory();
		$all     = $factory->get_all();
		$data    = [];
		foreach ( $all as $provider ) {
			$data[] = [
				'slug'         => $provider->get_slug(),
				'models'       => $provider->get_models(),
				'is_available' => $provider->is_available(),
			];
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Search published posts and pages by keyword.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request; accepts 'q' parameter.
	 * @return \WP_REST_Response
	 */
	public function search_posts( \WP_REST_Request $request ): \WP_REST_Response {
		$q            = trim( (string) $request->get_param( 'q' ) );
		$post_types   = array_values(
			array_filter(
				get_post_types( [ 'public' => true ], 'names' ),
				fn( $pt ) => 'attachment' !== $pt
			)
		);
		$post_types[] = 'attachment';

		$query = new \WP_Query(
			[
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				's'              => $q,
				'posts_per_page' => 10,
				'orderby'        => 'relevance',
			]
		);
		$data  = [];
		foreach ( $query->posts as $post ) {
			$type_obj   = get_post_type_object( $post->post_type );
			$type_label = $type_obj ? $type_obj->labels->singular_name : $post->post_type;
			$data[]     = [
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'type'       => $post->post_type,
				'type_label' => $type_label,
			];
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Check that the current user has the edit_posts capability.
	 *
	 * @since 1.0.0
	 * @return bool|\WP_Error
	 */
	public function check_permission(): bool|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'wp-ai-mind' ), [ 'status' => 403 ] );
		}
		return true;
	}

	// ── Overridable factory methods (for testing) ─────────────────────────────

	/**
	 * Factory method for ConversationStore — overridable in tests.
	 *
	 * @since 1.0.0
	 * @return ConversationStore
	 */
	protected function make_store(): ConversationStore {
		return new ConversationStore();
	}

	/**
	 * Factory method for ProviderFactory — overridable in tests.
	 *
	 * @since 1.0.0
	 * @return ProviderFactory
	 */
	protected function make_provider_factory(): ProviderFactory {
		return new ProviderFactory( new ProviderSettings() );
	}

	/**
	 * Factory method for VoiceInjector — overridable in tests.
	 *
	 * @since 1.0.0
	 * @return VoiceInjector
	 */
	protected function make_voice_injector(): VoiceInjector {
		return new VoiceInjector();
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Append a complete tool exchange (assistant tool call + user tool result) to the message history.
	 *
	 * @param array              $messages      Existing message history.
	 * @param string             $provider_slug Provider identifier.
	 * @param CompletionResponse $tool_response The provider response that triggered tool execution.
	 * @param array              $tool_results  Tool results keyed by tool_use/call id.
	 * @return array Updated message history.
	 */
	private function append_tool_exchange(
		array $messages,
		string $provider_slug,
		CompletionResponse $tool_response,
		array $tool_results
	): array {
		$tool_call = $tool_response->tool_call;

		// Convenience: result for the primary (first) tool call.
		$fallback_result     = reset( $tool_results );
		$primary_result      = $tool_results[ $tool_call['id'] ] ?? ( ! empty( $fallback_result ) ? $fallback_result : [] );
		$primary_result_json = \wp_json_encode( $primary_result );

		switch ( $provider_slug ) {
			case 'claude':
				$raw_content = $tool_response->raw['content'] ?? [];
				// PHP json_decode converts {} to [] for empty objects.
				// Claude requires tool_use.input to be a JSON object (dictionary), not an array.
				foreach ( $raw_content as &$block ) {
					if ( ( $block['type'] ?? '' ) === 'tool_use' && ( $block['input'] ?? null ) === [] ) {
						$block['input'] = new \stdClass();
					}
				}
				unset( $block );
				$messages[] = [
					'role'    => 'assistant',
					'content' => $raw_content,
				];
				// Build one tool_result entry for every tool_use block in the assistant turn.
				$result_blocks = [];
				foreach ( $raw_content as $block ) {
					if ( ( $block['type'] ?? '' ) !== 'tool_use' ) {
						continue;
					}
					$tu_id           = $block['id'];
					$tu_result       = $tool_results[ $tu_id ] ?? [];
					$result_blocks[] = [
						'type'        => 'tool_result',
						'tool_use_id' => $tu_id,
						'content'     => \wp_json_encode( $tu_result ),
					];
				}
				// Fall back to the primary result if raw content had no tool_use blocks.
				if ( empty( $result_blocks ) ) {
					$result_blocks[] = [
						'type'        => 'tool_result',
						'tool_use_id' => $tool_call['id'],
						'content'     => $primary_result_json,
					];
				}
				$messages[] = [
					'role'    => 'user',
					'content' => $result_blocks,
				];
				break;

			case 'openai':
			case 'grok':
				$messages[] = [
					'role'       => 'assistant',
					'tool_calls' => [
						[
							'id'       => $tool_call['id'],
							'type'     => 'function',
							'function' => [
								'name'      => $tool_call['name'],
								'arguments' => \wp_json_encode( $tool_call['arguments'] ),
							],
						],
					],
				];
				$messages[] = [
					'role'         => 'tool',
					'tool_call_id' => $tool_call['id'],
					'content'      => $primary_result_json,
				];
				break;

			case 'gemini':
				$call_id    = $tool_response->raw['call_id'] ?? $tool_call['id'];
				$messages[] = [
					'role'  => 'model',
					'parts' => [
						[
							'functionCall' => [
								'id'   => $call_id,
								'name' => $tool_call['name'],
								'args' => $tool_call['arguments'],
							],
						],
					],
				];
				$messages[] = [
					'role'  => 'user',
					'parts' => [
						[
							'functionResponse' => [
								'id'       => $call_id,
								'name'     => $tool_call['name'],
								'response' => $primary_result,
							],
						],
					],
				];
				break;

			default:
				$messages[] = [
					'role'    => 'user',
					'content' => 'Tool result: ' . $primary_result_json,
				];
		}

		return $messages;
	}
}
