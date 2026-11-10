<?php
/**
 * Unit tests for ManagedCatalogue.
 *
 * @package Plume\Tests\Unit\Providers
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Providers\ManagedCatalogue;

/**
 * Pins how the plugin reads the Worker's enabled providers and model allow-lists.
 *
 * @since NEXT_VERSION
 */
class ManagedCatalogueTest extends TestCase {

	private const KEY_FREE = 'plume_managed_catalogue_free';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\Plume\Tiers\WorkerConfig::reset_memo();
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
	 * Arrange a cache miss for a registered site and a Worker answering with $body.
	 *
	 * @param array<string, mixed> $body Decoded response body.
	 */
	private function arrange_fetch( array $body ): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		$this->stub_worker_body( $body );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_live_response_is_parsed_and_cached_for_an_hour(): void {
		$this->arrange_fetch(
			[
				'tier'              => 'free',
				'default_models'    => [
					'claude' => 'claude-haiku-4-5-20251001',
					'openai' => 'claude-haiku-4-5-20251001',
				],
				'enabled_providers' => [ 'claude' ],
				'models'            => [ 'claude' => [ 'claude-haiku-4-5-20251001' ] ],
			]
		);
		$expected = [
			'enabled_providers' => [ 'claude' ],
			'models'            => [ 'claude' => [ 'claude-haiku-4-5-20251001' ] ],
			'default_models'    => [
				'claude' => 'claude-haiku-4-5-20251001',
				'openai' => 'claude-haiku-4-5-20251001',
			],
		];
		Functions\expect( 'set_transient' )
			->once()
			->with( self::KEY_FREE, $expected, ManagedCatalogue::LIVE_TTL )
			->andReturn( true );

		$this->assertSame( $expected, ManagedCatalogue::get( 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_old_worker_without_enabled_providers_key_enables_every_proxy_provider(): void {
		$this->arrange_fetch(
			[
				'tier'           => 'free',
				'default_models' => [ 'openai' => 'gpt-4.1-nano' ],
			]
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$catalogue = ManagedCatalogue::get( 'free' );

		$this->assertSame( [ 'claude', 'openai', 'gemini' ], $catalogue['enabled_providers'] );
		$this->assertSame( [], $catalogue['models'], 'No Worker list means providers use their own lists.' );
		$this->assertSame( [ 'openai' => 'gpt-4.1-nano' ], $catalogue['default_models'] );
	}

	/**
	 * A present-but-null key is an invalid body, not an old Worker.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_enabled_providers_present_but_null_is_treated_as_invalid_and_falls_back(): void {
		$this->arrange_fetch(
			[
				'tier'              => 'free',
				'enabled_providers' => null,
				'default_models'    => [ 'claude' => 'claude-haiku-4-5-20251001' ],
			]
		);
		Functions\expect( 'set_transient' )
			->once()
			->with(
				self::KEY_FREE,
				[
					'enabled_providers' => ManagedCatalogue::FALLBACK_ENABLED,
					'models'            => [],
					'default_models'    => [],
				],
				ManagedCatalogue::FAILURE_TTL
			)
			->andReturn( true );

		$this->assertSame( [ 'claude' ], ManagedCatalogue::get( 'free' )['enabled_providers'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unknown_providers_are_dropped_and_claude_is_always_added(): void {
		$this->arrange_fetch(
			[
				'tier'              => 'pro_managed',
				'enabled_providers' => [ 'gemini', 'mistral', 'gemini' ],
			]
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$this->assertSame( [ 'claude', 'gemini' ], ManagedCatalogue::get( 'pro_managed' )['enabled_providers'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_models_are_limited_to_enabled_providers_and_valid_ids(): void {
		$long = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$long[] = 'claude-model-' . $i;
		}
		$this->arrange_fetch(
			[
				'tier'              => 'free',
				'enabled_providers' => [ 'claude' ],
				'models'            => [
					'claude' => array_merge( [ 'claude-ok', 'bad id', 42, '' ], $long ),
					'openai' => [ 'gpt-4.1' ],
				],
			]
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$models = ManagedCatalogue::get( 'free' )['models'];

		$this->assertSame( [ 'claude' ], array_keys( $models ) );
		$this->assertCount( 20, $models['claude'] );
		$this->assertSame( 'claude-ok', $models['claude'][0] );
		$this->assertNotContains( 'bad id', $models['claude'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_failure_falls_back_to_constant_and_caches_with_short_ttl(): void {
		$this->stub_token( 'sometoken' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\expect( 'set_transient' )
			->once()
			->with(
				self::KEY_FREE,
				[
					'enabled_providers' => [ 'claude' ],
					'models'            => [],
					'default_models'    => [],
				],
				ManagedCatalogue::FAILURE_TTL
			)
			->andReturn( true );

		$this->assertSame( [ 'claude' ], ManagedCatalogue::get( 'free' )['enabled_providers'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_tier_mismatch_falls_back(): void {
		$this->arrange_fetch(
			[
				'tier'              => 'pro_managed',
				'enabled_providers' => [ 'claude', 'openai' ],
			]
		);
		Functions\expect( 'set_transient' )->once()->with( self::KEY_FREE, \Mockery::type( 'array' ), ManagedCatalogue::FAILURE_TTL )->andReturn( true );

		$this->assertSame( [ 'claude' ], ManagedCatalogue::get( 'free' )['enabled_providers'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unregistered_site_gets_fallback_without_fetch_or_cache(): void {
		$this->stub_token( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$catalogue = ManagedCatalogue::get( 'free' );

		$this->assertSame( [ 'claude' ], $catalogue['enabled_providers'] );
		$this->assertSame( [], $catalogue['models'] );
		$this->assertSame( [], $catalogue['default_models'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_pro_byok_makes_no_call_and_enables_every_provider(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$catalogue = ManagedCatalogue::get( 'pro_byok' );

		$this->assertSame( [ 'claude', 'openai', 'gemini' ], $catalogue['enabled_providers'] );
		$this->assertSame( [], $catalogue['models'] );
		$this->assertSame( [], $catalogue['default_models'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_transient_hit_makes_no_http_call(): void {
		$cached = [
			'enabled_providers' => [ 'claude', 'gemini' ],
			'models'            => [ 'gemini' => [ 'gemini-3.1-flash-lite' ] ],
			'default_models'    => [ 'gemini' => 'gemini-3.1-flash-lite' ],
		];
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )->once()->with( 'plume_managed_catalogue_pro_managed' )->andReturn( $cached );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( $cached, ManagedCatalogue::get( 'pro_managed' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unrecognised_tier_is_cached_under_the_free_key(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'get_transient' )->once()->with( self::KEY_FREE )->andReturn(
			[
				'enabled_providers' => [ 'claude' ],
				'models'            => [],
				'default_models'    => [],
			]
		);
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertTrue( ManagedCatalogue::is_enabled( 'claude', 'bogus' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_is_enabled_and_models_read_the_catalogue(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'enabled_providers' => [ 'claude' ],
				'models'            => [ 'claude' => [ 'claude-haiku-4-5-20251001' ] ],
				'default_models'    => [],
			]
		);

		$this->assertTrue( ManagedCatalogue::is_enabled( 'claude', 'free' ) );
		$this->assertFalse( ManagedCatalogue::is_enabled( 'openai', 'free' ) );
		$this->assertSame( [ 'claude-haiku-4-5-20251001' ], ManagedCatalogue::models( 'claude', 'free' ) );
		$this->assertNull( ManagedCatalogue::models( 'gemini', 'free' ), 'No Worker list means null.' );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_labelled_models_label_precedence(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'enabled_providers' => [ 'claude' ],
				'models'            => [ 'claude' => [ 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'claude-unknown-9' ] ],
				'default_models'    => [],
			]
		);

		$labelled = ManagedCatalogue::labelled_models(
			'claude',
			'free',
			[
				'claude-haiku-4-5-20251001' => 'Provider Haiku',
			]
		);

		$this->assertSame(
			[
				'claude-haiku-4-5-20251001' => 'Provider Haiku',   // Provider list wins.
				'claude-sonnet-4-6'         => 'Claude Sonnet 4.6', // Then ManagedDefaults label.
				'claude-unknown-9'          => 'claude-unknown-9',  // Then the raw id.
			],
			$labelled
		);
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_labelled_models_returns_provider_models_when_worker_gave_no_list(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'enabled_providers' => [ 'claude', 'openai', 'gemini' ],
				'models'            => [],
				'default_models'    => [],
			]
		);

		$own = [ 'gpt-4.1' => 'GPT-4.1' ];
		$this->assertSame( $own, ManagedCatalogue::labelled_models( 'openai', 'free', $own ) );
	}

	// TEMPORARY(#1020): On removal: update this pin to all three providers together with
	// DEFAULT_ENABLED_PROVIDERS in plume-proxy/src/index.ts.
	/**
	 * Parity pin: the same literal as DEFAULT_ENABLED_PROVIDERS in plume-proxy/src/index.ts.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_fallback_enabled_mirrors_the_worker_default(): void {
		$this->assertSame( [ 'claude' ], ManagedCatalogue::FALLBACK_ENABLED );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_cache_lifetimes(): void {
		$this->assertSame( 3600, ManagedCatalogue::LIVE_TTL );
		$this->assertSame( 300, ManagedCatalogue::FAILURE_TTL );
	}
}
