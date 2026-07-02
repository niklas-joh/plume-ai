<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Admin\TierSyncBackfillNotice;
use Plume\Proxy\SiteRegistration;

/**
 * Covers the WP.org Guideline 11 page-scoping added in PR #888: notices must be
 * suppressed off Plume admin screens and shown on `plume*` slugs.
 */
class TierSyncBackfillNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// is_plume_admin_page() runs $_GET through these; pass values through verbatim.
		Functions\when( 'sanitize_key' )->alias( fn( $value ) => strtolower( (string) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_GET['page'], $_GET['plume_rotate'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke a private static method on the notice class via reflection.
	 *
	 * @param string $method Method name.
	 * @return mixed Method return value.
	 */
	private function invoke_private( string $method ) {
		$ref = new \ReflectionMethod( TierSyncBackfillNotice::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}

	// ── is_plume_admin_page() ────────────────────────────────────────────────

	/**
	 * @dataProvider provide_plume_page_slugs
	 */
	public function test_is_plume_admin_page_true_for_plume_slugs( string $slug ): void {
		$_GET['page'] = $slug;
		$this->assertTrue( $this->invoke_private( 'is_plume_admin_page' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_plume_page_slugs(): array {
		return [
			'plume'           => [ 'plume' ],
			'plume-seo'       => [ 'plume-seo' ],
			'plume-dev-tools' => [ 'plume-dev-tools' ],
		];
	}

	public function test_is_plume_admin_page_false_when_page_absent(): void {
		unset( $_GET['page'] );
		$this->assertFalse( $this->invoke_private( 'is_plume_admin_page' ) );
	}

	public function test_is_plume_admin_page_false_for_non_plume_slug(): void {
		$_GET['page'] = 'edit';
		$this->assertFalse( $this->invoke_private( 'is_plume_admin_page' ) );
	}

	// ── can_show_tier_notice() ───────────────────────────────────────────────

	public function test_can_show_tier_notice_false_off_plume_page_despite_cap_and_registration(): void {
		$_GET['page'] = 'edit';
		Functions\when( 'current_user_can' )->justReturn( true );
		// A registered site would otherwise pass; the page guard must short-circuit first.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				SiteRegistration::OPTION_TOKEN === $key ? 'a-token' : $default
		);

		$this->assertFalse( $this->invoke_private( 'can_show_tier_notice' ) );
	}

	public function test_can_show_tier_notice_true_on_plume_page_when_cap_and_registration_pass(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				SiteRegistration::OPTION_TOKEN === $key ? 'a-token' : $default
		);

		$this->assertTrue( $this->invoke_private( 'can_show_tier_notice' ) );
	}

	// ── maybe_display_result() ───────────────────────────────────────────────

	public function test_maybe_display_result_emits_nothing_off_plume_page(): void {
		$_GET['page']         = 'edit';
		$_GET['plume_rotate'] = 'success';
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		TierSyncBackfillNotice::maybe_display_result();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
