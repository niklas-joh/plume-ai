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
		$this->assertStringContainsString( 'random install ID', $captured[1] );
		$this->assertStringContainsString( 'not linked to the managed service', $captured[1] );
		$this->assertSame( 3, substr_count( $captured[1], '<p>' ) );
	}

	public function test_text_has_no_dashes_used_as_punctuation(): void {
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', PrivacyPolicy::text() );
	}
}
