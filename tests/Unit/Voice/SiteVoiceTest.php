<?php
// tests/Unit/Voice/SiteVoiceTest.php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Voice;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Voice\SiteVoice;
use PHPUnit\Framework\TestCase;

class SiteVoiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs a stateful option store and returns it by reference.
	 *
	 * @param array<string,mixed> $stored Initial options.
	 * @param array<int,string>   $deleted Collects deleted option names.
	 */
	private function stub_options( array &$stored, array &$deleted = [] ): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) use ( &$stored ) {
				return array_key_exists( $key, $stored ) ? $stored[ $key ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$stored, &$deleted ) {
				$deleted[] = $key;
				unset( $stored[ $key ] );
				return true;
			}
		);
	}

	// ── normalise() ───────────────────────────────────────────────────────────

	public function test_normalise_string_becomes_extra(): void {
		$this->assertSame( [ 'extra' => 'Warm and direct' ], SiteVoice::normalise( 'Warm and direct' ) );
	}

	public function test_normalise_whitespace_string_is_empty(): void {
		$this->assertSame( [], SiteVoice::normalise( "  \n " ) );
	}

	public function test_normalise_keeps_known_keys(): void {
		$voice = [
			'tone'     => 'Formal',
			'style'    => 'Concise',
			'language' => 'British English',
			'audience' => 'Developers',
			'avoid'    => 'Jargon',
			'extra'    => 'Be kind.',
		];
		$this->assertSame( $voice, SiteVoice::normalise( $voice ) );
	}

	public function test_normalise_drops_unknown_keys(): void {
		$this->assertSame( [ 'tone' => 'Formal' ], SiteVoice::normalise( [ 'tone' => 'Formal', 'mystery' => 'x' ] ) );
	}

	public function test_normalise_drops_nested_and_non_scalar_values(): void {
		$result = SiteVoice::normalise( [ 'tone' => [ 'a' ], 'style' => null, 'extra' => 'ok', 'avoid' => new \stdClass() ] );
		$this->assertSame( [ 'extra' => 'ok' ], $result );
	}

	public function test_normalise_casts_scalars_to_string(): void {
		$this->assertSame( [ 'tone' => '5' ], SiteVoice::normalise( [ 'tone' => 5 ] ) );
	}

	/**
	 * @dataProvider junk_provider
	 * @param mixed $junk Unsupported stored value.
	 */
	public function test_normalise_junk_is_empty( $junk ): void {
		$this->assertSame( [], SiteVoice::normalise( $junk ) );
	}

	/** @return array<string,array<int,mixed>> */
	public static function junk_provider(): array {
		return [
			'null'   => [ null ],
			'false'  => [ false ],
			'int'    => [ 7 ],
			'object' => [ new \stdClass() ],
		];
	}

	// ── get() / get_free_text() ───────────────────────────────────────────────

	public function test_get_reads_each_stored_shape(): void {
		$stored = [ 'plume_site_voice' => 'friendly' ];
		$this->stub_options( $stored );
		$this->assertSame( [ 'extra' => 'friendly' ], SiteVoice::get() );

		$stored['plume_site_voice'] = [ 'tone' => 'Formal', 'extra' => 'Hi' ];
		$this->assertSame( [ 'tone' => 'Formal', 'extra' => 'Hi' ], SiteVoice::get() );

		unset( $stored['plume_site_voice'] );
		$this->assertSame( [], SiteVoice::get() );
	}

	public function test_get_free_text_returns_extra_or_empty(): void {
		$stored = [ 'plume_site_voice' => [ 'tone' => 'Formal', 'extra' => 'Hi' ] ];
		$this->stub_options( $stored );
		$this->assertSame( 'Hi', SiteVoice::get_free_text() );

		$stored['plume_site_voice'] = [ 'tone' => 'Formal' ];
		$this->assertSame( '', SiteVoice::get_free_text() );

		$stored['plume_site_voice'] = false;
		$this->assertSame( '', SiteVoice::get_free_text() );
	}

	// ── save_free_text() ──────────────────────────────────────────────────────

	public function test_save_creates_array_when_no_option(): void {
		$stored = [];
		$this->stub_options( $stored );
		SiteVoice::save_free_text( 'Warm' );
		$this->assertSame( [ 'extra' => 'Warm' ], $stored['plume_site_voice'] );
	}

	public function test_save_preserves_other_keys_including_unknown(): void {
		$stored = [ 'plume_site_voice' => [ 'tone' => 'Formal', 'language' => 'British English', 'custom' => 'keep', 'extra' => 'old' ] ];
		$this->stub_options( $stored );
		SiteVoice::save_free_text( 'new' );
		$this->assertSame(
			[ 'tone' => 'Formal', 'language' => 'British English', 'custom' => 'keep', 'extra' => 'new' ],
			$stored['plume_site_voice']
		);
	}

	public function test_save_converts_legacy_string_to_array(): void {
		$stored = [ 'plume_site_voice' => 'friendly' ];
		$this->stub_options( $stored );
		SiteVoice::save_free_text( 'professional' );
		$this->assertSame( [ 'extra' => 'professional' ], $stored['plume_site_voice'] );
	}

	public function test_save_empty_clears_extra_but_keeps_other_keys(): void {
		$stored = [ 'plume_site_voice' => [ 'tone' => 'Formal', 'extra' => 'old' ] ];
		$deleted = [];
		$this->stub_options( $stored, $deleted );
		SiteVoice::save_free_text( '' );
		$this->assertSame( [ 'tone' => 'Formal', 'extra' => '' ], $stored['plume_site_voice'] );
		$this->assertSame( [], $deleted );
	}

	public function test_save_empty_with_nothing_else_deletes_option(): void {
		$stored = [ 'plume_site_voice' => 'friendly' ];
		$deleted = [];
		$this->stub_options( $stored, $deleted );
		SiteVoice::save_free_text( '' );
		$this->assertSame( [ 'plume_site_voice' ], $deleted );
		$this->assertArrayNotHasKey( 'plume_site_voice', $stored );
	}

	public function test_save_empty_with_no_option_deletes_option(): void {
		$stored = [];
		$deleted = [];
		$this->stub_options( $stored, $deleted );
		SiteVoice::save_free_text( '' );
		$this->assertSame( [ 'plume_site_voice' ], $deleted );
	}

	public function test_save_keeps_line_breaks(): void {
		$stored = [];
		$this->stub_options( $stored );
		SiteVoice::save_free_text( "Line one\nLine two" );
		$this->assertSame( "Line one\nLine two", $stored['plume_site_voice']['extra'] );
	}
}
