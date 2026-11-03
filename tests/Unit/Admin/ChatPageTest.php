<?php
/**
 * Unit tests for ChatPage's "Plugin default" model label.
 *
 * @package Plume\Tests\Unit\Admin
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Admin\ChatPage;

if ( ! defined( 'PLUME_DIR' ) ) {
	define( 'PLUME_DIR', dirname( __DIR__, 3 ) . '/' );
}
if ( ! defined( 'PLUME_URL' ) ) {
	define( 'PLUME_URL', 'http://example.test/wp-content/plugins/plume/' );
}
if ( ! defined( 'PLUME_VERSION' ) ) {
	define( 'PLUME_VERSION', '0.0.0-test' );
}

/**
 * The label must describe the model a request with no explicit model really uses:
 * the managed default for the tier when there is no API key, the provider's own
 * default when the site calls the provider directly.
 *
 * Exercised through render(), capturing the wp_localize_script() payload.
 *
 * @since NEXT_VERSION
 */
class ChatPageTest extends TestCase {

	/** @var array<string, mixed> Captured plumeData payload. */
	private array $localised = [];

	/** @var string|false Original CLAUDE_API_KEY environment value. */
	private string|false $original_env;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_env = getenv( 'CLAUDE_API_KEY' );
		putenv( 'CLAUDE_API_KEY' );

		Functions\stubs(
			[
				'wp_enqueue_script'  => null,
				'wp_enqueue_style'   => null,
				'wp_create_nonce'    => 'nonce',
				'rest_url'           => 'http://example.test/wp-json/plume/v1',
				'esc_url_raw'        => null,
				'get_bloginfo'       => 'Test site',
				'current_user_can'   => true,
			]
		);
		// publish_caps() is irrelevant here; no post types means no capability lookups.
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => 'plume_allowed_post_types' === $hook ? [] : $value );
		Functions\when( 'wp_localize_script' )->alias(
			function ( string $handle, string $name, array $data ): void {
				$this->localised = $data;
			}
		);
	}

	protected function tearDown(): void {
		if ( false === $this->original_env ) {
			putenv( 'CLAUDE_API_KEY' );
		} else {
			putenv( 'CLAUDE_API_KEY=' . $this->original_env );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run render() with the given options and return the localised label.
	 *
	 * @param string $tier     Stored site tier.
	 * @param string $provider Stored default provider slug.
	 * @return string
	 */
	private function label_for( string $tier, string $provider = 'claude' ): string {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $tier, $provider ) {
				return match ( $name ) {
					'plume_site_tier'        => $tier,
					'plume_default_provider' => $provider,
					default                  => $default,
				};
			}
		);

		ob_start();
		ChatPage::render();
		ob_end_clean();

		return $this->localised['defaultModelLabel'];
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_free_without_key_shows_haiku(): void {
		$this->assertSame( 'Claude Haiku 4.5', $this->label_for( 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_pro_managed_without_key_shows_sonnet(): void {
		$this->assertSame( 'Claude Sonnet 4.6', $this->label_for( 'pro_managed' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_pro_byok_without_key_shows_sonnet(): void {
		$this->assertSame( 'Claude Sonnet 4.6', $this->label_for( 'pro_byok' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unknown_tier_is_treated_as_free(): void {
		$this->assertSame( 'Claude Haiku 4.5', $this->label_for( 'bogus' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_own_key_shows_provider_default_on_any_tier(): void {
		putenv( 'CLAUDE_API_KEY=sk-test' );
		$this->assertSame( 'Claude Sonnet 4.6', $this->label_for( 'free' ) );
	}

	/**
	 * Ollama has no proxy path, so it always shows its own default.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_ollama_shows_provider_default(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => match ( $name ) {
				'plume_default_provider' => 'ollama',
				'plume_ollama_model'     => 'llama3.2',
				default                  => $default,
			}
		);

		ob_start();
		ChatPage::render();
		ob_end_clean();

		$this->assertSame( 'llama3.2', $this->localised['defaultModelLabel'] );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_factory_failure_falls_back_to_generic_label(): void {
		$this->assertSame( 'AI', $this->label_for( 'free', 'not-a-provider' ) );
	}
}
