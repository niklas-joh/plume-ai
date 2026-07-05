<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Core\Plugin;
use Plume\Proxy\SiteRegistration;
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
        Functions\when( 'flush_rewrite_rules' )->justReturn( null );
        Functions\when( 'get_users' )->justReturn( [] );

        Plugin::activate();
    }

    /**
     * The trial tier no longer exists — activate() must not schedule the
     * plume_trial_check cron event (there is no successor cron event either;
     * zero production users means no upgrade routine is needed).
     */
    public function test_activate_does_not_schedule_trial_check_cron(): void {
        Functions\expect( 'wp_next_scheduled' )->never();
        Functions\expect( 'wp_schedule_event' )->never();

        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'add_option' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'flush_rewrite_rules' )->justReturn( null );
        Functions\when( 'get_users' )->justReturn( [] );

        Plugin::activate();
        $this->addToAssertionCount( 1 );
    }

    /**
     * activate() no longer schedules plume_trial_check, but deactivate() must
     * still defensively clear it so installs upgraded from a version that did
     * schedule it shed the now-callback-less orphaned event.
     */
    public function test_deactivate_clears_orphaned_trial_check_cron_and_does_not_fatal(): void {
        Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'plume_trial_check' );
        Functions\expect( 'flush_rewrite_rules' )->once();

        Plugin::deactivate();
        $this->addToAssertionCount( 1 );
    }

    /**
     * Compliance guard (WP.org Guideline 7 — no phoning home without consent):
     * booting the plugin must NOT hook SiteRegistration::maybe_register on
     * admin_init. Registering there would transmit the site URL to the proxy
     * before the admin takes any AI action. Registration is scheduled lazily on
     * shutdown of the first user-initiated proxy request instead, so silently
     * re-adding the eager hook in a future refactor must fail this test.
     */
    public function test_init_hooks_does_not_register_site_registration_on_admin_init(): void {
        $registered = [];
        Functions\when( 'add_action' )->alias(
            static function ( $hook, $callback = null ) use ( &$registered ) {
                $registered[] = [ $hook, $callback ];
                return true;
            }
        );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'add_filter' )->justReturn( true );

        Plugin::instance();

        $this->assertNotContains(
            [ 'admin_init', [ SiteRegistration::class, 'maybe_register' ] ],
            $registered,
            'SiteRegistration::maybe_register must not be hooked on admin_init (WP.org Guideline 7).'
        );
    }
}
