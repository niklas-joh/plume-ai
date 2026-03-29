<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Core;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WP_AI_Mind\Core\ProGate;
use PHPUnit\Framework\TestCase;

class ProGateTest extends TestCase {

	protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	public function test_is_pro_returns_false_when_option_not_set(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertFalse( ProGate::is_pro() );
	}

	public function test_is_pro_returns_true_when_option_set(): void {
		Functions\when( 'get_option' )->justReturn( 'active' );
		$this->assertTrue( ProGate::is_pro() );
	}

	public function test_filter_can_override_pro_status(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Filters\expectApplied( 'wp_ai_mind_is_pro' )
			->once()
			->andReturn( true );
		$this->assertTrue( ProGate::is_pro() );
	}

	public function test_global_helper_function_exists(): void {
		// Ensure the class is loaded (which triggers the global function definition).
		class_exists( ProGate::class );
		$this->assertTrue( function_exists( 'wp_ai_mind_is_pro' ) );
	}
}
