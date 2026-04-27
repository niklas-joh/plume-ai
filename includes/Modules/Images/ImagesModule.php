<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Modules\Images;

use WP_AI_Mind\Providers\ProviderFactory;
use WP_AI_Mind\Providers\ProviderException;
use WP_AI_Mind\Settings\ProviderSettings;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

class ImagesModule {

	public static function register(): void {
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		\add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function enqueue_assets( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by admin_enqueue_scripts hook signature.
		// Only load on the images admin page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, never output.
		if ( sanitize_key( \wp_unslash( $_GET['page'] ?? '' ) ) !== 'wp-ai-mind-images' ) {
			return;
		}

		$asset_file = WP_AI_MIND_DIR . 'assets/images/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		\wp_enqueue_script(
			'wp-ai-mind-images',
			WP_AI_MIND_URL . 'assets/images/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'wp-ai-mind-images',
			'wpAiMindData',
			[
				'nonce'    => \wp_create_nonce( 'wp_rest' ),
				'restUrl'  => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
				'isPro'    => NJ_Tier_Manager::user_can( 'images' ),
				'adminUrl' => \esc_url_raw( \admin_url() ),
			]
		);

		\wp_enqueue_style(
			'wp-ai-mind-images',
			WP_AI_MIND_URL . 'assets/images/index.css',
			[],
			$asset['version']
		);
	}

	public static function register_routes(): void {
		\register_rest_route(
			'wp-ai-mind/v1',
			'/images/generate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_generate' ],
				'permission_callback' => function () {
						$user_id = \get_current_user_id();
						return \current_user_can( 'edit_posts' ) && NJ_Tier_Manager::user_can( 'images', $user_id ) && NJ_Usage_Tracker::check_limit( $user_id );
				},
				'args'                => [
					'prompt'       => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'aspect_ratio' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '1:1',
						'enum'              => [ '1:1', '16:9', '4:3', '9:16' ],
					],
					'count'        => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 3,
					],
				],
			]
		);
	}

	public static function handle_generate( \WP_REST_Request $request ): \WP_REST_Response {
		$prompt       = $request->get_param( 'prompt' );
		$aspect_ratio = $request->get_param( 'aspect_ratio' );
		$count        = min( (int) $request->get_param( 'count' ), 3 );

		$size_map = [
			'1:1'  => '1024x1024',
			'16:9' => '1792x1024',
			'4:3'  => '1024x768',
			'9:16' => '1024x1792',
		];
		$options  = [
			'aspect_ratio' => $aspect_ratio,
			'size'         => $size_map[ $aspect_ratio ] ?? '1024x1024',
		];

		$factory  = new ProviderFactory( new ProviderSettings() );
		$provider = $factory->make_image_provider();

		$images = [];
		$errors = [];

		for ( $i = 0; $i < $count; $i++ ) {
			try {
				$attachment_id = $provider->generate_image( $prompt, $options );
				$full          = \wp_get_attachment_image_src( $attachment_id, 'full' );
				$thumb         = \wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
				$images[]      = [
					'attachment_id' => $attachment_id,
					'url'           => $full ? \esc_url_raw( $full[0] ) : '',
					'thumbnail_url' => $thumb ? \esc_url_raw( $thumb[0] ) : '',
					'prompt'        => $prompt,
				];
			} catch ( ProviderException $e ) {
				$errors[] = $e->getMessage();
			} catch ( \Exception $e ) {
				$errors[] = $e->getMessage();
			}
		}

		if ( empty( $images ) ) {
			return new \WP_REST_Response(
				[
					'error'   => __( 'All image generation requests failed.', 'wp-ai-mind' ),
					'details' => $errors,
				],
				500
			);
		}

		NJ_Usage_Tracker::log_usage( count( $images ) );
		$status = empty( $errors ) ? 201 : 207;

		return new \WP_REST_Response(
			[
				'images'   => $images,
				'errors'   => $errors,
				'provider' => $provider->get_slug(),
				'count'    => count( $images ),
			],
			$status
		);
	}
}
