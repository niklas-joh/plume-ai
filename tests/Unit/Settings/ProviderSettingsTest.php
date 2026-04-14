<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Settings\ProviderSettings;
use PHPUnit\Framework\TestCase;

class ProviderSettingsTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_get_api_key_returns_empty_string_when_not_set(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        $settings = new ProviderSettings();
        $this->assertSame( '', $settings->get_api_key( 'claude' ) );
    }

    public function test_set_and_get_api_key_roundtrip(): void {
        $stored = [];
        Functions\when( 'get_option' )->alias( function( $k, $d = null ) use ( &$stored ) { return $stored[ $k ] ?? $d; } );
        Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
            return true;
        } );
        if ( ! defined( 'AUTH_KEY' ) )         define( 'AUTH_KEY',         'test-auth-key-32-chars-exactly!!' );
        if ( ! defined( 'SECURE_AUTH_KEY' ) )  define( 'SECURE_AUTH_KEY',  'test-secure-auth-32-chars!!!!!!' );

        $settings = new ProviderSettings();
        $settings->set_api_key( 'claude', 'sk-ant-test-key' );

        // Reload from "stored" option.
        $settings2 = new ProviderSettings();
        $this->assertSame( 'sk-ant-test-key', $settings2->get_api_key( 'claude' ) );
    }

    public function test_env_var_takes_priority_over_db_value(): void {
        $stored = [];
        Functions\when( 'get_option' )->alias( function( $k, $d = null ) use ( &$stored ) { return $stored[ $k ] ?? $d; } );
        Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
            return true;
        } );

        $settings = new ProviderSettings();
        $settings->set_api_key( 'claude', 'sk-ant-from-db' );

        putenv( 'CLAUDE_API_KEY=sk-ant-from-env' );
        try {
            $settings2 = new ProviderSettings();
            $this->assertSame( 'sk-ant-from-env', $settings2->get_api_key( 'claude' ) );
        } finally {
            putenv( 'CLAUDE_API_KEY' ); // clean up even if assertion fails.
        }
    }

    public function test_falls_back_to_db_when_env_var_not_set(): void {
        $stored = [];
        Functions\when( 'get_option' )->alias( function( $k, $d = null ) use ( &$stored ) { return $stored[ $k ] ?? $d; } );
        Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
            return true;
        } );

        putenv( 'CLAUDE_API_KEY' ); // ensure unset.
        $settings = new ProviderSettings();
        $settings->set_api_key( 'claude', 'sk-ant-from-db' );

        $settings2 = new ProviderSettings();
        $this->assertSame( 'sk-ant-from-db', $settings2->get_api_key( 'claude' ) );
    }

    public function test_api_key_is_not_stored_in_plaintext(): void {
        $stored = [];
        Functions\when( 'get_option' )->alias( function( $k, $d = null ) use ( &$stored ) { return $stored[ $k ] ?? $d; } );
        Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
        } );
        // AUTH_KEY / SECURE_AUTH_KEY already defined in previous test if run in sequence.

        $settings = new ProviderSettings();
        $settings->set_api_key( 'openai', 'sk-proj-plaintext' );

        $option_value = $stored['wp_ai_mind_provider_keys'] ?? [];
        $this->assertNotContains( 'sk-proj-plaintext', $option_value );
    }
}
