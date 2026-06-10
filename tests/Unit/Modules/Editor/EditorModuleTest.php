<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Editor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Editor\EditorModule;
use PHPUnit\Framework\TestCase;

class EditorModuleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_hooks_enqueue_block_editor_assets(): void {
        EditorModule::register();
        self::assertSame(
            10,
            has_action( 'enqueue_block_editor_assets', [ EditorModule::class, 'enqueue_assets' ] )
        );
    }
}
