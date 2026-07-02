<?php
/**
 * REST controller handling conversation and message endpoints for the chat feature.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Modules\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Core\RestApi;
use Plume\DB\ConversationStore;
use Plume\Providers\ProviderFactory;
use Plume\Providers\CompletionRequest;
use Plume\Providers\CompletionResponse;
use Plume\Providers\ProviderException;
use Plume\Settings\ProviderSettings;
use Plume\Tools\ToolRegistry;
use Plume\Tools\ToolExecutor;
use Plume\Voice\VoiceInjector;
use Plume\Proxy\SiteRegistration;
use Plume\Tiers\TierManager;
use Plume\Tiers\UsageTracker;

/**
 * REST controller for chat conversations, providers, and post search.
 *
 * Routes:
 *   GET    /plume/v1/conversations               — list conversations
 *   POST   /plume/v1/conversations               — create conversation
 *   GET    /plume/v1/conversations/{id}/messages — get messages
 *   POST   /plume/v1/conversations/{id}/messages — send message (AI turn)
 *   PATCH  /plume/v1/conversations/{id}          — update conversation title
 *   DELETE /plume/v1/conversations/{id}          — delete conversation
 *   GET    /plume/v1/providers                   — list available providers
 *   GET    /plume/v1/search-posts                — search posts
 *
 * All routes require the edit_posts capability except delete, which also
 * allows manage_options to delete any conversation.
 */
class ChatRestController {

	private const MAX_TOOL_ITERATIONS = 5;

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
			RestApi::API_NAMESPACE,
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
			RestApi::API_NAMESPACE,
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
			RestApi::API_NAMESPACE,
			'/conversations/(?P<id>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_conversation' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'title' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'maxLength'         => 100,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_conversation' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			RestApi::API_NAMESPACE,
			'/providers',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_providers' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			RestApi::API_NAMESPACE,
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
			return new \WP_REST_Response( [ 'message' => __( 'Failed to create conversation.', 'plume' ) ], 500 );
		}
		$conversation = $store->get_conversation( $id );
		if ( null === $conversation ) {
			return new \WP_REST_Response( [ 'message' => __( 'Failed to retrieve conversation.', 'plume' ) ], 500 );
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
		$provider_slug  = ! empty( $provider_param ) ? $provider_param : \get_option( 'plume_default_provider', 'claude' );
		$model          = $request->get_param( 'model' );

		$user_id = \get_current_user_id();
		$store   = $this->make_store();

		// Ownership guard.
		$conv = $store->get_conversation( $conv_id );
		if ( ! $conv || $user_id !== (int) $conv['user_id'] ) {
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
		$system   = $injector->build_system_prompt(
			'Tool rule: when the user wants to edit or update a post, call plan_update directly after reading the post — never use chat_response to share your analysis or ask for permission first. Your analysis goes in the plan_update analysis field.',
			$user_id
		);

		$context_post_id = absint( $request->get_param( 'context_post_id' ) );
		if ( $context_post_id > 0 ) {
			$context_post = get_post( $context_post_id );
			if ( $context_post instanceof \WP_Post && \current_user_can( 'read_post', $context_post_id ) ) {
				$system .= "\n\nCurrent context: You are working on a WordPress post titled '"
					. esc_attr( $context_post->post_title )
					. "' (ID: {$context_post_id}). You MUST call get_post_content with post_id={$context_post_id} to retrieve the full content before answering any question about this post's body, text, or details beyond its title.";
			}
		}

		try {
			$factory  = $this->make_provider_factory();
			$provider = $factory->make( $provider_slug );

			if ( ! $provider->is_available() ) {
				$is_proxy_tier = ! TierManager::user_can( 'own_api_key' );

				if ( $is_proxy_tier ) {
					// Site token absent — schedule re-registration so the next page load succeeds.
					// Guard against double-scheduling: add_action does not deduplicate identical callbacks on the same hook.
					if ( ! has_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] ) ) {
						add_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] );
					}
					return new \WP_REST_Response(
						[
							'message' => __( 'Could not connect to Plume — Write and Design. Please reload the page and try again.', 'plume' ),
						],
						503
					);
				}

				return new \WP_REST_Response(
					[
						'message' => sprintf(
							/* translators: %s: provider slug */
							__( 'No API key configured for "%s". Please add one in Plume → Settings.', 'plume' ),
							$provider_slug
						),
					],
					422
				);
			}

			$tools = $provider->supports_tools()
				? $this->tool_registry->get_for_provider( $provider_slug )
				: [];

			$max_iterations = self::MAX_TOOL_ITERATIONS;
			$iteration      = 0;
			$final_response = null;
			$pending_plan   = null;
			$tools_called   = [];

			while ( $iteration < $max_iterations ) {
				++$iteration;

				$req = new CompletionRequest(
					messages:       $messages,
					system:         $system,
					model:          $model,
					metadata:       [
						'feature' => 'chat',
						'post_id' => null,
					],
					tools:          $tools,
					force_tool_use: ! empty( $tools ),
					max_tokens:     8192,
				);

				$response = $provider->complete( $req );

				if ( \is_wp_error( $response ) ) {
					return new \WP_REST_Response(
						[ 'message' => $response->get_error_message() ],
						502
					);
				}

				// Bare text response — happens when tools are not supported by the provider.
				if ( ! $response->is_tool_call() ) {
					$final_response = $response;
					break;
				}

				// Collect ALL tool calls from the raw response (providers may request multiple in one turn).
				$all_tool_uses = $this->extract_tool_calls( $response, $provider_slug );

				// Detect chat_response tool — the model's exit signal.
				$chat_response_tu = null;
				foreach ( $all_tool_uses as $tu ) {
					if ( 'chat_response' === $tu['name'] ) {
						$chat_response_tu = $tu;
						break;
					}
				}

				// Execute all non-chat_response tools and collect results.
				$tool_results         = [];
				$pending_plan_message = '';
				foreach ( $all_tool_uses as $tu ) {
					if ( 'chat_response' === $tu['name'] ) {
						continue;
					}
					$tool_name                 = $tu['name'];
					$result                    = $this->tool_executor->execute( $tool_name, $tu['input'], $user_id );
					$tool_results[ $tu['id'] ] = $result;
					$tools_called[]            = $tool_name;

					if ( 'pending_approval' === ( $result['status'] ?? '' ) ) {
						$pending_plan         = $result;
						$pending_plan_message = \sanitize_textarea_field( $tu['input']['analysis'] ?? '' );
					}
				}

				// If the model included chat_response, use its message as the final text and exit.
				if ( null !== $chat_response_tu ) {
					$final_response = $response->with_text( $chat_response_tu['input']['message'] ?? '' );
					break;
				}

				// Safety net for models that omit `analysis` on plan_update/plan_post, or for
				// legacy tool-call fixtures/providers that don't send it.
				if ( null !== $pending_plan ) {
					$message        = '' !== $pending_plan_message
						? $pending_plan_message
						: __( "I've prepared the changes for your review.", 'plume' );
					$final_response = $response->with_text( $message );
					break;
				}

				$messages = $this->append_tool_exchange( $messages, $provider_slug, $response, $tool_results );
			}

			if ( null === $final_response ) {
				// Substitute a displayable message so the chat UI receives a 200 rather than crashing on 500.
				$limit_message  = \__( 'The assistant reached the maximum number of steps without finishing. Please try rephrasing your request or breaking it into smaller tasks.', 'plume' );
				$final_response = $response->with_text( $limit_message );
			}

			// Log the Worker's reported credit cost exactly once per user message, after all
			// tool-call iterations are complete. ProxyClient skips logging for 'chat' to
			// prevent per-iteration double-counting in the agentic loop.
			UsageTracker::log_usage( $final_response->credits_charged, $user_id );

			$store->add_message( $conv_id, 'assistant', $final_response->content, $final_response->model, $final_response->total_tokens );

			return rest_ensure_response(
				[
					'content'      => $final_response->content,
					'model'        => $final_response->model,
					'credits'      => $final_response->credits_charged,
					'cost_usd'     => $final_response->cost_usd,
					'pending_plan' => $pending_plan,
					'tools_called' => \array_values( \array_unique( $tools_called ) ),
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
	 * Update the title of a conversation owned by the current user.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request; must contain 'id' and 'title' parameters.
	 * @return \WP_REST_Response|\WP_Error 200 on success; 404 if not found; 403 if forbidden; 500 on DB failure.
	 */
	public function update_conversation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store   = $this->make_store();
		$conv_id = (int) $request->get_param( 'id' );
		$conv    = $store->get_conversation( $conv_id );

		if ( ! $conv ) {
			return new \WP_Error( 'not_found', __( 'Not found.', 'plume' ), [ 'status' => 404 ] );
		}
		if ( get_current_user_id() !== (int) $conv['user_id'] ) {
			return new \WP_Error( 'forbidden', __( 'You cannot update this conversation.', 'plume' ), [ 'status' => 403 ] );
		}

		// Sanitise explicitly: the route schema runs sanitize_callback in production,
		// but unit tests bypass the schema, so a second call here ensures correctness.
		$updated = $store->update_title( $conv_id, sanitize_text_field( $request->get_param( 'title' ) ) );
		if ( ! $updated ) {
			return new \WP_Error( 'db_error', __( 'Failed to update conversation.', 'plume' ), [ 'status' => 500 ] );
		}
		return rest_ensure_response( [ 'updated' => true ] );
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
			return new \WP_Error( 'not_found', __( 'Not found.', 'plume' ), [ 'status' => 404 ] );
		}
		if ( get_current_user_id() !== (int) $conv['user_id'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot delete this conversation.', 'plume' ), [ 'status' => 403 ] );
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

		$posts = $this->run_post_query(
			[
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				's'              => $q,
				'posts_per_page' => 10,
				'orderby'        => 'relevance',
			]
		);
		$data  = [];
		foreach ( $posts as $post ) {
			$type_obj   = get_post_type_object( $post->post_type );
			$type_label = $type_obj ? $type_obj->labels->singular_name : $post->post_type;
			$data[]     = [
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'type'       => $post->post_type,
				'type_label' => $type_label,
				'edit_link'  => get_edit_post_link( $post->ID, 'raw' ) ?? '',
			];
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Executes a WP_Query and returns the matching posts array.
	 *
	 * Extracted to allow unit tests to stub query results without instantiating
	 * WP_Query, which requires a full WordPress bootstrap.
	 *
	 * @since 1.10.0
	 * @param array $args WP_Query argument array.
	 * @return object[] Array of WP_Post objects (or compatible stubs in tests).
	 */
	protected function run_post_query( array $args ): array {
		return ( new \WP_Query( $args ) )->posts;
	}

	/**
	 * Checks that the current user may access chat REST endpoints.
	 *
	 * Tier and quota are no longer checked here — the Worker's credit ledger is
	 * the sole enforcement point now. This collapses to a single capability
	 * check, identical across every tier.
	 *
	 * @since 1.0.0
	 * @since NEXT_VERSION Removed the tier and quota checks (and the user_can_chat()/
	 *                      user_within_quota() helper methods entirely) as part of the
	 *                      credits-based redesign.
	 * @return bool|\WP_Error True on success; WP_Error with 403 status on failure.
	 */
	public function check_permission(): bool|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'plume' ),
				[ 'status' => 403 ]
			);
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
	 * Extract every tool call from a provider response in a normalised shape.
	 *
	 * Claude exposes tool_use blocks in raw['content']; Gemini exposes functionCall
	 * parts in raw['data']. Gemini frequently omits call ids, so the tool name is
	 * used as the result key — append_tool_exchange applies the same id-or-name
	 * rule when matching results back to functionResponse parts.
	 *
	 * @since 1.9.0
	 * @param CompletionResponse $response      Provider response flagged as a tool call.
	 * @param string             $provider_slug Provider identifier.
	 * @return array<int, array{id: string, name: string, input: array}> Normalised tool calls.
	 */
	private function extract_tool_calls( CompletionResponse $response, string $provider_slug ): array {
		$tool_uses = [];

		if ( 'gemini' === $provider_slug ) {
			$raw_parts = $response->raw['data']['candidates'][0]['content']['parts'] ?? [];
			foreach ( $raw_parts as $part ) {
				if ( ! isset( $part['functionCall'] ) ) {
					continue;
				}
				$fc          = $part['functionCall'];
				$tool_uses[] = [
					'id'    => $fc['id'] ?? $fc['name'],
					'name'  => $fc['name'],
					'input' => $fc['args'] ?? [],
				];
			}
		} else {
			// Proxy responses set raw['content'] to a string; only iterate when it's an array.
			$raw_content = $response->raw['content'] ?? [];
			foreach ( \is_array( $raw_content ) ? $raw_content : [] as $block ) {
				if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
					$tool_uses[] = [
						'id'    => $block['id'],
						'name'  => $block['name'],
						'input' => $block['input'] ?? [],
					];
				}
			}
		}

		// Fall back to the normalised proxy tool call(s) if raw parsing found nothing.
		// CompletionResponse::first_and_all_tool_calls_from_proxy() handles the tool_calls (plural) /
		// tool_call (singular) contract; older responses expose only the singular tool_call property.
		if ( empty( $tool_uses ) ) {
			[ , $all_tool_calls ] = CompletionResponse::first_and_all_tool_calls_from_proxy( $response->raw );
			if ( empty( $all_tool_calls ) && null !== $response->tool_call ) {
				$all_tool_calls = [ $response->tool_call ];
			}
			foreach ( $all_tool_calls as $tc ) {
				$tool_uses[] = [
					'id'    => $tc['id'] ?? '',
					'name'  => $tc['name'] ?? '',
					'input' => $tc['arguments'] ?? [],
				];
			}
		}

		return $tool_uses;
	}

	/**
	 * Append a complete tool exchange (assistant tool call + user tool result) to the message history.
	 *
	 * @since 1.0.0
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

		// Full ordered set of executed tool calls — every one must be written back so the
		// model sees all results next turn and does not re-request calls 2..N (#898).
		$all_tool_calls = $this->extract_tool_calls( $tool_response, $provider_slug );

		// Convenience: result for the primary (first) tool call.
		$first_result        = reset( $tool_results );
		$default_result      = $tool_results[ $tool_call['id'] ] ?? ( ! empty( $first_result ) ? $first_result : [] );
		$default_result_json = \wp_json_encode( $default_result );

		switch ( $provider_slug ) {
			case 'claude':
				$raw_content = $tool_response->raw['content'] ?? [];
				// PHP json_decode converts {} to [] for empty objects.
				// Claude requires tool_use.input to be a JSON object (dictionary), not an array.
				if ( is_array( $raw_content ) ) {
					foreach ( $raw_content as &$block ) {
						if ( ( $block['type'] ?? '' ) === 'tool_use' && ( $block['input'] ?? null ) === [] ) {
							$block['input'] = new \stdClass();
						}
					}
					unset( $block );
				}
				// Proxy normalises content to a flat string; reconstruct the tool_use block so Claude
				// receives a valid assistant turn when the next message contains tool_result blocks.
				$has_tool_use_block = is_array( $raw_content ) && ! empty(
					array_filter( $raw_content, fn( $b ) => ( $b['type'] ?? '' ) === 'tool_use' )
				);
				if ( ! $has_tool_use_block ) {
					// Rebuild one tool_use block per executed call so parallel calls 2..N survive (#898).
					$raw_content = [];
					foreach ( $all_tool_calls as $tc ) {
						$tool_input    = ! empty( $tc['input'] ) ? (object) $tc['input'] : new \stdClass();
						$raw_content[] = [
							'type'  => 'tool_use',
							'id'    => $tc['id'],
							'name'  => $tc['name'],
							'input' => $tool_input,
						];
					}
					// Fall back to the primary tool call if extraction yielded nothing.
					if ( empty( $raw_content ) ) {
						$tool_input  = ! empty( $tool_call['arguments'] ) ? (object) $tool_call['arguments'] : new \stdClass();
						$raw_content = [
							[
								'type'  => 'tool_use',
								'id'    => $tool_call['id'],
								'name'  => $tool_call['name'],
								'input' => $tool_input,
							],
						];
					}
				}
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
						'content'     => $default_result_json,
					];
				}
				$messages[] = [
					'role'    => 'user',
					'content' => $result_blocks,
				];
				break;

			case 'openai':
			case 'grok':
				// Emit one tool_calls[] entry per executed call so calls 2..N are not dropped (#898).
				$tool_calls_payload = [];
				foreach ( $all_tool_calls as $tc ) {
					$tool_calls_payload[] = [
						'id'       => $tc['id'],
						'type'     => 'function',
						'function' => [
							'name'      => $tc['name'],
							'arguments' => \wp_json_encode( $tc['input'] ),
						],
					];
				}
				// Fall back to the primary tool call if extraction yielded nothing.
				if ( empty( $tool_calls_payload ) ) {
					$tool_calls_payload[] = [
						'id'       => $tool_call['id'],
						'type'     => 'function',
						'function' => [
							'name'      => $tool_call['name'],
							'arguments' => \wp_json_encode( $tool_call['arguments'] ),
						],
					];
				}
				$messages[] = [
					'role'       => 'assistant',
					'tool_calls' => $tool_calls_payload,
				];
				// One tool result message per tool_call_id.
				foreach ( $tool_calls_payload as $tc_entry ) {
					$tc_id      = $tc_entry['id'];
					$tc_result  = $tool_results[ $tc_id ] ?? $default_result;
					$messages[] = [
						'role'         => 'tool',
						'tool_call_id' => $tc_id,
						'content'      => \wp_json_encode( $tc_result ),
					];
				}
				break;

			case 'gemini':
				// Collect all functionCall parts from the raw Gemini response.
				$raw_parts      = $tool_response->raw['data']['candidates'][0]['content']['parts'] ?? [];
				$function_calls = array_values( array_filter( $raw_parts, fn( $p ) => isset( $p['functionCall'] ) ) );
				// Fall back to the single normalised tool_call when raw parts are unavailable.
				if ( empty( $function_calls ) ) {
					$call_id        = $tool_response->raw['call_id'] ?? $tool_call['id'];
					$function_calls = [
						[
							'functionCall' => [
								'id'   => $call_id,
								'name' => $tool_call['name'],
								'args' => $tool_call['arguments'],
							],
						],
					];
				}
				$messages[]     = [
					'role'  => 'model',
					'parts' => $function_calls,
				];
				$response_parts = [];
				foreach ( $function_calls as $fc_part ) {
					$fc               = $fc_part['functionCall'];
					$fc_id            = $fc['id'] ?? $fc['name'];
					$result           = $tool_results[ $fc_id ] ?? [];
					$response_parts[] = [
						'functionResponse' => [
							'id'       => $fc_id,
							'name'     => $fc['name'],
							// json_encode turns [] into a JSON array; Gemini requires response to be an object.
							'response' => empty( $result ) ? new \stdClass() : $result,
						],
					];
				}
				$messages[] = [
					'role'  => 'user',
					'parts' => $response_parts,
				];
				break;

			default:
				$messages[] = [
					'role'    => 'user',
					'content' => 'Tool result: ' . $default_result_json,
				];
		}

		return $messages;
	}
}
