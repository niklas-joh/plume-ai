<?php
namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Providers\ProviderFactory;
use Plume\Providers\CompletionRequest;
use Plume\Providers\ClaudeProvider;
use Plume\Settings\ProviderSettings;
use Plume\Voice\VoiceInjector;
use PHPUnit\Framework\TestCase;

class ProviderIntegrationTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_factory_and_voice_injector_produce_valid_request(): void {
        // Arrange: mock WP options + user meta.
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) => match ( $k ) {
            'plume_site_voice'      => [ 'tone' => 'Professional', 'language' => 'British English' ],
            'plume_default_provider' => 'claude',
            default                       => ( is_array( $d ) ? $d : [] ),
        } );
        Functions\when( 'get_user_meta' )->justReturn( [] );
        Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_textarea_field' )->alias( fn($v) => $v );

        // Act: build system prompt via VoiceInjector.
        $injector = new VoiceInjector();
        $system   = $injector->build_system_prompt( 'Write a blog post introduction.', 0 );

        // Assert: prompt contains voice + feature instruction.
        $this->assertStringContainsString( 'Professional', $system );
        $this->assertStringContainsString( 'British English', $system );
        $this->assertStringContainsString( 'blog post introduction', $system );

        // Act: build CompletionRequest with system prompt.
        $request = new CompletionRequest(
            messages:  [ [ 'role' => 'user', 'content' => 'Write about AI in WordPress.' ] ],
            system:    $system,
            model:     'claude-sonnet-4-6',
            metadata:  [ 'feature' => 'generator', 'post_id' => 42 ],
        );

        // Assert: request is correctly structured.
        $this->assertNotEmpty( $request->system );
        $this->assertSame( 'claude-sonnet-4-6', $request->model );
        $this->assertSame( 'generator', $request->metadata['feature'] );
        $this->assertSame( 42, $request->metadata['post_id'] );

        // Act: factory resolves correct provider from settings.
        $factory  = new ProviderFactory( new ProviderSettings() );
        $provider = $factory->make_default();

        // Assert: correct provider returned.
        $this->assertInstanceOf( ClaudeProvider::class, $provider );
        $this->assertSame( 'claude', $provider->get_slug() );
    }
}
