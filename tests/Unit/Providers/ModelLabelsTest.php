<?php
/**
 * Unit tests for ModelLabels.
 *
 * @package Plume\Tests\Unit\Providers
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Providers\ClaudeProvider;
use Plume\Providers\GeminiProvider;
use Plume\Providers\ManagedDefaults;
use Plume\Providers\ModelLabels;
use Plume\Providers\OpenAIProvider;

/**
 * Pins how raw model ids become display names.
 *
 * @since NEXT_VERSION
 */
class ModelLabelsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ) => trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every id in a curated list maps to its curated label.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function known_ids(): array {
		$cases = [];
		foreach ( array_merge( ManagedDefaults::labels(), ClaudeProvider::MODELS, OpenAIProvider::MODELS, GeminiProvider::MODELS ) as $id => $label ) {
			$cases[ $id ] = [ $id, $label ];
		}
		return $cases;
	}

	/**
	 * @dataProvider known_ids
	 * @since NEXT_VERSION
	 * @param string $id    Model id.
	 * @param string $label Expected label.
	 */
	public function test_known_ids_use_the_curated_label( string $id, string $label ): void {
		$this->assertSame( $label, ModelLabels::for_id( $id ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_managed_and_provider_labels_agree_for_gpt_luna(): void {
		$this->assertSame( 'GPT-6 Luna', ModelLabels::for_id( 'gpt-6-luna' ) );
		$this->assertSame( ManagedDefaults::label( 'gpt-6-luna' ), OpenAIProvider::MODELS['gpt-6-luna'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function snapshot_and_unknown_ids(): array {
		return [
			'iso date snapshot'     => [ 'gpt-6-luna-2026-04-14', 'GPT-6 Luna' ],
			'compact date snapshot' => [ 'claude-3-5-sonnet-20241022', 'Claude 3.5 Sonnet' ],
			'latest alias'          => [ 'gpt-6-sol-latest', 'GPT-6 Sol' ],
			'unlisted claude id'    => [ 'claude-sonnet-4-5', 'Claude Sonnet 4.5' ],
			'ollama tag'            => [ 'llama3.2:latest', 'Llama3.2' ],
			'ollama size tag'       => [ 'qwen2.5:7b', 'Qwen2.5' ],
			'gemini suffix kept'    => [ 'gemini-3.6-flash-001', 'Gemini 3.6 Flash 001' ],
			'gpt family humanised'  => [ 'gpt-5-turbo', 'GPT-5 Turbo' ],
			'underscores'           => [ 'my_custom_model', 'My Custom Model' ],
		];
	}

	/**
	 * @dataProvider snapshot_and_unknown_ids
	 * @since NEXT_VERSION
	 * @param string $id       Model id.
	 * @param string $expected Expected label.
	 */
	public function test_snapshots_and_unknown_ids_are_humanised( string $id, string $expected ): void {
		$this->assertSame( $expected, ModelLabels::for_id( $id ) );
	}

	/**
	 * The exact lookup runs before any suffix stripping.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_exact_match_wins_over_suffix_stripping(): void {
		// The date is part of the curated id, so stripping it first would lose the label.
		$this->assertSame( 'Claude Haiku 4.5', ModelLabels::for_id( 'claude-haiku-4-5-20251001' ) );
	}

	/**
	 * Only dates, "-latest" and Ollama tags are stripped.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_other_trailing_numbers_are_not_treated_as_snapshots(): void {
		$this->assertSame( 'Model 42', ModelLabels::for_id( 'model-42' ) );
		$this->assertSame( 'Foo 123', ModelLabels::for_id( 'foo-123' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_empty_id_gives_empty_label(): void {
		$this->assertSame( '', ModelLabels::for_id( '' ) );
		$this->assertSame( '', ModelLabels::for_id( '   ' ) );
	}

	/**
	 * @return array<int, array{string}>
	 */
	public static function unknown_slugs(): array {
		return [
			[ 'mistral-large-2411' ],
			[ 'some-new-model-x' ],
			[ 'o3-mini' ],
			[ 'deepseek-r1' ],
			[ 'command-r-plus' ],
			[ 'grok-4-fast' ],
		];
	}

	/**
	 * @dataProvider unknown_slugs
	 * @since NEXT_VERSION
	 * @param string $id Unknown hyphenated id.
	 */
	public function test_unknown_hyphenated_ids_are_never_returned_raw( string $id ): void {
		$label = ModelLabels::for_id( $id );
		$this->assertNotSame( $id, $label );
		$this->assertNotSame( '', $label );
		$this->assertStringNotContainsString( '-', $label );
	}

	/**
	 * A bare number cannot be humanised, so a neutral name is used instead of the id.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_unreadable_slug_gets_neutral_label(): void {
		$this->assertSame( 'AI model', ModelLabels::for_id( '1234' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_markup_in_the_id_is_removed(): void {
		$label = ModelLabels::for_id( 'claude-<script>alert(1)</script>-pro' );
		$this->assertStringNotContainsString( '<', $label );
		$this->assertStringNotContainsString( '>', $label );

		$this->assertSame( 'AI model', ModelLabels::for_id( '<b></b>' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_very_long_ids_are_capped(): void {
		$label = ModelLabels::for_id( str_repeat( 'model-', 100 ) );
		$this->assertLessThanOrEqual( 60, mb_strlen( $label ) );
	}
}
