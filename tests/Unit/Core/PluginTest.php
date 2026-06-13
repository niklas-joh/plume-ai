<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Core\Plugin;
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

    public function test_activate_seeds_write_tools_via_add_option(): void {
        // activate() must use add_option() — not update_option() — so that an admin
        // who has explicitly disabled write tools will not have their preference reset
        // on subsequent plugin reactivations (add_option is a WP no-op when the key exists).
        Functions\expect( 'add_option' )
            ->once()
            ->with( 'plume_enable_write_tools', true );
        $this->addToAssertionCount( 1 );

        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\when( 'wp_schedule_event' )->justReturn( true );
        Functions\when( 'flush_rewrite_rules' )->justReturn( null );
        Functions\when( 'get_users' )->justReturn( [] );

        Plugin::activate();
    }
}
