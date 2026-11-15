<?php
namespace Plume\Tests\Unit\Voice;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Voice\SiteVoice;
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

    // ── Stored shapes (string, nested junk, empty) ────────────────────────────

    private function stub_site_voice( $value ): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            $k === 'plume_site_voice' ? $value : ( $d ?? null )
        );
        Functions\when( 'get_user_meta' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_textarea_field' )->alias( fn($v) => $v );
    }

    public function test_string_site_voice_appears_verbatim_in_prompt(): void {
        $this->stub_site_voice( 'Write like a friendly neighbour.' );

        $prompt = ( new VoiceInjector() )->build_system_prompt( '', 0 );
        $this->assertStringContainsString( 'Write like a friendly neighbour.', $prompt );
    }

    public function test_array_site_voice_extra_appears_in_prompt(): void {
        $this->stub_site_voice( [ 'extra' => 'Persona: dry wit.' ] );

        $prompt = ( new VoiceInjector() )->build_system_prompt( '', 0 );
        $this->assertStringContainsString( 'Persona: dry wit.', $prompt );
    }

    public function test_string_site_voice_merges_with_user_tone_override(): void {
        $this->stub_site_voice( 'Be brief.' );
        Functions\when( 'get_user_meta' )->justReturn( [ 'tone' => 'Casual' ] );

        $prompt = ( new VoiceInjector() )->build_system_prompt( '', 1 );
        $this->assertStringContainsString( 'Be brief.', $prompt );
        $this->assertStringContainsString( 'Tone: Casual', $prompt );
    }

    public function test_nested_junk_in_site_voice_is_ignored_without_warning(): void {
        $this->stub_site_voice( [ 'tone' => [ 'nested' ], 'extra' => 'Keep me.' ] );

        $prompt = ( new VoiceInjector() )->build_system_prompt( '', 0 );
        $this->assertStringContainsString( 'Keep me.', $prompt );
        $this->assertStringNotContainsString( 'Array', $prompt );
        $this->assertStringNotContainsString( 'Tone:', $prompt );
    }

    /**
     * @dataProvider empty_shapes
     */
    public function test_empty_site_voice_yields_only_base_prompt( $value ): void {
        $this->stub_site_voice( $value );

        $prompt = ( new VoiceInjector() )->build_system_prompt( '', 0 );
        $this->assertStringContainsString( 'Do not insert hyperlinks', $prompt );
        $this->assertStringNotContainsString( 'Tone:', $prompt );
    }

    public static function empty_shapes(): array {
        return [ 'empty array' => [ [] ], 'false' => [ false ], 'empty string' => [ '' ] ];
    }

    public function test_voice_saved_in_settings_reaches_the_prompt(): void {
        $stored = [];
        Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$stored ) {
            return array_key_exists( $k, $stored ) ? $stored[ $k ] : $d;
        } );
        Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$stored ) {
            $stored[ $k ] = $v;
            return true;
        } );
        Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$stored ) {
            unset( $stored[ $k ] );
            return true;
        } );
        Functions\when( 'get_user_meta' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'sanitize_textarea_field' )->alias( fn($v) => $v );

        SiteVoice::save_free_text( 'Always sign off with a haiku.' );

        $prompt = ( new VoiceInjector() )->build_system_prompt( '', 0 );
        $this->assertStringContainsString( 'Always sign off with a haiku.', $prompt );
    }
}
