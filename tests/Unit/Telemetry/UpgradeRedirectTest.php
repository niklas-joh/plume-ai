<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Telemetry\Telemetry;
use Plume\Telemetry\UpgradeRedirect;
use Plume\Tests\Helpers\TelemetryHarness;
use PHPUnit\Framework\TestCase;

class UpgradeRedirectTest extends TestCase {

	private const REDIRECT_SIGNAL = 'redirected';

	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->calls = [];
		$calls       = &$this->calls;
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			function ( $v ) use ( &$calls ) {
				$calls[] = 'sanitize_key';
				return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) );
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) use ( &$calls ) {
				$calls[] = 'nonce:' . $action;
				return 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title = '', $args = [] ) {
				throw new \RuntimeException( 'wp_die:' . ( $args['response'] ?? 0 ) );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_GET['plan'] );
		Telemetry::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function run_handle(): string {
		$target = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$target ) {
				$target = $url;
				throw new \RuntimeException( self::REDIRECT_SIGNAL );
			}
		);
		try {
			UpgradeRedirect::handle();
			$this->fail( 'handle() did not redirect.' );
		} catch ( \RuntimeException $e ) {
			if ( self::REDIRECT_SIGNAL !== $e->getMessage() ) {
				throw $e;
			}
		}
		return $target;
	}

	public function test_url_is_a_nonced_admin_post_link(): void {
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		$this->assertSame(
			'https://example.com/wp-admin/admin-post.php?action=plume_upgrade&plan=monthly&_wpnonce=plume_upgrade',
			UpgradeRedirect::url( 'monthly' )
		);
	}

	public function test_contributor_gets_403(): void {
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_posts' === $cap );
		$_GET['plan'] = 'monthly';
		$this->expectExceptionMessage( 'wp_die:403' );
		UpgradeRedirect::handle();
	}

	public function test_unknown_plan_gets_400(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['plan'] = 'pricing';
		$this->expectExceptionMessage( 'wp_die:400' );
		UpgradeRedirect::handle();
	}

	public function test_plan_is_read_with_sanitize_key_after_the_nonce_check(): void {
		TelemetryHarness::install();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['plan'] = 'monthly';
		$this->run_handle();
		$this->assertSame( [ 'nonce:plume_upgrade', 'sanitize_key' ], $this->calls );
	}

	/**
	 * @dataProvider plans
	 */
	public function test_plans_redirect_to_their_checkout_and_record_the_click( string $plan, string $variant ): void {
		$h = TelemetryHarness::install( [ 'plume_site_token' => 'tok' ] );
		$h->consent_yes();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['plan'] = $plan;

		$target = $this->run_handle();

		$this->assertStringStartsWith( 'https://plume.lemonsqueezy.com/checkout/buy/' . $variant, $target );
		$queue = $h->flush();
		$this->assertSame( 'upgrade_click', $queue[0]['name'] );
		$this->assertSame( 'upgrade_page', $queue[0]['placement'] );
		$this->assertSame( 'upgrade', $queue[0]['screen'] );
		$this->assertSame( $plan, $queue[0]['plan'] );
		$this->assertSame( [], $h->posts );
	}

	public static function plans(): array {
		return [
			'monthly' => [ 'monthly', '1550505' ],
			'annual'  => [ 'annual', '1550477' ],
		];
	}

	public function test_allowed_hosts_include_lemon_squeezy(): void {
		$this->assertSame( [ 'example.com', 'plume.lemonsqueezy.com' ], UpgradeRedirect::allow_checkout_host( [ 'example.com' ] ) );
	}

	public function test_register_hooks_admin_post(): void {
		UpgradeRedirect::register();
		$this->assertNotFalse( has_action( 'admin_post_plume_upgrade', [ UpgradeRedirect::class, 'handle' ] ) );
	}
}
