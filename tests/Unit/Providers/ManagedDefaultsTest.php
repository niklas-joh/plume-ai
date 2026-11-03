<?php
/**
 * Unit tests for ManagedDefaults.
 *
 * @package Plume\Tests\Unit\Providers
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use Plume\Providers\ManagedDefaults;

/**
 * Pins the managed-proxy default model per provider and tier.
 *
 * The literals below are the same ones pinned by plume-proxy/test/default-model.test.ts
 * (DEFAULT_MODEL_BY_TIER), so a change on either side fails a test on that side.
 *
 * @since NEXT_VERSION
 */
class ManagedDefaultsTest extends TestCase {

	/**
	 * @since NEXT_VERSION
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function default_provider(): array {
		return [
			'claude free'        => [ 'claude', 'free', 'claude-haiku-4-5-20251001', 'Claude Haiku 4.5' ],
			'claude pro_managed' => [ 'claude', 'pro_managed', 'claude-sonnet-4-6', 'Claude Sonnet 4.6' ],
			'claude pro_byok'    => [ 'claude', 'pro_byok', 'claude-sonnet-4-6', 'Claude Sonnet 4.6' ],
			'openai free'        => [ 'openai', 'free', 'gpt-4.1-nano', 'GPT-4.1 nano' ],
			'openai pro_managed' => [ 'openai', 'pro_managed', 'gpt-4.1', 'GPT-4.1' ],
			'gemini free'        => [ 'gemini', 'free', 'gemini-3.1-flash-lite', 'Gemini 3.1 Flash Lite' ],
			'gemini pro_managed' => [ 'gemini', 'pro_managed', 'gemini-3.6-flash', 'Gemini 3.6 Flash' ],
			'claude unknown tier' => [ 'claude', 'bogus', 'claude-haiku-4-5-20251001', 'Claude Haiku 4.5' ],
		];
	}

	/**
	 * @since NEXT_VERSION
	 * @dataProvider default_provider
	 * @param string $provider Provider slug.
	 * @param string $tier     Tier slug.
	 * @param string $id       Expected model id.
	 * @param string $label    Expected label.
	 */
	public function test_default_id_and_label( string $provider, string $tier, string $id, string $label ): void {
		$this->assertSame( $id, ManagedDefaults::model_id( $provider, $tier ) );
		$this->assertSame( $label, ManagedDefaults::label( $id ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unknown_provider_has_no_managed_default(): void {
		$this->assertNull( ManagedDefaults::model_id( 'ollama', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unknown_model_has_no_label(): void {
		$this->assertNull( ManagedDefaults::label( 'not-a-model' ) );
	}
}
