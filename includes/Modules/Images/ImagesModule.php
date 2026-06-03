<?php
/**
 * Images module — REST routes and asset enqueuing for AI image generation.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Modules\Images;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Providers\ProviderFactory;
use Stilus\Providers\ProviderException;
use Stilus\Settings\ProviderSettings;
use Stilus\Tiers\TierManager;
use Stilus\Tiers\UsageTracker;

/**
 * Registers the image-generation admin assets and REST route.
 *
 * Multiple images are generated in a loop; partial failures are reported
 * via a 207 Multi-Status response rather than aborting the entire request.
 */
class ImagesModule {

	/**
	 * Register WordPress hooks for this module.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		\add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Enqueue image-module assets on the images admin page only.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix (unused; page detection uses $_GET).
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by admin_enqueue_scripts hook signature.
		// Only load on the images admin page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, never output.
		if ( sanitize_key( \wp_unslash( $_GET['page'] ?? '' ) ) !== 'stilus-images' ) {
			return;
		}

		$asset_file = STILUS_DIR . 'assets/images/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => STILUS_VERSION,
			];

		\wp_enqueue_script(
			'stilus-images',
			STILUS_URL . 'assets/images/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'stilus-images',
			'wpAiMindData',
			[
				'nonce'    => \wp_create_nonce( 'wp_rest' ),
				'restUrl'  => \esc_url_raw( \rest_url( 'stilus/v1' ) ),
				'isPro'    => TierManager::user_can( 'images' ),
				'adminUrl' => \esc_url_raw( \admin_url() ),
			]
		);

		\wp_enqueue_style(
			'stilus-images',
			STILUS_URL . 'assets/images/index.css',
			[],
			$asset['version']
		);
	}

	/**
	 * Register the /stilus/v1/images/generate REST route.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		\register_rest_route(
			'stilus/v1',
			'/images/generate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_generate' ],
				'permission_callback' => function () {
						$user_id = \get_current_user_id();
						return \current_user_can( 'edit_posts' ) && TierManager::user_can( 'images', $user_id ) && UsageTracker::check_limit( $user_id );
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

	/**
	 * Generate one or more images and save them to the WordPress media library.
	 *
	 * Returns 201 when all images succeed, 207 when at least one fails but at
	 * least one succeeds, and 500 when all fail.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response
	 */
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
					'error'   => __( 'All image generation requests failed.', 'stilus' ),
					'details' => $errors,
				],
				500
			);
		}

		UsageTracker::log_usage( count( $images ) );
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
