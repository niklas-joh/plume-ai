<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Core\ModuleRegistry;
use PHPUnit\Framework\TestCase;

class ModuleRegistryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_module_is_enabled_by_default_for_free_modules(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        $registry = new ModuleRegistry();
        $this->assertTrue( $registry->is_enabled( 'chat' ) );
        $this->assertTrue( $registry->is_enabled( 'generator' ) );
        $this->assertTrue( $registry->is_enabled( 'usage' ) );
    }

    public function test_module_can_be_disabled(): void {
        Functions\when( 'get_option' )->justReturn( [ 'chat' => false ] );
        $registry = new ModuleRegistry();
        $this->assertFalse( $registry->is_enabled( 'chat' ) );
    }

    public function test_unknown_module_returns_false(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        $registry = new ModuleRegistry();
        $this->assertFalse( $registry->is_enabled( 'nonexistent_module' ) );
    }

    public function test_get_all_modules_returns_expected_keys(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        $registry = new ModuleRegistry();
        $modules  = $registry->get_all();
        $expected = [ 'chat', 'text_rewrite', 'summaries', 'seo', 'images', 'generator', 'frontend_widget', 'usage' ];
        foreach ( $expected as $key ) {
            $this->assertArrayHasKey( $key, $modules );
        }
    }
}
