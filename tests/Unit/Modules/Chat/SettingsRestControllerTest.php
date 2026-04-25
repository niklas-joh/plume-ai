<?php
// tests/Unit/Modules/Chat/SettingsRestControllerTest.php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Modules\Chat\SettingsRestController;
use PHPUnit\Framework\TestCase;

class SettingsRestControllerTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    // ── Route registration ────────────────────────────────────────────────────

    public function test_register_routes_adds_settings_route(): void {
        $registered = [];
        Functions\when( 'register_rest_route' )->alias(
            function( $ns, $route ) use ( &$registered ) {
                $registered[] = $ns . $route;
            }
        );
        Functions\when( 'get_option' )->justReturn( [] );

        $controller = new SettingsRestController();
        $controller->register_routes();

        $this->assertContains( 'wp-ai-mind/v1/settings', $registered );
    }

    // ── GET /settings — masked keys ───────────────────────────────────────────

    public function test_get_settings_returns_masked_keys_when_set(): void {
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_post_types' )->justReturn( [] );
        Functions\when( 'wp_ai_mind_is_pro' )->justReturn( false );
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            $map = [
                'wp_ai_mind_default_provider' => 'claude',
                'wp_ai_mind_image_provider'   => 'gemini',
                'wp_ai_mind_site_voice'        => 'friendly',
                'wp_ai_mind_modules'          => [ 'chat' => true, 'text_rewrite' => false, 'summaries' => false, 'seo' => false, 'images' => false, 'generator' => false, 'frontend_widget' => false, 'usage' => false ],
                'wp_ai_mind_ollama_url'        => 'http://localhost:11434',
            ];
            return $map[ $key ] ?? $default;
        } );

        // has_key() calls get_api_key() which calls get_option(OPTION_KEY) — already handled above.
        // We mock ProviderSettings directly by controlling get_option for the keys option.
        // The encrypted store is not present, so has_key returns false — we test the "set" path
        // by using a partial approach: instantiate real class but inject a fake ProviderSettings
        // via a subclass.

        // Simpler: test mask() logic indirectly through get_settings with a stubbed ProviderSettings.
        // We'll use a test-double subclass.
        $controller = new class extends SettingsRestController {
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $stub = new class extends \WP_AI_Mind\Settings\ProviderSettings {
                    public function __construct() {} // Skip get_option in constructor.
                    public function has_key( string $provider ): bool {
                        return in_array( $provider, [ 'claude', 'gemini' ], true );
                    }
                    public function get_api_key( string $provider ): string { return ''; }
                };
                return $stub;
            }
        };

        $request  = new \WP_REST_Request();
        $response = $controller->get_settings( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $data = $response->data;

        // Providers that "have a key" should be masked.
        $this->assertSame( '••••••', $data['api_keys']['claude'] );
        $this->assertSame( '••••••', $data['api_keys']['gemini'] );
        // Providers without a key should be empty string.
        $this->assertSame( '', $data['api_keys']['openai'] );
    }

    public function test_get_settings_returns_empty_string_when_key_not_set(): void {
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_post_types' )->justReturn( [] );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'wp_ai_mind_is_pro' )->justReturn( false );

        $controller = new class extends SettingsRestController {
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $stub = new class extends \WP_AI_Mind\Settings\ProviderSettings {
                    public function __construct() {}
                    public function has_key( string $provider ): bool { return false; }
                    public function get_api_key( string $provider ): string { return ''; }
                };
                return $stub;
            }
        };

        $request  = new \WP_REST_Request();
        $response = $controller->get_settings( $request );
        $data     = $response->data;

        $this->assertSame( '', $data['api_keys']['claude'] );
        $this->assertSame( '', $data['api_keys']['openai'] );
        $this->assertSame( '', $data['api_keys']['gemini'] );
    }

    // ── GET /settings — is_pro field ─────────────────────────────────────────

    private function make_controller_with_is_pro( bool $is_pro ): SettingsRestController {
        $controller = new class( $is_pro ) extends SettingsRestController {
            private bool $is_pro;
            public function __construct( bool $is_pro ) { $this->is_pro = $is_pro; }
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $stub = new class extends \WP_AI_Mind\Settings\ProviderSettings {
                    public function __construct() {}
                    public function has_key( string $provider ): bool { return false; }
                    public function get_api_key( string $provider ): string { return ''; }
                };
                return $stub;
            }
        };

        // Wrap the controller so wp_ai_mind_is_pro() is available as a mocked function.
        Functions\when( 'wp_ai_mind_is_pro' )->justReturn( $is_pro );

        return $controller;
    }

    public function test_get_settings_is_pro_field_is_present(): void {
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'get_post_types' )->justReturn( [] );

        $controller = $this->make_controller_with_is_pro( false );

        $response = $controller->get_settings( new \WP_REST_Request() );
        $this->assertArrayHasKey( 'is_pro', $response->data );
    }

    public function test_get_settings_is_pro_is_always_boolean(): void {
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'get_post_types' )->justReturn( [] );

        foreach ( [ false, true ] as $value ) {
            $controller = $this->make_controller_with_is_pro( $value );
            $response   = $controller->get_settings( new \WP_REST_Request() );
            $this->assertIsBool( $response->data['is_pro'], 'is_pro must be a bool, got ' . gettype( $response->data['is_pro'] ) );
        }
    }

    public function test_get_settings_is_pro_reflects_wp_ai_mind_is_pro(): void {
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'get_post_types' )->justReturn( [] );

        $controller_free = $this->make_controller_with_is_pro( false );
        $response_free   = $controller_free->get_settings( new \WP_REST_Request() );
        $this->assertFalse( $response_free->data['is_pro'] );

        Functions\when( 'wp_ai_mind_is_pro' )->justReturn( true );
        $response_pro = $controller_free->get_settings( new \WP_REST_Request() );
        $this->assertTrue( $response_pro->data['is_pro'] );
    }

    // ── POST /settings — saves options ────────────────────────────────────────

    public function test_save_settings_updates_options(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
            return true;
        } );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'esc_url_raw' )->alias( fn( $v ) => $v );
        // ModuleRegistry::__construct() calls get_option to load saved state.
        Functions\when( 'get_option' )->justReturn( [] );

        $api_key_calls = [];
        $controller = new class( $api_key_calls ) extends SettingsRestController {
            private array $calls;
            public function __construct( array &$calls ) { $this->calls = &$calls; }
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $calls = &$this->calls;
                $stub  = new class( $calls ) extends \WP_AI_Mind\Settings\ProviderSettings {
                    private array $calls;
                    public function __construct( array &$calls ) {
                        $this->calls = &$calls;
                    }
                    public function set_api_key( string $provider, string $key ): void {
                        $this->calls[] = [ $provider, $key ];
                    }
                };
                return $stub;
            }
        };

        $request = new \WP_REST_Request();
        $request->set_body_params( [
            'default_provider' => 'claude',
            'image_provider'   => 'gemini',
            'site_voice'       => 'professional',
            'enabled_modules'  => [ 'chat', 'summaries' ],
            'api_keys'         => [
                'claude'     => 'sk-new-key',
                'openai'     => '••••••',   // masked — must be skipped
                'gemini'     => 'new-gem-key',
                'ollama_url' => 'http://localhost:11434',
            ],
        ] );

        $response = $controller->save_settings( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertTrue( $response->data['saved'] );

        // Options updated.
        $this->assertSame( 'claude',                     $stored['wp_ai_mind_default_provider'] );
        $this->assertSame( 'gemini',                     $stored['wp_ai_mind_image_provider'] );
        $this->assertSame( 'professional',               $stored['wp_ai_mind_site_voice'] );
        $this->assertArrayHasKey( 'wp_ai_mind_modules', $stored );
        $modules_map = $stored['wp_ai_mind_modules'];
        $this->assertTrue( $modules_map['chat'] );
        $this->assertTrue( $modules_map['summaries'] );
        $this->assertFalse( $modules_map['text_rewrite'] );
        $this->assertFalse( $modules_map['seo'] );
        $this->assertFalse( $modules_map['images'] );
        $this->assertFalse( $modules_map['generator'] );
        $this->assertFalse( $modules_map['frontend_widget'] );
        $this->assertFalse( $modules_map['usage'] );
        $this->assertSame( 'http://localhost:11434',      $stored['wp_ai_mind_ollama_url'] );

        // set_api_key called for non-masked keys only.
        $providers_saved = array_column( $api_key_calls, 0 );
        $this->assertContains( 'claude', $providers_saved );
        $this->assertContains( 'gemini', $providers_saved );
        $this->assertNotContains( 'openai', $providers_saved );   // masked — skipped.
    }

    // ── GET /settings — post type fields ──────────────────────────────────────

    public function test_get_settings_returns_allowed_post_types(): void {
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            if ( 'wp_ai_mind_allowed_post_types' === $key ) {
                return [ 'post' ];
            }
            return is_array( $default ) ? $default : '';
        } );
        Functions\when( 'get_post_types' )->justReturn( [] );
        Functions\when( 'wp_ai_mind_is_pro' )->justReturn( false );

        $controller = new class extends SettingsRestController {
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $stub = new class extends \WP_AI_Mind\Settings\ProviderSettings {
                    public function __construct() {}
                    public function has_key( string $provider ): bool { return false; }
                    public function get_api_key( string $provider ): string { return ''; }
                };
                return $stub;
            }
        };

        $request  = new \WP_REST_Request();
        $response = $controller->get_settings( $request );
        $data     = $response->data;

        $this->assertArrayHasKey( 'allowed_post_types', $data );
        $this->assertSame( [ 'post' ], $data['allowed_post_types'] );
    }

    public function test_get_settings_returns_available_post_types(): void {
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            return is_array( $default ) ? $default : '';
        } );
        Functions\when( 'wp_ai_mind_is_pro' )->justReturn( false );

        // Build fake WP post type objects.
        $fake_post  = new \stdClass();
        $fake_post->labels = new \stdClass();
        $fake_post->labels->singular_name = 'Post';

        $fake_page  = new \stdClass();
        $fake_page->labels = new \stdClass();
        $fake_page->labels->singular_name = 'Page';

        Functions\when( 'get_post_types' )->justReturn( [
            'post' => $fake_post,
            'page' => $fake_page,
        ] );

        $controller = new class extends SettingsRestController {
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $stub = new class extends \WP_AI_Mind\Settings\ProviderSettings {
                    public function __construct() {}
                    public function has_key( string $provider ): bool { return false; }
                    public function get_api_key( string $provider ): string { return ''; }
                };
                return $stub;
            }
        };

        $request  = new \WP_REST_Request();
        $response = $controller->get_settings( $request );
        $data     = $response->data;

        $this->assertArrayHasKey( 'available_post_types', $data );
        $slugs = array_column( $data['available_post_types'], 'slug' );
        $this->assertContains( 'post', $slugs );
        $this->assertContains( 'page', $slugs );
    }

    // ── POST /settings — post type fields ─────────────────────────────────────

    public function test_post_settings_saves_allowed_post_types(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
            return true;
        } );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
        Functions\when( 'get_post_types' )->justReturn( [ 'post' => true, 'page' => true ] );

        $controller = new class extends SettingsRestController {
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $stub = new class extends \WP_AI_Mind\Settings\ProviderSettings {
                    public function __construct() {}
                    public function set_api_key( string $provider, string $key ): void {}
                };
                return $stub;
            }
        };

        $request = new \WP_REST_Request();
        $request->set_body_params( [ 'allowed_post_types' => [ 'post', 'page' ] ] );

        $controller->save_settings( $request );

        $this->assertArrayHasKey( 'wp_ai_mind_allowed_post_types', $stored );
        $this->assertSame( [ 'post', 'page' ], $stored['wp_ai_mind_allowed_post_types'] );
    }

    public function test_post_settings_saves_enable_write_tools(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
            return true;
        } );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_post_types' )->justReturn( [] );

        $controller = new class extends SettingsRestController {
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $stub = new class extends \WP_AI_Mind\Settings\ProviderSettings {
                    public function __construct() {}
                    public function set_api_key( string $provider, string $key ): void {}
                };
                return $stub;
            }
        };

        $request = new \WP_REST_Request();
        $request->set_body_params( [ 'enable_write_tools' => true ] );

        $controller->save_settings( $request );

        $this->assertArrayHasKey( 'wp_ai_mind_enable_write_tools', $stored );
        $this->assertTrue( $stored['wp_ai_mind_enable_write_tools'] );
    }

    public function test_save_settings_skips_masked_api_keys(): void {
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $api_key_calls = [];
        $controller = new class( $api_key_calls ) extends SettingsRestController {
            private array $calls;
            public function __construct( array &$calls ) { $this->calls = &$calls; }
            protected function make_provider_settings(): \WP_AI_Mind\Settings\ProviderSettings {
                $calls = &$this->calls;
                $stub  = new class( $calls ) extends \WP_AI_Mind\Settings\ProviderSettings {
                    private array $calls;
                    public function __construct( array &$calls ) { $this->calls = &$calls; }
                    public function set_api_key( string $provider, string $key ): void {
                        $this->calls[] = [ $provider, $key ];
                    }
                };
                return $stub;
            }
        };

        $request = new \WP_REST_Request();
        $request->set_body_params( [
            'api_keys' => [
                'claude' => '••••••',
                'openai' => '••••••',
                'gemini' => '••••••',
            ],
        ] );

        $controller->save_settings( $request );

        // No provider keys should be saved when all values are masked.
        $this->assertEmpty( $api_key_calls );
    }
}
