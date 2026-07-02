<?php

declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Chat\ChatRestController;
use Plume\Tools\ToolRegistry;
use Plume\Tools\ToolExecutor;
use Plume\Providers\CompletionResponse;
use Plume\Tests\Helpers\WpdbStubFactory;
use PHPUnit\Framework\TestCase;

class ChatRestControllerTest extends TestCase {

    protected ToolRegistry $tool_registry;
    protected ToolExecutor $tool_executor;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->tool_registry = $this->createMock( ToolRegistry::class );
        $this->tool_executor = $this->createMock( ToolExecutor::class );
        // Ensure a valid $wpdb stub is always present — other test classes (e.g.
        // AbstractProviderTest) replace it with a bare stdClass without restoring it.
        global $wpdb;
        $wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── update_conversation ───────────────────────────────────────────────────

    /**
     * Helper: build an anonymous ChatRestController subclass with an injected store mock.
     *
     * @param \Plume\DB\ConversationStore $store_mock
     * @return ChatRestController
     */
    private function make_controller_with_store( \Plume\DB\ConversationStore $store_mock ): ChatRestController {
        return new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
                return $this->store_override;
            }
        };
    }

    public function test_update_conversation_returns_404_when_not_found(): void {
        Functions\when( '__' )->alias( fn( $s ) => $s );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
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
        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
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
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'list_for_user' )->willReturn( [
            [ 'id' => '99', 'title' => 'Test', 'updated_at' => '2026-02-01 00:00:00', 'user_id' => '1' ],
        ] );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request  = new \WP_REST_Request( 'GET' );
        $response = $controller->list_conversations( $request );

        $this->assertSame( 99, $response->data[0]['id'], 'id must be cast to int, not returned as a string.' );
    }

    public function test_list_conversations_returns_empty_array_when_no_conversations(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'list_for_user' )->willReturn( [] );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
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
        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 7 );
        $store_mock->method( 'get_conversation' )->with( 7 )->willReturn(
            [ 'id' => 7, 'title' => 'My convo', 'updated_at' => '2026-01-01 00:00:00' ]
        );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 0 );
        $store_mock->method( 'get_conversation' )->willReturn( null );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 7 );
        $store_mock->method( 'get_conversation' )->with( 7 )->willReturn( null );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
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

        $this->assertContains( 'plume/v1/conversations', $registered );
        $this->assertContains( 'plume/v1/conversations/(?P<id>\\d+)', $registered, 'PATCH /conversations/{id} route must be registered.' );
        $this->assertContains( 'plume/v1/conversations/(?P<id>\\d+)/messages', $registered );
        $this->assertContains( 'plume/v1/providers', $registered );
    }

    // ── Permission check ───────────────────────────────────────────────────────
    //
    // check_permission() no longer checks tier or quota — credit enforcement
    // happens entirely on the Worker side. It now collapses to a single
    // current_user_can('edit_posts') check, identical across every tier.
    // user_can_chat()/user_within_quota() were deleted entirely (not left as
    // dead code) to prevent a future regression re-wiring them back in.

    public function test_permission_check_returns_true_for_editors(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );

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

    public function test_permission_check_ignores_tier_entirely(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );

        foreach ( [ 'free', 'pro_managed', 'pro_byok' ] as $tier ) {
            Functions\when( 'get_option' )->justReturn( $tier );
            $this->assertTrue( $controller->check_permission(), "check_permission() must return true for tier '{$tier}'." );
        }
    }

    public function test_user_can_chat_method_does_not_exist(): void {
        $this->assertFalse(
            method_exists( ChatRestController::class, 'user_can_chat' ),
            'user_can_chat() must be deleted entirely, not left as dead code.'
        );
    }

    public function test_user_within_quota_method_does_not_exist(): void {
        $this->assertFalse(
            method_exists( ChatRestController::class, 'user_within_quota' ),
            'user_within_quota() must be deleted entirely, not left as dead code.'
        );
    }

    // ── Ownership guard ────────────────────────────────────────────────────────

    public function test_send_message_returns_403_when_conversation_not_owned(): void {
        // Arrange: conversation belongs to user 999, but current user is 1.
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 999 ] );

        // Use an anonymous subclass to inject the store mock.
        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
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
                'plume_default_provider' => 'claude',
                default                       => $default,
            };
        } );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        // Store mock.
        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
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
        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturnOnConsecutiveCalls( $tool_response, $final_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( false );
        $provider_mock->method( 'complete' )->willThrowException(
            new \Plume\Providers\ProviderException( 'Rate limit exceeded', 'claude', 429 )
        );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( false );
        $provider_mock->method( 'complete' )->willThrowException(
            new \Plume\Providers\ProviderException( 'Forbidden', 'claude', 403 )
        );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '11' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 502, $response->get_status(), 'Provider 403 must be masked as 502.' );
    }

    public function test_send_message_returns_200_with_message_after_max_iterations(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
        // __() is called to build the limit message; pass strings through untranslated in unit tests.
        Functions\when( '__' )->returnArg();
        Functions\when( 'update_user_meta' )->justReturn( true );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Hi' ],
        ] );
        $store_mock->method( 'add_message' )->willReturn( 99 );

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

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        // Always returns a tool call response — loop will exhaust MAX_TOOL_ITERATIONS.
        $provider_mock->method( 'complete' )->willReturn( $tool_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '99' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        // Must be 200 with a user-facing message, not a 500 crash.
        $this->assertSame( 200, $response->get_status() );
        $this->assertStringContainsString( 'maximum number of steps', $response->data['content'] );
    }

    // ── Provider unavailability — 503 / 422 branching ─────────────────────────

    /**
     * Helper: extend make_controller() for the unavailability branch.
     *
     * Tier is no longer read via an overridable controller method — is_proxy_tier
     * now calls TierManager::user_can('own_api_key') directly, which resolves the
     * tier from the site option. Tests control the tier by stubbing get_option()
     * for TierManager::SITE_OPTION before constructing the controller.
     *
     * @param \Plume\DB\ConversationStore    $store
     * @param \Plume\Providers\ProviderFactory $factory
     * @param \Plume\Voice\VoiceInjector      $voice
     * @return ChatRestController
     */
    private function make_controller_with_tier(
        \Plume\DB\ConversationStore $store,
        \Plume\Providers\ProviderFactory $factory,
        \Plume\Voice\VoiceInjector $voice
    ): ChatRestController {
        return new class(
            $this->tool_registry,
            $this->tool_executor,
            $store,
            $factory,
            $voice
        ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            private \Plume\Providers\ProviderFactory $factory_override;
            private \Plume\Voice\VoiceInjector $voice_override;

            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store,
                \Plume\Providers\ProviderFactory $factory,
                \Plume\Voice\VoiceInjector $voice
            ) {
                parent::__construct( $tr, $te );
                $this->store_override   = $store;
                $this->factory_override = $factory;
                $this->voice_override   = $voice;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
                return $this->store_override;
            }
            protected function make_provider_factory(): \Plume\Providers\ProviderFactory {
                return $this->factory_override;
            }
            protected function make_voice_injector(): \Plume\Voice\VoiceInjector {
                return $this->voice_override;
            }
        };
    }

    /**
     * A proxy-tier user whose provider is unavailable must receive a 503.
     */
    public function test_send_message_returns_503_for_proxy_tier_when_provider_unavailable(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'has_action' )->justReturn( false );
        Functions\when( 'add_action' )->justReturn( null );
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( 'plume_site_tier' === $key ) {
                    return 'free';
                }
                if ( \Plume\Payments\TierUpdateWebhookController::OPTION_SECRET === $key ) {
                    return ''; // No sync secret — is_site_tier_verified() short-circuits to true.
                }
                return 'claude' === $default ? 'claude' : $default;
            }
        );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( false );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller_with_tier( $store_mock, $factory_mock, $voice_mock );

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
        Functions\when( '__' )->alias( fn( $s ) => $s );
        // has_action returns false — hook has not been registered yet this request.
        Functions\when( 'has_action' )->justReturn( false );
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( 'plume_site_tier' === $key ) {
                    return 'free';
                }
                if ( \Plume\Payments\TierUpdateWebhookController::OPTION_SECRET === $key ) {
                    return '';
                }
                return 'claude' === $default ? 'claude' : $default;
            }
        );

        $add_action_calls = 0;
        Functions\when( 'add_action' )->alias(
            function ( $hook, $callback ) use ( &$add_action_calls ) {
                if ( 'shutdown' === $hook ) {
                    ++$add_action_calls;
                }
            }
        );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( false );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller_with_tier( $store_mock, $factory_mock, $voice_mock );

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
        Functions\when( '__' )->alias( fn( $s ) => $s );
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( 'plume_site_tier' === $key ) {
                    return 'pro_byok';
                }
                if ( \Plume\Payments\TierUpdateWebhookController::OPTION_SECRET === $key ) {
                    return '';
                }
                return 'claude' === $default ? 'claude' : $default;
            }
        );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( false );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller_with_tier( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '1' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 422, $response->get_status(), 'BYOK-tier users must receive 422 (missing API key), not 503.' );
    }

    // ── context_post_id system-prompt injection ───────────────────────────────

    /**
     * @covers \Plume\Modules\Chat\ChatRestController::send_message
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $captured_system = null;
        $provider_mock   = $this->createMock( \Plume\Providers\ProviderInterface::class );
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

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
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
     * @covers \Plume\Modules\Chat\ChatRestController::send_message
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

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [] );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );

        $captured_system = null;
        $provider_mock   = $this->createMock( \Plume\Providers\ProviderInterface::class );
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

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
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
        \Plume\DB\ConversationStore $store,
        \Plume\Providers\ProviderFactory $factory,
        \Plume\Voice\VoiceInjector $voice
    ): ChatRestController {
        return new class(
            $this->tool_registry,
            $this->tool_executor,
            $store,
            $factory,
            $voice
        ) extends ChatRestController {
            private \Plume\DB\ConversationStore $store_override;
            private \Plume\Providers\ProviderFactory $factory_override;
            private \Plume\Voice\VoiceInjector $voice_override;

            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \Plume\DB\ConversationStore $store,
                \Plume\Providers\ProviderFactory $factory,
                \Plume\Voice\VoiceInjector $voice
            ) {
                parent::__construct( $tr, $te );
                $this->store_override   = $store;
                $this->factory_override = $factory;
                $this->voice_override   = $voice;
            }
            protected function make_store(): \Plume\DB\ConversationStore {
                return $this->store_override;
            }
            protected function make_provider_factory(): \Plume\Providers\ProviderFactory {
                return $this->factory_override;
            }
            protected function make_voice_injector(): \Plume\Voice\VoiceInjector {
                return $this->voice_override;
            }
        };
    }

    // ── append_tool_exchange: Gemini multi-tool ───────────────────────────────

    /**
     * Call the private append_tool_exchange method via reflection.
     */
    private function call_append_tool_exchange(
        array $messages,
        string $provider_slug,
        CompletionResponse $response,
        array $tool_results
    ): array {
        $method = new \ReflectionMethod( ChatRestController::class, 'append_tool_exchange' );
        $method->setAccessible( true );
        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );
        return $method->invoke( $controller, $messages, $provider_slug, $response, $tool_results );
    }

    public function test_gemini_append_tool_exchange_handles_single_tool_call(): void {
        $raw_data = [
            'data' => [
                'candidates' => [ [
                    'content' => [
                        'parts' => [ [
                            'functionCall' => [ 'id' => 'c1', 'name' => 'get_site_info', 'args' => [] ],
                        ] ],
                    ],
                ] ],
            ],
            'call_id' => 'c1',
        ];

        $response = new CompletionResponse(
            content: '',
            model: 'gemini-2.0-flash',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: $raw_data,
            tool_call: [ 'id' => 'c1', 'name' => 'get_site_info', 'arguments' => [] ],
        );

        $messages = $this->call_append_tool_exchange( [], 'gemini', $response, [
            'c1' => [ 'name' => 'Plume AI' ],
        ] );

        $this->assertCount( 2, $messages );
        $parts = $messages[1]['parts'];
        $this->assertCount( 1, $parts );
        $this->assertSame( 'c1', $parts[0]['functionResponse']['id'] );
        $this->assertSame( [ 'name' => 'Plume AI' ], $parts[0]['functionResponse']['response'] );
    }

    public function test_gemini_append_tool_exchange_handles_multiple_tool_calls(): void {
        $raw_data = [
            'data' => [
                'candidates' => [ [
                    'content' => [
                        'parts' => [
                            [ 'functionCall' => [ 'id' => 'c1', 'name' => 'get_recent_posts', 'args' => [] ] ],
                            [ 'functionCall' => [ 'id' => 'c2', 'name' => 'get_site_info', 'args' => [] ] ],
                        ],
                    ],
                ] ],
            ],
            'call_id' => 'c1',
        ];

        $response = new CompletionResponse(
            content: '',
            model: 'gemini-2.0-flash',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: $raw_data,
            tool_call: [ 'id' => 'c1', 'name' => 'get_recent_posts', 'arguments' => [] ],
        );

        $messages = $this->call_append_tool_exchange( [], 'gemini', $response, [
            'c1' => [ 'posts' => [] ],
            'c2' => [ 'name' => 'Plume AI' ],
        ] );

        // One model turn + one user turn with both responses.
        $this->assertCount( 2, $messages );
        $model_parts = $messages[0]['parts'];
        $this->assertCount( 2, $model_parts, 'Model turn must contain both functionCall parts' );

        $user_parts = $messages[1]['parts'];
        $this->assertCount( 2, $user_parts, 'User turn must contain both functionResponse parts' );
        $this->assertSame( 'c1', $user_parts[0]['functionResponse']['id'] );
        $this->assertSame( 'get_recent_posts', $user_parts[0]['functionResponse']['name'] );
        $this->assertSame( 'c2', $user_parts[1]['functionResponse']['id'] );
        $this->assertSame( 'get_site_info', $user_parts[1]['functionResponse']['name'] );
    }

    public function test_gemini_append_tool_exchange_matches_results_by_name_when_ids_missing(): void {
        // Real Gemini responses frequently omit functionCall ids.
        $raw_data = [
            'data' => [
                'candidates' => [ [
                    'content' => [
                        'parts' => [
                            [ 'functionCall' => [ 'name' => 'get_recent_posts', 'args' => [] ] ],
                            [ 'functionCall' => [ 'name' => 'get_site_info', 'args' => [] ] ],
                        ],
                    ],
                ] ],
            ],
            'call_id' => 'gemini_generated_1',
        ];

        $response = new CompletionResponse(
            content: '',
            model: 'gemini-2.0-flash',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: $raw_data,
            tool_call: [ 'id' => 'gemini_generated_1', 'name' => 'get_recent_posts', 'arguments' => [] ],
        );

        // extract_tool_calls keys results by name when the id is absent.
        $messages = $this->call_append_tool_exchange( [], 'gemini', $response, [
            'get_recent_posts' => [ 'posts' => [ [ 'id' => 1 ] ] ],
        ] );

        $user_parts = $messages[1]['parts'];
        $this->assertCount( 2, $user_parts );
        $this->assertSame( [ 'posts' => [ [ 'id' => 1 ] ] ], $user_parts[0]['functionResponse']['response'] );
        // A missing result must be encoded as a JSON object, never a JSON array.
        $this->assertInstanceOf( \stdClass::class, $user_parts[1]['functionResponse']['response'] );
    }

    // ── append_tool_exchange: OpenAI/Grok & Claude proxy writeback (#898/#899) ──

    public function test_openai_append_tool_exchange_writes_back_all_tool_calls(): void {
        // Proxy responses set raw['content'] to a string and carry every call in tool_calls (plural).
        $response = new CompletionResponse(
            content: '',
            model: 'gpt-4o',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: [
                'content'    => '',
                'tool_calls' => [
                    [ 'id' => 'call_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
                    [ 'id' => 'call_2', 'name' => 'get_site_info', 'arguments' => [] ],
                ],
            ],
            tool_call: [ 'id' => 'call_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
        );

        $messages = $this->call_append_tool_exchange( [], 'openai', $response, [
            'call_1' => [ 'posts' => [] ],
            'call_2' => [ 'name' => 'Plume AI' ],
        ] );

        // One assistant turn carrying both calls + one tool result message per id.
        $this->assertCount( 3, $messages );
        $this->assertSame( 'assistant', $messages[0]['role'] );
        $this->assertCount( 2, $messages[0]['tool_calls'], 'Assistant turn must declare both parallel tool calls' );
        $this->assertSame( 'call_1', $messages[0]['tool_calls'][0]['id'] );
        $this->assertSame( 'get_recent_posts', $messages[0]['tool_calls'][0]['function']['name'] );
        $this->assertSame( 'call_2', $messages[0]['tool_calls'][1]['id'] );
        $this->assertSame( 'get_site_info', $messages[0]['tool_calls'][1]['function']['name'] );

        $this->assertSame( 'tool', $messages[1]['role'] );
        $this->assertSame( 'call_1', $messages[1]['tool_call_id'] );
        $this->assertSame( \wp_json_encode( [ 'posts' => [] ] ), $messages[1]['content'] );
        $this->assertSame( 'tool', $messages[2]['role'] );
        $this->assertSame( 'call_2', $messages[2]['tool_call_id'] );
        $this->assertSame( \wp_json_encode( [ 'name' => 'Plume AI' ] ), $messages[2]['content'] );
    }

    public function test_claude_proxy_append_tool_exchange_reconstructs_all_tool_use_blocks(): void {
        // Proxy normalises raw['content'] to a flat string, so the tool_use blocks must be
        // reconstructed from the plural tool_calls array — one per executed call (#898).
        $response = new CompletionResponse(
            content: '',
            model: 'claude-3-5-sonnet',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: [
                'content'    => '',
                'tool_calls' => [
                    [ 'id' => 'tu_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
                    [ 'id' => 'tu_2', 'name' => 'get_site_info', 'arguments' => [] ],
                ],
            ],
            tool_call: [ 'id' => 'tu_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
        );

        $messages = $this->call_append_tool_exchange( [], 'claude', $response, [
            'tu_1' => [ 'posts' => [] ],
            'tu_2' => [ 'name' => 'Plume AI' ],
        ] );

        // One assistant turn with both tool_use blocks + one user turn with both tool_result blocks.
        $this->assertCount( 2, $messages );
        $assistant_blocks = $messages[0]['content'];
        $this->assertCount( 2, $assistant_blocks, 'Assistant turn must carry both reconstructed tool_use blocks' );
        $this->assertSame( 'tool_use', $assistant_blocks[0]['type'] );
        $this->assertSame( 'tu_1', $assistant_blocks[0]['id'] );
        $this->assertSame( 'get_recent_posts', $assistant_blocks[0]['name'] );
        $this->assertSame( 'tu_2', $assistant_blocks[1]['id'] );
        // An empty argument set must be encoded as a JSON object, never a JSON array.
        $this->assertInstanceOf( \stdClass::class, $assistant_blocks[1]['input'] );

        $result_blocks = $messages[1]['content'];
        $this->assertCount( 2, $result_blocks, 'User turn must carry both tool_result blocks' );
        $this->assertSame( 'tool_result', $result_blocks[0]['type'] );
        $this->assertSame( 'tu_1', $result_blocks[0]['tool_use_id'] );
        $this->assertSame( \wp_json_encode( [ 'posts' => [] ] ), $result_blocks[0]['content'] );
        $this->assertSame( 'tu_2', $result_blocks[1]['tool_use_id'] );
        $this->assertSame( \wp_json_encode( [ 'name' => 'Plume AI' ] ), $result_blocks[1]['content'] );
    }

    // ── extract_tool_calls ─────────────────────────────────────────────────────

    /**
     * Call the private extract_tool_calls method via reflection.
     */
    private function call_extract_tool_calls( CompletionResponse $response, string $provider_slug ): array {
        $method = new \ReflectionMethod( ChatRestController::class, 'extract_tool_calls' );
        $method->setAccessible( true );
        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );
        return $method->invoke( $controller, $response, $provider_slug );
    }

    public function test_extract_tool_calls_returns_all_gemini_function_calls(): void {
        $response = new CompletionResponse(
            content: '',
            model: 'gemini-2.0-flash',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: [
                'data' => [
                    'candidates' => [ [
                        'content' => [
                            'parts' => [
                                [ 'functionCall' => [ 'name' => 'get_recent_posts', 'args' => [ 'count' => 3 ] ] ],
                                [ 'text' => 'thinking…' ],
                                [ 'functionCall' => [ 'name' => 'get_site_info', 'args' => [] ] ],
                            ],
                        ],
                    ] ],
                ],
                'call_id' => 'gemini_generated_1',
            ],
            tool_call: [ 'id' => 'gemini_generated_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
        );

        $calls = $this->call_extract_tool_calls( $response, 'gemini' );

        $this->assertCount( 2, $calls, 'Both functionCall parts must be extracted for execution' );
        $this->assertSame( 'get_recent_posts', $calls[0]['name'] );
        $this->assertSame( [ 'count' => 3 ], $calls[0]['input'] );
        // Without a provider id, the name doubles as the result key.
        $this->assertSame( 'get_recent_posts', $calls[0]['id'] );
        $this->assertSame( 'get_site_info', $calls[1]['name'] );
        $this->assertSame( 'get_site_info', $calls[1]['id'] );
    }

    public function test_extract_tool_calls_falls_back_to_normalised_tool_call(): void {
        $response = new CompletionResponse(
            content: '',
            model: 'gemini-2.0-flash',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: [ 'call_id' => 'gemini_generated_1' ],
            tool_call: [ 'id' => 'gemini_generated_1', 'name' => 'get_site_info', 'arguments' => [] ],
        );

        $calls = $this->call_extract_tool_calls( $response, 'gemini' );

        $this->assertCount( 1, $calls );
        $this->assertSame( 'gemini_generated_1', $calls[0]['id'] );
        $this->assertSame( 'get_site_info', $calls[0]['name'] );
    }

    public function test_extract_tool_calls_returns_all_proxy_tool_calls(): void {
        // Proxy responses set raw['content'] to a string and carry every tool call in the
        // tool_calls (plural) array — all of them must execute in a single turn (#887).
        $response = new CompletionResponse(
            content: '',
            model: 'gpt-4o',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: [
                'content'    => '',
                'tool_calls' => [
                    [ 'id' => 'call_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
                    [ 'id' => 'call_2', 'name' => 'get_site_info', 'arguments' => [] ],
                ],
            ],
            tool_call: [ 'id' => 'call_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
        );

        $calls = $this->call_extract_tool_calls( $response, 'openai' );

        $this->assertCount( 2, $calls, 'Both proxy tool_calls must be extracted for execution' );
        $this->assertSame( 'call_1', $calls[0]['id'] );
        $this->assertSame( 'get_recent_posts', $calls[0]['name'] );
        $this->assertSame( [ 'count' => 3 ], $calls[0]['input'] );
        $this->assertSame( 'call_2', $calls[1]['id'] );
        $this->assertSame( 'get_site_info', $calls[1]['name'] );
    }

    public function test_extract_tool_calls_returns_all_claude_tool_use_blocks(): void {
        $response = new CompletionResponse(
            content: '',
            model: 'claude-3-5-sonnet',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: [
                'content' => [
                    [ 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_recent_posts', 'input' => [ 'count' => 3 ] ],
                    [ 'type' => 'text', 'text' => 'thinking…' ],
                    [ 'type' => 'tool_use', 'id' => 'tu_2', 'name' => 'get_site_info', 'input' => [] ],
                ],
            ],
            tool_call: [ 'id' => 'tu_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
        );

        $calls = $this->call_extract_tool_calls( $response, 'claude' );

        $this->assertCount( 2, $calls, 'Both tool_use blocks must be extracted; text blocks must be skipped' );
        $this->assertSame( 'tu_1', $calls[0]['id'] );
        $this->assertSame( 'get_recent_posts', $calls[0]['name'] );
        $this->assertSame( [ 'count' => 3 ], $calls[0]['input'] );
        $this->assertSame( 'tu_2', $calls[1]['id'] );
        $this->assertSame( 'get_site_info', $calls[1]['name'] );
    }

    public function test_extract_tool_calls_falls_back_to_tool_call_when_raw_is_empty(): void {
        $response = new CompletionResponse(
            content: '',
            model: 'claude-3-5-sonnet',
            prompt_tokens: 10,
            completion_tokens: 5,
            raw: [ 'content' => [] ],
            tool_call: [ 'id' => 'tc_1', 'name' => 'get_site_info', 'arguments' => [ 'extra' => 'val' ] ],
        );

        $calls = $this->call_extract_tool_calls( $response, 'claude' );

        $this->assertCount( 1, $calls );
        $this->assertSame( 'tc_1', $calls[0]['id'] );
        $this->assertSame( 'get_site_info', $calls[0]['name'] );
        $this->assertSame( [ 'extra' => 'val' ], $calls[0]['input'] );
    }

    // ── send_message: Gemini multi-tool execution ──────────────────────────────

    public function test_send_message_executes_all_gemini_tool_calls_in_one_turn(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'gemini' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Hello' ],
        ] );

        // Gemini requests two tools in one turn, omitting functionCall ids.
        $tool_response = new CompletionResponse(
            content:           '',
            model:             'gemini-2.0-flash',
            prompt_tokens:     10,
            completion_tokens: 5,
            cost_usd:          0.0,
            raw:               [
                'data' => [
                    'candidates' => [ [
                        'content' => [
                            'parts' => [
                                [ 'functionCall' => [ 'name' => 'get_recent_posts', 'args' => [ 'count' => 3 ] ] ],
                                [ 'functionCall' => [ 'name' => 'get_site_info', 'args' => [] ] ],
                            ],
                        ],
                    ] ],
                ],
                'call_id' => 'gemini_generated_1',
            ],
            tool_call:         [ 'id' => 'gemini_generated_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
        );

        $final_response = new CompletionResponse(
            content:           'Here is your site overview.',
            model:             'gemini-2.0-flash',
            prompt_tokens:     20,
            completion_tokens: 15,
            cost_usd:          0.0,
            raw:               [],
            tool_call:         null,
        );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [ [ 'functionDeclarations' => [] ] ] );

        $executed = [];
        $this->tool_executor->expects( $this->exactly( 2 ) )
            ->method( 'execute' )
            ->willReturnCallback( function ( string $name, array $args, int $user_id ) use ( &$executed ): array {
                $executed[] = $name;
                return [ 'ok' => true ];
            } );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturnOnConsecutiveCalls( $tool_response, $final_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Hello', 'provider' => 'gemini', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'get_recent_posts', 'get_site_info' ], $executed, 'Both Gemini tool calls must be executed' );
        $this->assertSame( 'Here is your site overview.', $response->data['content'] );
    }

    // ── send_message: chat_response extraction and pending_plan ───────────────

    public function test_send_message_uses_chat_response_message_as_final_content(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Hello' ],
        ] );

        $chat_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            cost_usd:          0.0,
            raw:               [
                'content' => [
                    [ 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'chat_response', 'input' => [ 'message' => 'Hi! How can I help?' ] ],
                ],
            ],
            tool_call:         [ 'id' => 'tu_1', 'name' => 'chat_response', 'arguments' => [ 'message' => 'Hi! How can I help?' ] ],
        );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [ [ 'name' => 'chat_response' ] ] );
        $this->tool_executor->expects( $this->never() )->method( 'execute' );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturn( $chat_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Hello', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'Hi! How can I help?', $response->data['content'] );
    }

    public function test_send_message_includes_pending_plan_when_plan_stored(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
        Functions\when( '__' )->alias( fn( $v ) => $v );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Write a post about widgets' ],
        ] );

        $plan_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            cost_usd:          0.0,
            raw:               [
                'content' => [
                    [ 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'plan_post', 'input' => [ 'title' => 'Widgets', 'content' => 'Full body.' ] ],
                ],
            ],
            tool_call:         [ 'id' => 'tu_1', 'name' => 'plan_post', 'arguments' => [ 'title' => 'Widgets', 'content' => 'Full body.' ] ],
        );

        $pending = [
            'id'          => 'abc12345',
            'status'      => 'pending_approval',
            'plan_type'   => 'create',
            'title'       => 'Widgets',
            'content'     => 'Full body.',
            'post_status' => 'draft',
        ];

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [ [ 'name' => 'plan_post' ] ] );
        $this->tool_executor->expects( $this->once() )
            ->method( 'execute' )
            ->with( 'plan_post', [ 'title' => 'Widgets', 'content' => 'Full body.' ], 1 )
            ->willReturn( $pending );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturn( $plan_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Write a post about widgets', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( "I've prepared the changes for your review.", $response->data['content'] );
        $this->assertSame( $pending, $response->data['pending_plan'], 'pending_plan must be surfaced in the REST response' );
        $this->assertContains( 'plan_post', $response->data['tools_called'] );
    }

    public function test_send_message_uses_analysis_as_content_when_plan_update_includes_it(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
        Functions\when( '__' )->alias( fn( $v ) => $v );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Please review and tighten this post' ],
        ] );

        $analysis_text = 'The intro buries the lede and the CTA is missing; tightening both.';
        $tool_input    = [
            'analysis'    => $analysis_text,
            'post_id'     => 7,
            'changes'     => 'Tightened intro, added CTA',
            'new_content' => 'Full updated body.',
        ];

        $plan_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            cost_usd:          0.0,
            raw:               [
                'content' => [
                    [ 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'plan_update', 'input' => $tool_input ],
                ],
            ],
            tool_call:         [ 'id' => 'tu_1', 'name' => 'plan_update', 'arguments' => $tool_input ],
        );

        $pending = [
            'id'          => 'def45678',
            'status'      => 'pending_approval',
            'plan_type'   => 'update',
            'post_id'     => 7,
            'changes'     => 'Tightened intro, added CTA',
            'new_content' => 'Full updated body.',
            'post_status' => '',
        ];

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [ [ 'name' => 'plan_update' ] ] );
        $this->tool_executor->expects( $this->once() )
            ->method( 'execute' )
            ->with( 'plan_update', $tool_input, 1 )
            ->willReturn( $pending );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturn( $plan_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Please review and tighten this post', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $analysis_text, $response->data['content'], 'analysis text must be surfaced as the reply, not the generic fallback' );
        $this->assertSame( $pending, $response->data['pending_plan'] );
        $this->assertContains( 'plan_update', $response->data['tools_called'] );
    }

    public function test_send_message_uses_analysis_as_content_when_plan_post_includes_it(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
        Functions\when( '__' )->alias( fn( $v ) => $v );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Write a post about widgets' ],
        ] );

        $analysis_text = 'You asked for a launch announcement, so I drafted one covering the key features.';
        $tool_input    = [
            'analysis' => $analysis_text,
            'title'    => 'Widgets',
            'content'  => 'Full body.',
        ];

        $plan_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            cost_usd:          0.0,
            raw:               [
                'content' => [
                    [ 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'plan_post', 'input' => $tool_input ],
                ],
            ],
            tool_call:         [ 'id' => 'tu_1', 'name' => 'plan_post', 'arguments' => $tool_input ],
        );

        $pending = [
            'id'          => 'abc12345',
            'status'      => 'pending_approval',
            'plan_type'   => 'create',
            'title'       => 'Widgets',
            'content'     => 'Full body.',
            'post_status' => 'draft',
        ];

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [ [ 'name' => 'plan_post' ] ] );
        $this->tool_executor->expects( $this->once() )
            ->method( 'execute' )
            ->with( 'plan_post', $tool_input, 1 )
            ->willReturn( $pending );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturn( $plan_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Write a post about widgets', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $analysis_text, $response->data['content'], 'analysis text must be surfaced as the reply, not the generic fallback' );
        $this->assertSame( $pending, $response->data['pending_plan'] );
        $this->assertContains( 'plan_post', $response->data['tools_called'] );
    }

    // ── Credit logging ────────────────────────────────────────────────────────

    public function test_send_message_logs_credits_once_after_loop(): void {
        // Verifies the single UsageTracker::log_usage() call added after the while loop.
        // A two-iteration exchange (tool call → final answer) must produce exactly one
        // DB write for credits, reflecting the final response's credits_charged value.
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->alias( fn( $key, $default = '' ) => match ( $key ) {
            'plume_default_provider' => 'claude',
            default                  => $default,
        } );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Hello' ],
        ] );

        // Iteration 1: tool call (3 credits from Worker, but ProxyClient skips logging).
        $tool_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            raw:               [ 'content' => [] ],
            tool_call:         [ 'id' => 'tc_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
            credits_charged:   3,
        );

        // Iteration 2: final answer (3 more credits — controller logs this value once).
        $final_response = new CompletionResponse(
            content:           'Final answer',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     20,
            completion_tokens: 15,
            credits_charged:   3,
        );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );
        $this->tool_executor->method( 'execute' )->willReturn( [ 'posts' => [] ] );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturnOnConsecutiveCalls( $tool_response, $final_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        // Use a Mockery $wpdb to assert exactly one DB write for credits and capture the value.
        global $wpdb;
        $original_wpdb       = $wpdb;
        $captured_credits    = null;
        $wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $wpdb->usermeta      = 'wp_usermeta';
        $wpdb->rows_affected = 1;
        // Capture the first prepared argument (the credit amount) so the test pins the
        // actual value written, not merely that a single query was issued.
        $wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
            function ( $sql, $credits ) use ( &$captured_credits ) {
                $captured_credits = $credits;
                return $sql;
            }
        );
        $wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Hello', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 3, $response->data['credits'], 'credits field must reflect final_response->credits_charged.' );
        $this->assertSame( 3, $captured_credits, 'log_usage must write final_response->credits_charged (3) to usermeta.' );
    }

    public function test_send_message_logs_only_final_iteration_credits_when_amounts_differ(): void {
        // Locks down the accounting rule (relates to #880): when intermediate and final
        // iterations charge different amounts, the controller must log exactly the final
        // response's credits_charged once — never the intermediate value, a sum, or 0.
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->alias( fn( $key, $default = '' ) => match ( $key ) {
            'plume_default_provider' => 'claude',
            default                  => $default,
        } );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        $store_mock = $this->createMock( \Plume\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 1 ] );
        $store_mock->method( 'get_messages' )->willReturn( [
            [ 'role' => 'user', 'content' => 'Hello' ],
        ] );

        // Iteration 1: tool call charging 3 credits — ProxyClient skips logging for chat.
        $tool_response = new CompletionResponse(
            content:           '',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     10,
            completion_tokens: 5,
            raw:               [ 'content' => [] ],
            tool_call:         [ 'id' => 'tc_1', 'name' => 'get_recent_posts', 'arguments' => [ 'count' => 3 ] ],
            credits_charged:   3,
        );

        // Iteration 2: final answer charging a DIFFERENT amount (7) — only this is logged.
        $final_response = new CompletionResponse(
            content:           'Final answer',
            model:             'claude-3-5-sonnet',
            prompt_tokens:     20,
            completion_tokens: 15,
            credits_charged:   7,
        );

        $this->tool_registry->method( 'get_for_provider' )->willReturn( [] );
        $this->tool_executor->method( 'execute' )->willReturn( [ 'posts' => [] ] );

        $provider_mock = $this->createMock( \Plume\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturnOnConsecutiveCalls( $tool_response, $final_response );

        $factory_mock = $this->createMock( \Plume\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \Plume\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        global $wpdb;
        $original_wpdb       = $wpdb;
        $wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $wpdb->usermeta      = 'wp_usermeta';
        $wpdb->rows_affected = 1;
        $logged_credits      = null;
        $wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
            function ( $sql, $credits = null ) use ( &$logged_credits ) {
                $logged_credits = $credits;
                return $sql;
            }
        );
        $wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

        $controller = $this->make_controller( $store_mock, $factory_mock, $voice_mock );

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Hello', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 7, $response->data['credits'], 'credits field must reflect the final iteration, not the intermediate 3.' );
        $this->assertSame( 7, $logged_credits, 'usermeta must record the final iteration amount (7), not the intermediate 3, their sum (10), or 0.' );
    }

    // ── search_posts ──────────────────────────────────────────────────────────

    /**
     * Helper: build a controller subclass with a stub post list for search_posts.
     *
     * @param object[] $posts
     * @return ChatRestController
     */
    private function make_searchable_controller( array $posts ): ChatRestController {
        return new class( $this->tool_registry, $this->tool_executor, $posts ) extends ChatRestController {
            private array $stub_posts;
            public function __construct( ToolRegistry $tr, ToolExecutor $te, array $posts ) {
                parent::__construct( $tr, $te );
                $this->stub_posts = $posts;
            }
            protected function run_post_query( array $args ): array {
                return $this->stub_posts;
            }
        };
    }

    public function test_search_posts_includes_edit_link_for_editor(): void {
        $post            = new \stdClass();
        $post->ID        = 42;
        $post->post_type = 'post';

        Functions\when( 'get_post_types' )->justReturn( [ 'post', 'page' ] );
        Functions\when( 'get_post_type_object' )->justReturn(
            (object) [ 'labels' => (object) [ 'singular_name' => 'Post' ] ]
        );
        Functions\when( 'get_the_title' )->justReturn( 'My Post' );
        Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.com/wp-admin/post.php?post=42&action=edit' );

        $controller = $this->make_searchable_controller( [ $post ] );

        $request = new \WP_REST_Request( 'GET' );
        $request->set_param( 'q', 'my' );

        $response = $controller->search_posts( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertCount( 1, $response->data );
        $item = $response->data[0];
        $this->assertArrayHasKey( 'edit_link', $item );
        $this->assertSame(
            'https://example.com/wp-admin/post.php?post=42&action=edit',
            $item['edit_link'],
            'edit_link must contain the edit URL when the current user can edit the post.'
        );
    }

    public function test_search_posts_edit_link_empty_for_subscriber(): void {
        $post            = new \stdClass();
        $post->ID        = 99;
        $post->post_type = 'post';

        Functions\when( 'get_post_types' )->justReturn( [ 'post', 'page' ] );
        Functions\when( 'get_post_type_object' )->justReturn(
            (object) [ 'labels' => (object) [ 'singular_name' => 'Post' ] ]
        );
        Functions\when( 'get_the_title' )->justReturn( 'Subscriber Post' );
        // get_edit_post_link() returns null when the user has no edit permission.
        Functions\when( 'get_edit_post_link' )->justReturn( null );

        $controller = $this->make_searchable_controller( [ $post ] );

        $request = new \WP_REST_Request( 'GET' );
        $request->set_param( 'q', 'post' );

        $response = $controller->search_posts( $request );

        $item = $response->data[0];
        $this->assertArrayHasKey( 'edit_link', $item );
        $this->assertSame( '', $item['edit_link'], 'edit_link must fall back to empty string when the user cannot edit the post.' );
    }
}
