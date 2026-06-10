<?php
namespace Plume\Tests\Unit\DB;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\DB\ConversationStore;
use PHPUnit\Framework\TestCase;

/**
 * Minimal wpdb stub that supports insert().
 */
class FakeWpdb {
    public string $prefix    = 'wp_';
    public int    $insert_id = 0;

    public function insert( string $table, array $data, array $format = [] ): int {
        return 1;
    }
}

class ConversationStoreTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_create_returns_integer_id(): void {
        global $wpdb;
        $fake            = new FakeWpdb();
        $fake->insert_id = 42;
        $wpdb            = $fake;

        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store = new ConversationStore();
        $id    = $store->create( 'Test conversation', 5 );
        $this->assertSame( 42, $id );
    }

    public function test_add_message_returns_integer(): void {
        global $wpdb;
        $fake            = new FakeWpdb();
        $fake->insert_id = 99;
        $wpdb            = $fake;

        Functions\when( 'wp_kses_post' )->alias( fn( $v ) => $v );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store = new ConversationStore();
        $id    = $store->add_message( 42, 'user', 'Hello', 'gpt-4o', 10 );
        $this->assertSame( 99, $id );
    }
}
