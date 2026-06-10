<?php
namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Providers\ProviderFactory;
use Plume\Providers\ClaudeProvider;
use Plume\Providers\OpenAIProvider;
use Plume\Providers\GeminiProvider;
use Plume\Settings\ProviderSettings;
use PHPUnit\Framework\TestCase;

class ProviderFactoryTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function make_settings(): ProviderSettings {
        // ProviderSettings reads from wp_options — mock get_option to return empty (no key).
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
        return new ProviderSettings();
    }

    public function test_make_returns_claude_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->assertInstanceOf( ClaudeProvider::class, $factory->make( 'claude' ) );
    }

    public function test_make_returns_openai_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->assertInstanceOf( OpenAIProvider::class, $factory->make( 'openai' ) );
    }

    public function test_make_returns_gemini_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->assertInstanceOf( GeminiProvider::class, $factory->make( 'gemini' ) );
    }

    public function test_make_throws_for_unknown_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->expectException( \InvalidArgumentException::class );
        $factory->make( 'nonexistent' );
    }

    public function test_make_default_reads_from_options(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            $k === 'plume_default_provider' ? 'openai' : ( is_array( $d ) ? $d : [] )
        );
        Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
        $factory = new ProviderFactory( new ProviderSettings() );
        $this->assertInstanceOf( OpenAIProvider::class, $factory->make_default() );
    }

    public function test_make_default_falls_back_to_claude(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            $k === 'plume_default_provider' ? false : ( is_array( $d ) ? $d : [] )
        );
        Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
        $factory = new ProviderFactory( new ProviderSettings() );
        $this->assertInstanceOf( ClaudeProvider::class, $factory->make_default() );
    }

    public function test_make_image_provider_returns_gemini_by_default(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            in_array( $k, [ 'plume_image_provider', 'plume_default_provider' ], true ) ? false : ( is_array( $d ) ? $d : [] )
        );
        Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
        $factory = new ProviderFactory( new ProviderSettings() );
        $this->assertInstanceOf( GeminiProvider::class, $factory->make_image_provider() );
    }
}
