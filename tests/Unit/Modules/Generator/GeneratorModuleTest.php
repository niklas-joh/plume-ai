<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Generator;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Generator\GeneratorModule;
use PHPUnit\Framework\TestCase;

class GeneratorModuleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_hooks_admin_enqueue_scripts(): void {
        GeneratorModule::register();
        self::assertSame(
            10,
            has_action( 'admin_enqueue_scripts', [ GeneratorModule::class, 'enqueue_assets' ] )
        );
    }

    public function test_register_hooks_rest_api_init(): void {
        GeneratorModule::register();
        self::assertSame(
            10,
            has_action( 'rest_api_init', [ GeneratorModule::class, 'register_routes' ] )
        );
    }

    public function test_enqueue_assets_skips_wrong_page(): void {
        $_GET['page'] = 'some-other-page';

        Functions\expect( 'wp_enqueue_script' )->never();

        GeneratorModule::enqueue_assets( 'toplevel_page_other' );

        unset( $_GET['page'] );

        // Explicit assertion: the method must return without enqueueing anything.
        self::assertArrayNotHasKey( 'page', $_GET );
    }

    // ── handle_generate error envelope ────────────────────────────────────────

    /**
     * Build a /generate request with the parameters the wizard sends.
     *
     * @since NEXT_VERSION
     * @param string $length Length slug (short|medium|long).
     * @return \WP_REST_Request
     */
    private function make_request( string $length = 'medium' ): \WP_REST_Request {
        $request = new \WP_REST_Request( 'POST' );
        $request->set_param( 'title', 'Ten tips for better sleep' );
        $request->set_param( 'keywords', '' );
        $request->set_param( 'tone', 'professional' );
        $request->set_param( 'length', $length );
        return $request;
    }

    /**
     * Stub WordPress for a keyless, registered site whose managed-proxy call
     * answers with the given HTTP status and JSON body.
     *
     * @since NEXT_VERSION
     * @param int   $status HTTP status the proxy returns.
     * @param array $body   Decoded JSON body the proxy returns.
     * @return void
     */
    private function stub_managed_proxy( int $status, array $body ): void {
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = null ) {
                if ( 'plume_default_provider' === $key ) {
                    return 'claude';
                }
                if ( 'plume_provider_settings' === $key ) {
                    return [];
                }
                if ( 'plume_site_token' === $key ) {
                    return 'site-token';
                }
                return $default;
            }
        );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'unreachable' ) );
        Functions\when( 'wp_remote_post' )->justReturn(
            [
                'response' => [ 'code' => $status ],
                'body'     => json_encode( $body ),
            ]
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
        Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
    }

    /**
     * Credit exhaustion is reported as 429 with the 'rate_limit_exceeded' code in
     * the body: apiFetch rejects with the parsed body only, so the wizard needs
     * the code there to show the out-of-credits notice.
     *
     * @since NEXT_VERSION
     * @return void
     */
    public function test_handle_generate_reports_credit_exhaustion_with_code_and_message(): void {
        $this->stub_managed_proxy( 429, [ 'error' => 'Monthly limit reached' ] );

        $response = GeneratorModule::handle_generate( $this->make_request() );

        self::assertSame( 429, $response->get_status() );
        self::assertSame( 'rate_limit_exceeded', $response->data['code'] );
        self::assertSame( 'Monthly usage limit reached.', $response->data['message'] );
        self::assertSame( $response->data['message'], $response->data['error'], 'The legacy `error` key must be kept.' );
    }

    /**
     * Other provider failures keep their message and are reported as 502.
     *
     * @since NEXT_VERSION
     * @return void
     */
    public function test_handle_generate_reports_provider_failure_as_502(): void {
        $this->stub_managed_proxy( 500, [ 'error' => 'Upstream provider unavailable.' ] );

        $response = GeneratorModule::handle_generate( $this->make_request() );

        self::assertSame( 502, $response->get_status() );
        self::assertSame( 'provider_error', $response->data['code'] );
        self::assertSame( 'Upstream provider unavailable.', $response->data['message'] );
        self::assertSame( 'Upstream provider unavailable.', $response->data['error'] );
    }

    /**
     * The request asks for enough output tokens to finish a long post.
     *
     * A flat 2000-token cap cut "Long (1200–1800 words)" drafts off mid-sentence.
     *
     * @since NEXT_VERSION
     * @return void
     */
    public function test_handle_generate_requests_enough_tokens_for_a_long_post(): void {
        $this->stub_managed_proxy( 429, [ 'error' => 'Monthly limit reached' ] );

        $sent = null;
        Functions\when( 'wp_remote_post' )->alias(
            static function ( string $url, array $args ) use ( &$sent ) {
                $sent = json_decode( $args['body'], true );
                return [
                    'response' => [ 'code' => 429 ],
                    'body'     => '{}',
                ];
            }
        );

        GeneratorModule::handle_generate( $this->make_request( 'long' ) );

        self::assertIsArray( $sent );
        self::assertGreaterThanOrEqual( 4096, $sent['max_tokens'] );
    }
}
