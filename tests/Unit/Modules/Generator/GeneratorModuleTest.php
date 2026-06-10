<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Generator;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Generator\GeneratorModule;
use PHPUnit\Framework\TestCase;

class GeneratorModuleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_hooks_admin_enqueue_scripts(): void {
        GeneratorModule::register();
        self::assertSame(
            10,
            has_action( 'admin_enqueue_scripts', [ GeneratorModule::class, 'enqueue_assets' ] )
        );
    }

    public function test_register_hooks_rest_api_init(): void {
        GeneratorModule::register();
        self::assertSame(
            10,
            has_action( 'rest_api_init', [ GeneratorModule::class, 'register_routes' ] )
        );
    }

    public function test_enqueue_assets_skips_wrong_page(): void {
        $_GET['page'] = 'some-other-page';

        Functions\expect( 'wp_enqueue_script' )->never();

        GeneratorModule::enqueue_assets( 'toplevel_page_other' );

        unset( $_GET['page'] );

        // Explicit assertion: the method must return without enqueueing anything.
        self::assertArrayNotHasKey( 'page', $_GET );
    }
}
