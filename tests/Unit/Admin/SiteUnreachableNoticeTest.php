<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Admin\SiteUnreachableNotice;
use Plume\Proxy\SiteRegistration;

/**
 * Covers the permanent-failure notice added in PR #962: page/capability
 * scoping, the success/fail result copy, the gated style enqueue, and the
 * manual-retry handler's capability + redirect behaviour.
 *
 * Mirrors the mocking approach in TierSyncBackfillNoticeTest.
 */
class SiteUnreachableNoticeTest extends TestCase {

	/**
	 * Signal used to unwind handle_retry() before its terminal exit; thrown
	 * from the wp_safe_redirect() stub so the test can inspect the redirect
	 * without the real `exit` killing the PHPUnit process.
	 *
	 * @var string
	 */
	private const REDIRECT_SIGNAL = 'redirect-signal';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// is_plume_admin_page() runs $_GET through these; pass values verbatim.
		Functions\when( 'sanitize_key' )->alias( fn( $value ) => strtolower( (string) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Escaping / i18n — echo or pass through so output assertions see raw text.
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
	}

	protected function tearDown(): void {
		unset( $_GET['page'], $_GET['plume_retry_verification'] );
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
		$ref = new \ReflectionMethod( SiteUnreachableNotice::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}

	/**
	 * Stub get_option so the site reads as unregistered and a permanent-failure
	 * diagnostic is (optionally) on record.
	 *
	 * @param bool $has_failure Whether get_permanent_failure() should return a diagnostic.
	 * @param bool $registered  Whether a site token is present.
	 * @return void
	 */
	private function stub_registration_state( bool $has_failure, bool $registered = false ): void {
		$failure = $has_failure
			? [ 'reason' => 'network_error', 'message' => 'Canned diagnostic message.' ]
			: null;
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( $failure, $registered ) {
				if ( SiteRegistration::OPTION_TOKEN === $key ) {
					return $registered ? 'a-token' : '';
				}
				if ( SiteRegistration::OPTION_PERMANENT_FAILURE === $key ) {
					return $failure ?? $default;
				}
				return $default;
			}
		);
	}

	// ── current_user_can_see_notice() ────────────────────────────────────────

	public function test_can_see_notice_false_for_non_admin(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( $this->invoke_private( 'current_user_can_see_notice' ) );
	}

	public function test_can_see_notice_false_off_plume_page(): void {
		$_GET['page'] = 'edit';
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertFalse( $this->invoke_private( 'current_user_can_see_notice' ) );
	}

	public function test_can_see_notice_true_on_plume_page_with_cap(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( $this->invoke_private( 'current_user_can_see_notice' ) );
	}

	// ── maybe_display() ──────────────────────────────────────────────────────

	public function test_maybe_display_hidden_for_non_admin(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', $this->capture( [ SiteUnreachableNotice::class, 'maybe_display' ] ) );
	}

	public function test_maybe_display_hidden_when_registered(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_registration_state( true, true );

		$this->assertSame( '', $this->capture( [ SiteUnreachableNotice::class, 'maybe_display' ] ) );
	}

	public function test_maybe_display_hidden_when_no_permanent_failure(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_registration_state( false );

		$this->assertSame( '', $this->capture( [ SiteUnreachableNotice::class, 'maybe_display' ] ) );
	}

	public function test_maybe_display_renders_form_when_permanent_failure(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_registration_state( true );

		$output = $this->capture( [ SiteUnreachableNotice::class, 'maybe_display' ] );

		$this->assertStringContainsString( 'Canned diagnostic message.', $output );
		$this->assertStringContainsString( 'nj-backfill-form', $output );
		$this->assertStringContainsString( 'method="post"', $output );
		// The form must not sit inside a <p> (nit #965): it is wrapped in a div.
		$this->assertStringContainsString( 'nj-backfill-form-wrap', $output );
	}

	// ── maybe_display_result() ───────────────────────────────────────────────

	public function test_maybe_display_result_hidden_off_plume_page(): void {
		$_GET['page']                     = 'edit';
		$_GET['plume_retry_verification'] = 'success';
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( '', $this->capture( [ SiteUnreachableNotice::class, 'maybe_display_result' ] ) );
	}

	public function test_maybe_display_result_success(): void {
		$_GET['page']                     = 'plume';
		$_GET['plume_retry_verification'] = 'success';
		Functions\when( 'current_user_can' )->justReturn( true );

		$output = $this->capture( [ SiteUnreachableNotice::class, 'maybe_display_result' ] );

		$this->assertStringContainsString( 'is now connected', $output );
	}

	/**
	 * Fail + a permanent-failure diagnostic on record: the copy may point at the
	 * message maybe_display() renders below it.
	 */
	public function test_maybe_display_result_fail_references_message_when_failure_present(): void {
		$_GET['page']                     = 'plume';
		$_GET['plume_retry_verification'] = 'fail';
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_registration_state( true );

		$output = $this->capture( [ SiteUnreachableNotice::class, 'maybe_display_result' ] );

		$this->assertStringContainsString( 'See the message below', $output );
	}

	/**
	 * Fail on a transient reason clears the diagnostic, so nothing renders below
	 * — the copy must be self-contained (regression guard for issue #963).
	 */
	public function test_maybe_display_result_fail_is_self_contained_without_failure(): void {
		$_GET['page']                     = 'plume';
		$_GET['plume_retry_verification'] = 'fail';
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_registration_state( false );

		$output = $this->capture( [ SiteUnreachableNotice::class, 'maybe_display_result' ] );

		$this->assertStringContainsString( 'Please try again later', $output );
		$this->assertStringNotContainsString( 'See the message below', $output );
	}

	// ── enqueue_styles() ─────────────────────────────────────────────────────

	public function test_enqueue_styles_noop_when_notice_hidden(): void {
		$_GET['page'] = 'edit'; // off a Plume page — notice never shows.
		Functions\when( 'current_user_can' )->justReturn( true );
		$added = false;
		Functions\when( 'wp_add_inline_style' )->alias( function () use ( &$added ) { $added = true; } );

		SiteUnreachableNotice::enqueue_styles();

		$this->assertFalse( $added );
	}

	public function test_enqueue_styles_added_when_notice_visible(): void {
		$_GET['page'] = 'plume';
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_registration_state( true );
		$added = false;
		Functions\when( 'wp_add_inline_style' )->alias( function () use ( &$added ) { $added = true; } );

		SiteUnreachableNotice::enqueue_styles();

		$this->assertTrue( $added );
	}

	// ── handle_retry() ───────────────────────────────────────────────────────

	public function test_handle_retry_denies_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias( function () { throw new \RuntimeException( 'wp_die' ); } );
		// register() must never run for an unprivileged caller.
		Functions\expect( 'wp_remote_get' )->never();

		$this->expectException( \RuntimeException::class );
		SiteUnreachableNotice::handle_retry();
	}

	public function test_handle_retry_redirects_fail_on_transient_error(): void {
		$this->arm_handle_retry();
		// A transient WP_Error from the first Worker call short-circuits register().
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'timeout', 'Connection timed out' ) );

		$redirect = $this->run_handle_retry();

		$this->assertStringContainsString( 'plume_retry_verification=fail', $redirect );
	}

	public function test_handle_retry_redirects_success(): void {
		$this->arm_handle_retry();
		// Drive register() to success: one shared body satisfies both the
		// challenge and the register response (challenge + token both present).
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"challenge":"c","token":"t"}' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );

		$redirect = $this->run_handle_retry();

		$this->assertStringContainsString( 'plume_retry_verification=success', $redirect );
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	/**
	 * Common stubs shared by both handle_retry() redirect tests.
	 *
	 * @return void
	 */
	private function arm_handle_retry(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_get_referer' )->justReturn( 'https://example.test/wp-admin/admin.php?page=plume' );
		Functions\when( 'add_query_arg' )->alias(
			fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value
		);
	}

	/**
	 * Run handle_retry(), intercepting the terminal redirect, and return the URL
	 * passed to wp_safe_redirect().
	 *
	 * @return string The redirect target.
	 */
	private function run_handle_retry(): string {
		$redirect = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect ) {
				$redirect = $url;
				throw new \RuntimeException( self::REDIRECT_SIGNAL );
			}
		);

		try {
			SiteUnreachableNotice::handle_retry();
			$this->fail( 'handle_retry() did not reach wp_safe_redirect().' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( self::REDIRECT_SIGNAL, $e->getMessage() );
		}

		return $redirect;
	}

	/**
	 * Capture the echoed output of a callable.
	 *
	 * @param callable $callback Callback to invoke.
	 * @return string Captured output.
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}
}
