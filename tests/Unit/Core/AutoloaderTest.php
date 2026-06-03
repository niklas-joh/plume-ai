<?php
declare( strict_types=1 );

namespace Stilus\Tests\Unit\Core;

use Stilus\Core\Autoloader;
use PHPUnit\Framework\TestCase;

class AutoloaderTest extends TestCase {

    public function test_register_returns_true(): void {
        $result = Autoloader::register();
        $this->assertTrue( $result );
    }

    public function test_loads_existing_class(): void {
        // After register, a real class in the namespace should autoload.
        $this->assertTrue( class_exists( 'Stilus\Core\Plugin' ) );
    }
}
