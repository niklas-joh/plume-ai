<?php
/**
 * Unit tests for ManagedDefaults.
 *
 * @package Plume\Tests\Unit\Providers
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
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

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the site token option; an empty string models an unregistered site.
	 *
	 * @param string $token Site token.
	 */
	private function stub_token( string $token ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'plume_site_token' === $key ? $token : $default
		);
	}

	/**
	 * Stub a successful /v1/config response with the given body.
	 *
	 * @param array<string, mixed> $body Decoded response body.
	 */
	private function stub_worker_body( array $body ): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

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
			'gemini pro_managed' => [ 'gemini', 'pro_managed', 'gemini-3.5-flash', 'Gemini 3.5 Flash' ],
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
		// Unregistered site: the offline constants apply and nothing is fetched or cached.
		$this->stub_token( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( $id, ManagedDefaults::model_id( $provider, $tier ) );
		$this->assertSame( $label, ManagedDefaults::label( $id ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unknown_provider_has_no_managed_default(): void {
		$this->stub_token( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		$this->assertNull( ManagedDefaults::model_id( 'ollama', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unknown_model_has_no_label(): void {
		$this->assertNull( ManagedDefaults::label( 'not-a-model' ) );
	}

	// ── Live defaults from the Worker (/v1/config default_models) ──────────────

	/**
	 * @since NEXT_VERSION
	 */
	public function test_live_default_wins_over_the_constant(): void {
		$live = [
			'claude' => 'claude-live-1',
			'openai' => 'gpt-live',
			'gemini' => 'gemini-live',
		];
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )->once()->with( 'plume_default_models_free' )->andReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		$this->stub_worker_body(
			[
				'tier'           => 'free',
				'default_models' => $live,
			]
		);
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_default_models_free', $live, ManagedDefaults::LIVE_TTL )
			->andReturn( true );

		$this->assertSame( 'claude-live-1', ManagedDefaults::model_id( 'claude', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_cache_lifetimes_are_one_hour_live_and_five_minutes_on_failure(): void {
		$this->assertSame( 3600, ManagedDefaults::LIVE_TTL );
		$this->assertSame( 300, ManagedDefaults::FAILURE_TTL );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_transient_hit_makes_no_http_call(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )
			->once()
			->with( 'plume_default_models_pro_managed' )
			->andReturn( [ 'openai' => 'gpt-cached' ] );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( 'gpt-cached', ManagedDefaults::model_id( 'openai', 'pro_managed' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_provider_missing_from_cached_array_falls_back_to_its_constant(): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( [ 'openai' => 'gpt-cached' ] );
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 'claude-sonnet-4-6', ManagedDefaults::model_id( 'claude', 'pro_managed' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_sentinel_hit_uses_constants_without_http_call(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( 'gpt-4.1-nano', ManagedDefaults::model_id( 'openai', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_failure_falls_back_and_caches_empty_sentinel_with_short_ttl(): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_default_models_free', [], ManagedDefaults::FAILURE_TTL )
			->andReturn( true );

		$this->assertSame( 'claude-haiku-4-5-20251001', ManagedDefaults::model_id( 'claude', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_tier_mismatch_falls_back_to_constants(): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		// Worker says pro_managed while the plugin asked about free: must not be trusted.
		$this->stub_worker_body(
			[
				'tier'           => 'pro_managed',
				'default_models' => [ 'claude' => 'claude-pro-live' ],
			]
		);
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_default_models_free', [], ManagedDefaults::FAILURE_TTL )
			->andReturn( true );

		$this->assertSame( 'claude-haiku-4-5-20251001', ManagedDefaults::model_id( 'claude', 'free' ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function malformed_body_provider(): array {
		return [
			'missing field'         => [ [ 'tier' => 'free' ] ],
			'null field'            => [
				[
					'tier'           => 'free',
					'default_models' => null,
				],
			],
			'string field'          => [
				[
					'tier'           => 'free',
					'default_models' => 'claude-live',
				],
			],
			'unknown provider only' => [
				[
					'tier'           => 'free',
					'default_models' => [ 'mistral' => 'mistral-large' ],
				],
			],
		];
	}

	/**
	 * @since NEXT_VERSION
	 * @dataProvider malformed_body_provider
	 * @param array<string, mixed> $body Response body to serve.
	 */
	public function test_malformed_body_falls_back_to_constants( array $body ): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		$this->stub_worker_body( $body );

		$this->assertSame( 'claude-haiku-4-5-20251001', ManagedDefaults::model_id( 'claude', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_partial_body_falls_back_per_provider_only(): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		$this->stub_worker_body(
			[
				'tier'           => 'free',
				'default_models' => [
					'claude'  => 'claude-live-1',
					'openai'  => 'bad id with spaces',
					'gemini'  => 42,
					'mistral' => 'ignored-key',
				],
			]
		);
		// Only the valid entry is cached; invalid values and unknown keys are dropped.
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_default_models_free', [ 'claude' => 'claude-live-1' ], ManagedDefaults::LIVE_TTL )
			->andReturn( true );

		$this->assertSame( 'claude-live-1', ManagedDefaults::model_id( 'claude', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_provider_without_live_value_keeps_its_constant(): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( [ 'claude' => 'claude-live-1' ] );

		$this->assertSame( 'gpt-4.1-nano', ManagedDefaults::model_id( 'openai', 'free' ) );
		$this->assertSame( 'gemini-3.1-flash-lite', ManagedDefaults::model_id( 'gemini', 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unregistered_site_uses_constants_and_caches_nothing(): void {
		$this->stub_token( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( 'gpt-4.1', ManagedDefaults::model_id( 'openai', 'pro_managed' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_pro_byok_uses_constants_and_makes_no_call(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( 'claude-sonnet-4-6', ManagedDefaults::model_id( 'claude', 'pro_byok' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unrecognised_tier_is_cached_under_the_free_key(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )->once()->with( 'plume_default_models_free' )->andReturn( [ 'claude' => 'claude-live-1' ] );
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 'claude-live-1', ManagedDefaults::model_id( 'claude', 'bogus' ) );
	}
}
