<?php
// includes/Modules/Chat/ChatRestController.php
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

class ChatRestController {

	private const NAMESPACE = 'wp-ai-mind/v1';

	public function __construct(
		private readonly ToolRegistry $tool_registry,
		private readonly ToolExecutor $tool_executor,
	) {}

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

	public function list_conversations( \WP_REST_Request $request ): \WP_REST_Response {
		$store = $this->make_store();
		return rest_ensure_response( $store->list_for_user( get_current_user_id() ) );
	}

	public function create_conversation( \WP_REST_Request $request ): \WP_REST_Response {
		$store         = $this->make_store();
		$post_id_param = $request->get_param( 'post_id' );
		$id            = $store->create(
			$request->get_param( 'title' ),
			! empty( $post_id_param ) ? (int) $post_id_param : null
		);
		return rest_ensure_response( [ 'id' => $id ] );
	}

	public function get_messages( \WP_REST_Request $request ): \WP_REST_Response {
		$store = $this->make_store();
		return rest_ensure_response( $store->get_messages( (int) $request->get_param( 'id' ) ) );
	}

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
			return new \WP_REST_Response( [ 'message' => $e->getMessage() ], $status );
		}
	}

	public function delete_conversation( \WP_REST_Request $request ): \WP_REST_Response {
		$store   = $this->make_store();
		$conv_id = (int) $request->get_param( 'id' );
		$conv    = $store->get_conversation( $conv_id );

		if ( ! $conv ) {
			return new \WP_REST_Response( [ 'message' => 'Not found.' ], 404 );
		}
		if ( get_current_user_id() !== (int) $conv['user_id'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_REST_Response( [ 'message' => 'You cannot delete this conversation.' ], 403 );
		}

		$store->delete( $conv_id );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	public function list_providers( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function check_permission(): bool|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'wp-ai-mind' ), [ 'status' => 403 ] );
		}
		return true;
	}

	// ── Overridable factory methods (for testing) ─────────────────────────────

	protected function make_store(): ConversationStore {
		return new ConversationStore();
	}

	protected function make_provider_factory(): ProviderFactory {
		return new ProviderFactory( new ProviderSettings() );
	}

	protected function make_voice_injector(): VoiceInjector {
		return new VoiceInjector();
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Append a complete tool exchange (assistant tool call + user tool result) to the message history.
	 *
	 * @param array             $messages      Existing message history.
	 * @param string            $provider_slug Provider identifier.
	 * @param CompletionResponse $tool_response The provider response that triggered tool execution.
	 * @param array             $tool_results  Tool results keyed by tool_use/call id.
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
