<?php
declare( strict_types=1 );

namespace Stilus\Tests\Unit\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Stilus\Core\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Plugin::reset_instance(); // prevent static state leaking between tests
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_instance_returns_same_object(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );

        $a = Plugin::instance();
        $b = Plugin::instance();
        $this->assertSame( $a, $b );
    }
}
