<?php
namespace Plume\Tests\Unit\Voice;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Voice\VoiceInjector;
use PHPUnit\Framework\TestCase;

class VoiceInjectorTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_site_voice_tone_appears_in_prompt(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            $k === 'plume_site_voice' ? [ 'tone' => 'Conversational' ] : ( $d ?? null )
        );
        Functions\when( 'get_user_meta' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_textarea_field' )->alias( fn($v) => $v );

        $injector = new VoiceInjector();
        $this->assertStringContainsString( 'Conversational', $injector->build_system_prompt( '', 0 ) );
    }

    public function test_user_override_replaces_site_tone(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            $k === 'plume_site_voice' ? [ 'tone' => 'Formal' ] : ( $d ?? null )
        );
        Functions\when( 'get_user_meta' )->justReturn( [ 'tone' => 'Casual' ] );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_textarea_field' )->alias( fn($v) => $v );

        $injector = new VoiceInjector();
        $prompt   = $injector->build_system_prompt( '', 1 );
        $this->assertStringContainsString( 'Casual', $prompt );
        $this->assertStringNotContainsString( 'Formal', $prompt );
    }

    public function test_feature_instruction_appended_when_voice_set(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            $k === 'plume_site_voice' ? [ 'tone' => 'Clear' ] : ( $d ?? null )
        );
        Functions\when( 'get_user_meta' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_textarea_field' )->alias( fn($v) => $v );

        $injector = new VoiceInjector();
        $prompt   = $injector->build_system_prompt( 'You are an SEO expert.', 0 );
        $this->assertStringContainsString( 'Clear', $prompt );
        $this->assertStringContainsString( 'SEO expert', $prompt );
    }

    public function test_empty_voice_still_appends_feature_instruction_and_no_links_guidance(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'get_user_meta' )->justReturn( [] );

        $injector = new VoiceInjector();
        $prompt   = $injector->build_system_prompt( 'Rewrite the text.', 0 );

        $this->assertStringContainsString( 'Rewrite the text.', $prompt );
        $this->assertStringContainsString( 'Do not insert hyperlinks', $prompt );
    }

    public function test_no_links_guidance_present_even_with_no_voice_and_no_feature_instruction(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'get_user_meta' )->justReturn( [] );

        $injector = new VoiceInjector();
        $prompt   = $injector->build_system_prompt( '', 0 );

        $this->assertStringContainsString( 'Do not insert hyperlinks', $prompt );
    }

    public function test_no_links_guidance_present_alongside_configured_voice(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            $k === 'plume_site_voice' ? [ 'tone' => 'Conversational' ] : ( $d ?? null )
        );
        Functions\when( 'get_user_meta' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_textarea_field' )->alias( fn($v) => $v );

        $injector = new VoiceInjector();
        $prompt   = $injector->build_system_prompt( '', 0 );

        $this->assertStringContainsString( 'Conversational', $prompt );
        $this->assertStringContainsString( 'Do not insert hyperlinks', $prompt );
    }
}
