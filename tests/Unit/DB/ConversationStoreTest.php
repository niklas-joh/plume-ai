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

    /** @var array<int, array{0: string, 1: array}> Recorded delete() calls. */
    public array $deleted = [];

    public function delete( string $table, array $where, array $format = [] ): int {
        $this->deleted[] = [ $table, $where ];
        return 1;
    }

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

    /**
     * A message is deleted only together with its conversation id, so a caller
     * can never remove a message from someone else's conversation by id alone.
     *
     * @since NEXT_VERSION
     */
    public function test_delete_message_scopes_the_delete_to_its_conversation(): void {
        global $wpdb;
        $fake = new FakeWpdb();
        $wpdb = $fake;

        ( new ConversationStore() )->delete_message( 42, 99 );

        $this->assertCount( 1, $fake->deleted );
        $this->assertSame( [ 'id' => 99, 'conversation_id' => 42 ], $fake->deleted[0][1] );
    }

    /**
     * An id of 0 (a failed insert) deletes nothing.
     *
     * @since NEXT_VERSION
     */
    public function test_delete_message_ignores_an_empty_id(): void {
        global $wpdb;
        $fake = new FakeWpdb();
        $wpdb = $fake;

        ( new ConversationStore() )->delete_message( 42, 0 );

        $this->assertCount( 0, $fake->deleted );
    }
}
