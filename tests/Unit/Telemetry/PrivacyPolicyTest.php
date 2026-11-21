<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Telemetry\PrivacyPolicy;
use PHPUnit\Framework\TestCase;

class PrivacyPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_admin_init(): void {
		PrivacyPolicy::register();
		$this->assertNotFalse( has_action( 'admin_init', [ PrivacyPolicy::class, 'add_content' ] ) );
	}

	public function test_add_content_registers_the_text(): void {
		$captured = [];
		Functions\when( 'wpautop' )->alias( fn( $text ) => '<p>' . str_replace( "\n\n", '</p><p>', $text ) . '</p>' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\expect( 'wp_add_privacy_policy_content' )
			->once()
			->andReturnUsing(
				function ( $name, $content ) use ( &$captured ) {
					$captured = [ $name, $content ];
				}
			);

		PrivacyPolicy::add_content();

		$this->assertSame( 'Plume AI - Write and Design', $captured[0] );
		$this->assertStringContainsString( "linked to this site's address", $captured[1] );
		$this->assertStringContainsString( 'Google Cloud project in the EU', $captured[1] );
		$this->assertStringContainsString( 'up to 2 years', $captured[1] );
		$this->assertStringContainsString( '<a href="https://wpaimind.com/privacy-policy">', $captured[1] );
		$this->assertStringContainsString( 'Share usage statistics with Plume', $captured[1] );
		$this->assertStringContainsString( 'Share managed-service request statistics', $captured[1] );
		$this->assertStringContainsString( "The site's IP address is processed by Cloudflare to deliver each request and to limit abuse, and may appear in Cloudflare's short-lived request logs, but it is not recorded in the usage statistics.", $captured[1] );
		$this->assertSame( 4, substr_count( $captured[1], '<p>' ) );
	}

	public function test_policy_url_points_at_the_plume_privacy_policy(): void {
		$this->assertSame( 'https://wpaimind.com/privacy-policy', PrivacyPolicy::POLICY_URL );
	}

	public function test_text_makes_no_unlinked_data_claim(): void {
		$this->assertDoesNotMatchRegularExpression( '/anon[y]m/i', PrivacyPolicy::text() );
	}

	public function test_text_has_no_dashes_used_as_punctuation(): void {
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', PrivacyPolicy::text() );
	}
}
