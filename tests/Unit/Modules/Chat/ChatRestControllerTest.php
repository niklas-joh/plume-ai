<?php
namespace Stilus\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Stilus\Modules\Chat\ChatRestController;
use Stilus\Tools\ToolRegistry;
use Stilus\Tools\ToolExecutor;
use Stilus\Providers\CompletionResponse;
use PHPUnit\Framework\TestCase;

class ChatRestControllerTest extends TestCase {

    protected ToolRegistry $tool_registry;
    protected ToolExecutor $tool_executor;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->tool_registry = $this->createMock( ToolRegistry::class );
        $this->tool_executor = $this->createMock( ToolExecutor::class );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── update_conversation ───────────────────────────────────────────────────

    /**
     * Helper: build an anonymous ChatRestController subclass with an injected store mock.
     *
     * @param \Stilus\DB\ConversationStore $store_mock
     * @return ChatRestController
     */
    private function make_controller_with_store( \Stilus\DB\ConversationStore $store_mock ): ChatRestController {
        return new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };
    }

    public function test_update_conversation_returns_404_when_not_found(): void {
        Functions\when( '__' )->alias( fn( $s ) => $s );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( null );

        $controller = $this->make_controller_with_store( $store_mock );

        $request = new \WP_REST_Request( 'PATCH' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'title' => 'New title' ] );

        $response = $controller->update_conversation( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 404, $response->get_error_data()['status'] );
    }

    public function test_update_conversation_returns_403_when_not_owned(): void {
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => '999' ] );

        $controller = $this->make_controller_with_store( $store_mock );

        $request = new \WP_REST_Request( 'PATCH' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'title' => 'Hijacked title' ] );

        $response = $controller->update_conversation( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 403, $response->get_error_data()['status'] );
    }

    public function test_update_conversation_happy_path_calls_update_title_and_returns_updated(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->with( 7 )->willReturn( [ 'user_id' => '5' ] );
        $store_mock->expects( $this->once() )
            ->method( 'update_title' )
            ->with( 7, 'My updated title' )
            ->willReturn( true );

        $controller = $this->make_controller_with_store( $store_mock );

        $request = new \WP_REST_Request( 'PATCH' );
        $request->set_url_params( [ 'id' => '7' ] );
        $request->set_body_params( [ 'title' => 'My updated title' ] );

        $response = $controller->update_conversation( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'updated' => true ], $response->data );
    }

    public function test_update_conversation_sanitises_html_in_title(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 3 );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => strip_tags( $v ) );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => '3' ] );
        $store_mock->expects( $this->once() )
            ->method( 'update_title' )
            ->with( $this->anything(), 'bold' ) // HTML stripped by sanitize_text_field.
            ->willReturn( true );

        $controller = $this->make_controller_with_store( $store_mock );

        $request = new \WP_REST_Request( 'PATCH' );
        $request->set_url_params( [ 'id' => '10' ] );
        $request->set_body_params( [ 'title' => '<b>bold</b>' ] );

        $response = $controller->update_conversation( $request );
        $this->assertInstanceOf( \WP_REST_Response::class, $response );
    }

    public function test_update_conversation_returns_500_when_db_update_fails(): void {
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => '5' ] );
        $store_mock->method( 'update_title' )->willReturn( false );

        $controller = $this->make_controller_with_store( $store_mock );

        $request = new \WP_REST_Request( 'PATCH' );
        $request->set_url_params( [ 'id' => '7' ] );
        $request->set_body_params( [ 'title' => 'Some title' ] );

        $response = $controller->update_conversation( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 500, $response->get_error_data()['status'] );
    }

    // ── list_conversations ────────────────────────────────────────────────────

    public function test_list_conversations_returns_only_expected_keys(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        // Store returns rows with extra internal columns that must not be exposed.
        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'list_for_user' )->with( 1 )->willReturn( [
            [
                'id'         => '5',
                'title'      => 'Hello',
                'updated_at' => '2026-01-10 12:00:00',
                'user_id'    => '1',
                'post_id'    => '42',
            ],
        ] );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request  = new \WP_REST_Request( 'GET' );
        $response = $controller->list_conversations( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertIsArray( $response->data );
        $this->assertCount( 1, $response->data );

        $item = $response->data[0];
        $this->assertArrayHasKey( 'id', $item );
        $this->assertArrayHasKey( 'title', $item );
        $this->assertArrayHasKey( 'updated_at', $item );
        $this->assertArrayNotHasKey( 'user_id', $item, 'user_id must not be exposed in the response.' );
        $this->assertArrayNotHasKey( 'post_id', $item, 'post_id must not be exposed in the response.' );
    }

    public function test_list_conversations_casts_id_to_int(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'list_for_user' )->willReturn( [
            [ 'id' => '99', 'title' => 'Test', 'updated_at' => '2026-02-01 00:00:00', 'user_id' => '1' ],
        ] );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request  = new \WP_REST_Request( 'GET' );
        $response = $controller->list_conversations( $request );

        $this->assertSame( 99, $response->data[0]['id'], 'id must be cast to int, not returned as a string.' );
    }

    public function test_list_conversations_returns_empty_array_when_no_conversations(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'list_for_user' )->willReturn( [] );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request  = new \WP_REST_Request( 'GET' );
        $response = $controller->list_conversations( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [], $response->data );
    }

    // ── create_conversation ───────────────────────────────────────────────────

    public function test_create_conversation_returns_201_with_conversation_data(): void {
        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 7 );
        $store_mock->method( 'get_conversation' )->with( 7 )->willReturn(
            [ 'id' => 7, 'title' => 'My convo', 'updated_at' => '2026-01-01 00:00:00' ]
        );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request = new \WP_REST_Request( 'POST' );
        $request->set_body_params( [ 'title' => 'My convo', 'post_id' => 0 ] );

        $response = $controller->create_conversation( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $this->assertArrayHasKey( 'id', $response->data );
        $this->assertArrayHasKey( 'title', $response->data );
        $this->assertArrayHasKey( 'updated_at', $response->data );
    }

    public function test_create_conversation_returns_500_when_db_insert_fails(): void {
        Functions\when( '__' )->alias( fn( $s ) => $s );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 0 );
        $store_mock->method( 'get_conversation' )->willReturn( null );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request = new \WP_REST_Request( 'POST' );
        $request->set_body_params( [ 'title' => '', 'post_id' => 0 ] );

        $response = $controller->create_conversation( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 500, $response->get_status() );
        $this->assertIsArray( $response->data );
        $this->assertArrayHasKey( 'message', $response->data );
    }

    public function test_create_conversation_returns_500_when_get_conversation_returns_null(): void {
        Functions\when( '__' )->alias( fn( $s ) => $s );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 7 );
        $store_mock->method( 'get_conversation' )->with( 7 )->willReturn( null );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request = new \WP_REST_Request( 'POST' );
        $request->set_body_params( [ 'title' => 'My convo', 'post_id' => 0 ] );

        $response = $controller->create_conversation( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 500, $response->get_status() );
        $this->assertIsArray( $response->data );
        $this->assertArrayHasKey( 'message', $response->data );
    }

    // ── Route registration ─────────────────────────────────────────────────────

    public function test_register_routes_registers_expected_endpoints(): void {
        $registered = [];
        Functions\when( 'register_rest_route' )->alias(
            function( $ns, $route ) use ( &$registered ) {
                $registered[] = $ns . $route;
            }
        );
        Functions\when( 'get_option' )->justReturn( [] );

        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );
        $controller->register_routes();

        $this->assertContains( 'stilus/v1/conversations', $registered );
        $this->assertContains( 'stilus/v1/conversations/(?P<id>\\d+)', $registered, 'PATCH /conversations/{id} route must be registered.' );
        $this->assertContains( 'stilus/v1/conversations/(?P<id>\\d+)/messages', $registered );
        $this->assertContains( 'stilus/v1/providers', $registered );
    }

    // ── Permission check ───────────────────────────────────────────────────────

    /**
     * Helper: build a ChatRestController subclass with both tier and quota checks stubbed to pass.
     *
     * @since 1.8.0
     * @return ChatRestController
     */
    private function make_controller_passing_gates(): ChatRestController {
        return new class( $this->tool_registry, $this->tool_executor ) extends ChatRestController {
            protected function user_can_chat( int $user_id ): bool {
                return true;
            }
            protected function user_within_quota( int $user_id ): bool {
                return true;
            }
        };
    }

    /**
     * Helper: build a ChatRestController subclass with tier check stubbed to fail.
     *
     * @since 1.8.0
     * @return ChatRestController
     */
    private function make_controller_tier_denied(): ChatRestController {
        return new class( $this->tool_registry, $this->tool_executor ) extends ChatRestController {
            protected function user_can_chat( int $user_id ): bool {
                return false;
            }
        };
    }

    /**
     * Helper: build a ChatRestController subclass with quota check stubbed to fail.
     *
     * @since 1.8.0
     * @return ChatRestController
     */
    private function make_controller_quota_exhausted(): ChatRestController {
        return new class( $this->tool_registry, $this->tool_executor ) extends ChatRestController {
            protected function user_can_chat( int $user_id ): bool {
                return true;
            }
            protected function user_within_quota( int $user_id ): bool {
                return false;
            }
        };
    }

    public function test_permission_check_returns_true_for_editors(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $controller = $this->make_controller_passing_gates();

        $result = $controller->check_permission();
        $this->assertTrue( $result );
    }

    public function test_permission_check_fails_for_non_editors(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );

        $result = $controller->check_permission();
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_permission_check_error_has_403_status(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );

        $result = $controller->check_permission();
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_permission_check_fails_when_tier_denies_chat(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        $controller = $this->make_controller_tier_denied();

        $result = $controller->check_permission();
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'rest_tier_denied', $result->get_error_code() );
    }

    public function test_permission_check_tier_error_has_403_status(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        $controller = $this->make_controller_tier_denied();

        $result = $controller->check_permission();
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_permission_check_fails_when_quota_exhausted(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        $controller = $this->make_controller_quota_exhausted();

        $result = $controller->check_permission();
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'rest_quota_exceeded', $result->get_error_code() );
    }

    public function test_permission_check_quota_error_has_403_status(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        $controller = $this->make_controller_quota_exhausted();

        $result = $controller->check_permission();
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    // ── Ownership guard ────────────────────────────────────────────────────────

    public function test_send_message_returns_403_when_conversation_not_owned(): void {
        // Arrange: conversation belongs to user 999, but current user is 1.
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 999 ] );

        // Use an anonymous subclass to inject the store mock.
        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
        };

        // Build request.
        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Hello', 'provider' => '', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 403, $response->get_status() );
    }

    // ── Tool loop ──────────────────────────────────────────────────────────────

    public function test_send_message_tool_loop_executes_tool_and_returns_final(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            return match ( $key ) {
                'stilus_default_provider' => 'claude',
                default                       => $default,
            };
        } );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        // Store mock.
        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Hello' ],
        ] );
        $store_mock->expects( $this->exactly( 2 ) )->method( 'add_message' );

        // Tool call response (iteration 1).
        $tool_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            cost_usd:          0.0,
            raw:               [ 'content' => [] ],
            tool_call:         [ 'id' => 'tc_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
        );

        // Final text response (iteration 2).
        $final_response = new CompletionResponse(
            content:           'Final answer',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     20,
            completion_tokens: 15,
            cost_usd:          0.001,
            raw:               [],
            tool_call:         null,
        );

        // ToolRegistry returns empty tools (no real tool wire-format needed here).
        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        // ToolExecutor executes exactly once.
        $this->tool_executor->expects( $this->once() )
            ->method( 'execute' )
            ->with( 'get_recent_posts', [ 'count' => 3 ], 1 )
            ->willReturn( [ 'posts' => [] ] );

        // Provider mock.
        $provider_mock = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturnOnConsecutiveCalls( $tool_response, $final_response );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Hello', 'provider' => 'claude', 'model' => 'claude-3-5-sonnet' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'Final answer', $response->data['content'] );
    }

    public function test_send_message_returns_429_with_retry_after_header_on_rate_limit(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $provider_mock = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( false );
        $provider_mock->method( 'complete' )->willThrowException(
            new \Stilus\Providers\ProviderException( 'Rate limit exceeded', 'claude', 429 )
        );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '10' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 429, $response->get_status() );
        $headers = $response->get_headers();
        $this->assertArrayHasKey( 'Retry-After', $headers, 'Retry-After header must be present on 429 responses.' );
        $this->assertGreaterThanOrEqual( 0, (int) $headers['Retry-After'], 'Retry-After must be a non-negative number of seconds.' );
    }

    public function test_send_message_maps_provider_403_to_502(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $provider_mock = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( false );
        $provider_mock->method( 'complete' )->willThrowException(
            new \Stilus\Providers\ProviderException( 'Forbidden', 'claude', 403 )
        );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '11' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 502, $response->get_status(), 'Provider 403 must be masked as 502.' );
    }

    public function test_send_message_returns_500_after_max_iterations(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Hi' ],
        ] );

        $tool_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            cost_usd:          0.0,
            raw:               [ 'content' => [] ],
            tool_call:         [ 'id' => 'tc_x', 'name' => 'get_site_info', 'arguments' => [] ],
        );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );
        $this->tool_executor->method( 'execute' )->willReturn( [ 'name' => 'Test Site' ] );

        $provider_mock = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        // Always returns a tool call response.
        $provider_mock->method( 'complete' )->willReturn( $tool_response );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '99' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 500, $response->get_status() );
        $this->assertStringContainsString( 'limit', $response->data['message'] );
    }

    // ── Provider unavailability — 503 / 422 branching ─────────────────────────

    /**
     * Helper: extend make_controller() with a fixed tier for the unavailability branch.
     *
     * @param \Stilus\DB\ConversationStore    $store
     * @param \Stilus\Providers\ProviderFactory $factory
     * @param \Stilus\Voice\VoiceInjector      $voice
     * @param string                           $tier    Tier slug returned by get_user_tier().
     * @return ChatRestController
     */
    private function make_controller_with_tier(
        \Stilus\DB\ConversationStore $store,
        \Stilus\Providers\ProviderFactory $factory,
        \Stilus\Voice\VoiceInjector $voice,
        string $tier
    ): ChatRestController {
        return new class(
            $this->tool_registry,
            $this->tool_executor,
            $store,
            $factory,
            $voice,
            $tier
        ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            private \Stilus\Providers\ProviderFactory $factory_override;
            private \Stilus\Voice\VoiceInjector $voice_override;
            private string $tier_override;

            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store,
                \Stilus\Providers\ProviderFactory $factory,
                \Stilus\Voice\VoiceInjector $voice,
                string $tier
            ) {
                parent::__construct( $tr, $te );
                $this->store_override   = $store;
                $this->factory_override = $factory;
                $this->voice_override   = $voice;
                $this->tier_override    = $tier;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
            protected function make_provider_factory(): \Stilus\Providers\ProviderFactory {
                return $this->factory_override;
            }
            protected function make_voice_injector(): \Stilus\Voice\VoiceInjector {
                return $this->voice_override;
            }
            protected function get_user_tier( int $user_id ): string {
                return $this->tier_override;
            }
        };
    }

    /**
     * A proxy-tier user whose provider is unavailable must receive a 503.
     */
    public function test_send_message_returns_503_for_proxy_tier_when_provider_unavailable(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'has_action' )->justReturn( false );
        Functions\when( 'add_action' )->justReturn( null );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $provider_mock = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( false );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller_with_tier( $store_mock, $factory_mock, $voice_mock, 'free' );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '1' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 503, $response->get_status(), 'Proxy-tier users must receive 503 when the provider is unavailable.' );
        $this->assertArrayHasKey( 'message', $response->data );
        $this->assertStringContainsString( 'Could not connect', $response->data['message'] );
    }

    /**
     * The re-registration shutdown hook must be scheduled exactly once when the
     * provider is unavailable for a proxy-tier user.
     */
    public function test_send_message_schedules_registration_on_shutdown_for_proxy_tier(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        // has_action returns false — hook has not been registered yet this request.
        Functions\when( 'has_action' )->justReturn( false );

        $add_action_calls = 0;
        Functions\when( 'add_action' )->alias(
            function ( $hook, $callback ) use ( &$add_action_calls ) {
                if ( 'shutdown' === $hook ) {
                    ++$add_action_calls;
                }
            }
        );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $provider_mock = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( false );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller_with_tier( $store_mock, $factory_mock, $voice_mock, 'free' );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '1' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $controller->send_message( $request );

        $this->assertSame( 1, $add_action_calls, 'Shutdown hook must be registered exactly once.' );
    }

    /**
     * A BYOK-tier user with an unavailable provider must still receive 422, not 503.
     */
    public function test_send_message_returns_422_for_byok_tier_when_provider_unavailable(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( '__' )->alias( fn( $s ) => $s );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $provider_mock = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( false );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller_with_tier( $store_mock, $factory_mock, $voice_mock, 'pro_byok' );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '1' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 422, $response->get_status(), 'BYOK-tier users must receive 422 (missing API key), not 503.' );
    }

    // ── context_post_id system-prompt injection ───────────────────────────────

    /**
     * @covers \Stilus\Modules\Chat\ChatRestController::send_message
     */
    public function test_send_message_augments_system_prompt_with_post_title_when_authorised(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
        Functions\when( 'esc_attr' )->alias( fn( $v ) => $v );

        $post           = new \WP_Post();
        $post->ID       = 5;
        $post->post_title = 'My Test Post';
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\when( 'current_user_can' )->justReturn( true );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $captured_system = null;
        $provider_mock   = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( false );
        $provider_mock->method( 'complete' )->willReturnCallback(
            function ( $req ) use ( &$captured_system ) {
                $captured_system = $req->system;
                return new CompletionResponse(
                    content:           'done',
                    model:             'claude',
                    prompt_tokens:     1,
                    completion_tokens: 1,
                );
            }
        );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '10' ] );
        $request->set_body_params( [ 'content' => 'Finish it', 'provider' => 'claude', 'model' => '', 'context_post_id' => '5' ] );

        $controller->send_message( $request );

        $this->assertNotNull( $captured_system );
        $this->assertStringContainsString( 'My Test Post', $captured_system );
        $this->assertStringContainsString( '5', $captured_system );
        $this->assertStringContainsString( 'MUST call get_post_content', $captured_system );
        $this->assertStringContainsString( 'post_id=5', $captured_system );
    }

    /**
     * @covers \Stilus\Modules\Chat\ChatRestController::send_message
     */
    public function test_send_message_does_not_augment_system_prompt_when_user_lacks_read_post(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

        $post           = new \WP_Post();
        $post->ID       = 5;
        $post->post_title = 'Private Post';
        Functions\when( 'get_post' )->justReturn( $post );
        // User lacks read_post capability — prompt must not be augmented.
        Functions\when( 'current_user_can' )->justReturn( false );

        $store_mock = $this->createMock( \Stilus\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $captured_system = null;
        $provider_mock   = $this->createMock( \Stilus\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( false );
        $provider_mock->method( 'complete' )->willReturnCallback(
            function ( $req ) use ( &$captured_system ) {
                $captured_system = $req->system;
                return new CompletionResponse(
                    content:           'done',
                    model:             'claude',
                    prompt_tokens:     1,
                    completion_tokens: 1,
                );
            }
        );

        $factory_mock = $this->createMock( \Stilus\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Stilus\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '10' ] );
        $request->set_body_params( [ 'content' => 'Finish it', 'provider' => 'claude', 'model' => '', 'context_post_id' => '5' ] );

        $controller->send_message( $request );

        // System prompt must not contain post title — no privilege escalation.
        $this->assertStringNotContainsString( 'Private Post', $captured_system ?? '' );
    }

    private function make_controller(
        \Stilus\DB\ConversationStore $store,
        \Stilus\Providers\ProviderFactory $factory,
        \Stilus\Voice\VoiceInjector $voice
    ): ChatRestController {
        return new class(
            $this->tool_registry,
            $this->tool_executor,
            $store,
            $factory,
            $voice
        ) extends ChatRestController {
            private \Stilus\DB\ConversationStore $store_override;
            private \Stilus\Providers\ProviderFactory $factory_override;
            private \Stilus\Voice\VoiceInjector $voice_override;

            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Stilus\DB\ConversationStore $store,
                \Stilus\Providers\ProviderFactory $factory,
                \Stilus\Voice\VoiceInjector $voice
            ) {
                parent::__construct( $tr, $te );
                $this->store_override   = $store;
                $this->factory_override = $factory;
                $this->voice_override   = $voice;
            }
            protected function make_store(): \Stilus\DB\ConversationStore {
                return $this->store_override;
            }
            protected function make_provider_factory(): \Stilus\Providers\ProviderFactory {
                return $this->factory_override;
            }
            protected function make_voice_injector(): \Stilus\Voice\VoiceInjector {
                return $this->voice_override;
            }
        };
    }
}
