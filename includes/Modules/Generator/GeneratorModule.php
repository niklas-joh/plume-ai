<?php
// includes/Modules/Generator/GeneratorModule.php
declare( strict_types=1 );
namespace WP_AI_Mind\Modules\Generator;

use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

class GeneratorModule {

	public static function register(): void {
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		\add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function enqueue_assets( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by admin_enqueue_scripts hook signature.
		// Only load on the generator admin page
		if ( ! isset( $_GET['page'] ) || 'wp-ai-mind-generator' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$asset_file = WP_AI_MIND_DIR . 'assets/generator/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		\wp_enqueue_script(
			'wp-ai-mind-generator',
			WP_AI_MIND_URL . 'assets/generator/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'wp-ai-mind-generator',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
				'currentPostId' => 0,
				'isPro'         => NJ_Tier_Manager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);
	}

	public static function register_routes(): void {
		\register_rest_route(
			'wp-ai-mind/v1',
			'/generate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_generate' ],
				'permission_callback' => function () {
						$user_id = \get_current_user_id();
						return \current_user_can( 'edit_posts' ) && NJ_Tier_Manager::user_can( 'generator', $user_id ) && NJ_Usage_Tracker::check_limit( $user_id );
				},
				'args'                => [
					'title'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'keywords' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
					'tone'     => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'professional',
					],
					'length'   => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'medium',
						'enum'              => [ 'short', 'medium', 'long' ],
					],
				],
			]
		);
	}

	public static function handle_generate( \WP_REST_Request $request ): \WP_REST_Response {
		$title    = $request->get_param( 'title' );
		$keywords = $request->get_param( 'keywords' );
		$tone     = $request->get_param( 'tone' );
		$length   = $request->get_param( 'length' );

		$length_map = [
			'short'  => '300–500',
			'medium' => '600–900',
			'long'   => '1200–1800',
		];
		$word_count = $length_map[ $length ] ?? '600–900';

		$prompt = "Write a complete blog post with the following specifications:\n\n"
			. "Title: {$title}\n"
			. ( $keywords ? "Keywords to include: {$keywords}\n" : '' )
			. "Tone: {$tone}\n"
			. "Length: approximately {$word_count} words\n\n"
			. 'Format the post with proper headings (H2, H3) and paragraphs. '
			. 'Return only the post body — no title, no preamble.';

		try {
			$factory  = new \WP_AI_Mind\Providers\ProviderFactory();
			$provider = $factory->make_default();
			$voice    = new \WP_AI_Mind\Voice\VoiceInjector();

			$req = new \WP_AI_Mind\Providers\CompletionRequest(
				messages:    [
					[
						'role'    => 'user',
						'content' => $prompt,
					],
				],
				system:      $voice->build_system_prompt( 'Post generation', \get_current_user_id() ),
				model:       $provider->get_models()[0]['id'] ?? '',
				temperature: 0.7,
				max_tokens:  2000,
				metadata:    [
					'feature'    => 'generator',
					'post_title' => $title,
				]
			);

			$response = $provider->complete( $req );
			NJ_Usage_Tracker::log_usage( $response->total_tokens );
			$content = $response->content;

			// Create a draft post
			$post_id = \wp_insert_post(
				[
					'post_title'   => \sanitize_text_field( $title ),
					'post_content' => \wp_kses_post( $content ),
					'post_status'  => 'draft',
					'post_author'  => \get_current_user_id(),
					'post_type'    => 'post',
				],
				true
			);

			if ( \is_wp_error( $post_id ) ) {
				return new \WP_REST_Response( [ 'error' => $post_id->get_error_message() ], 500 );
			}

			return new \WP_REST_Response(
				[
					'post_id'     => $post_id,
					'edit_url'    => \get_edit_post_link( $post_id, 'raw' ),
					'content'     => $content,
					'tokens_used' => $response->total_tokens,
				],
				201
			);

		} catch ( \Exception $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
		}
	}
}
