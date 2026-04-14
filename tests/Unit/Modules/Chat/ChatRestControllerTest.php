<?php
namespace WP_AI_Mind\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Modules\Chat\ChatRestController;
use WP_AI_Mind\Tools\ToolRegistry;
use WP_AI_Mind\Tools\ToolExecutor;
use WP_AI_Mind\Providers\CompletionResponse;
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

    // ── list_conversations ────────────────────────────────────────────────────

    public function test_list_conversations_returns_only_expected_keys(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        // Store returns rows with extra internal columns that must not be exposed.
        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
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
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
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

        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
        $store_mock->method( 'list_for_user' )->willReturn( [
            [ 'id' => '99', 'title' => 'Test', 'updated_at' => '2026-02-01 00:00:00', 'user_id' => '1' ],
        ] );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
                return $this->store_override;
            }
        };

        $request  = new \WP_REST_Request( 'GET' );
        $response = $controller->list_conversations( $request );

        $this->assertSame( 99, $response->data[0]['id'], 'id must be cast to int, not returned as a string.' );
    }

    public function test_list_conversations_returns_empty_array_when_no_conversations(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
        $store_mock->method( 'list_for_user' )->willReturn( [] );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
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
        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 7 );
        $store_mock->method( 'get_conversation' )->with( 7 )->willReturn(
            [ 'id' => 7, 'title' => 'My convo', 'updated_at' => '2026-01-01 00:00:00' ]
        );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
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

        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 0 );
        $store_mock->method( 'get_conversation' )->willReturn( null );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
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

        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
        $store_mock->method( 'create' )->willReturn( 7 );
        $store_mock->method( 'get_conversation' )->with( 7 )->willReturn( null );

        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
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

        $this->assertContains( 'wp-ai-mind/v1/conversations', $registered );
        $this->assertContains( 'wp-ai-mind/v1/conversations/(?P<id>\\d+)/messages', $registered );
        $this->assertContains( 'wp-ai-mind/v1/providers', $registered );
    }

    // ── Permission check ───────────────────────────────────────────────────────

    public function test_permission_check_fails_for_non_editors(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( '__' )->alias( fn( $s ) => $s );
        $controller = new ChatRestController( $this->tool_registry, $this->tool_executor );

        $result = $controller->check_permission();
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    // ── Ownership guard ────────────────────────────────────────────────────────

    public function test_send_message_returns_403_when_conversation_not_owned(): void {
        // Arrange: conversation belongs to user 999, but current user is 1.
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );

        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
        $store_mock->method( 'get_conversation' )->willReturn( [ 'user_id' => 999 ] );

        // Use an anonymous subclass to inject the store mock.
        $controller = new class( $this->tool_registry, $this->tool_executor, $store_mock ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store
            ) {
                parent::__construct( $tr, $te );
                $this->store_override = $store;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
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
                'wp_ai_mind_default_provider' => 'claude',
                default                       => $default,
            };
        } );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        // Store mock.
        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
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
        $provider_mock = $this->createMock( \WP_AI_Mind\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        $provider_mock->method( 'complete' )->willReturnOnConsecutiveCalls( $tool_response, $final_response );

        $factory_mock = $this->createMock( \WP_AI_Mind\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \WP_AI_Mind\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = new class(
            $this->tool_registry,
            $this->tool_executor,
            $store_mock,
            $factory_mock,
            $voice_mock
        ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            private \WP_AI_Mind\Providers\ProviderFactory $factory_override;
            private \WP_AI_Mind\Voice\VoiceInjector $voice_override;

            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store,
                \WP_AI_Mind\Providers\ProviderFactory $factory,
                \WP_AI_Mind\Voice\VoiceInjector $voice
            ) {
                parent::__construct( $tr, $te );
                $this->store_override   = $store;
                $this->factory_override = $factory;
                $this->voice_override   = $voice;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
                return $this->store_override;
            }
            protected function make_provider_factory(): \WP_AI_Mind\Providers\ProviderFactory {
                return $this->factory_override;
            }
            protected function make_voice_injector(): \WP_AI_Mind\Voice\VoiceInjector {
                return $this->voice_override;
            }
        };

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '42' ] );
        $request->set_body_params( [ 'content' => 'Hello', 'provider' => 'claude', 'model' => 'claude-3-5-sonnet' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'Final answer', $response->data['content'] );
    }

    public function test_send_message_returns_500_after_max_iterations(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
        Functions\when( 'get_option' )->justReturn( 'claude' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );

        $store_mock = $this->createMock( \WP_AI_Mind\DB\ConversationStore::class );
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

        $provider_mock = $this->createMock( \WP_AI_Mind\Providers\ProviderInterface::class );
        $provider_mock->method( 'is_available' )->willReturn( true );
        $provider_mock->method( 'supports_tools' )->willReturn( true );
        // Always returns a tool call response.
        $provider_mock->method( 'complete' )->willReturn( $tool_response );

        $factory_mock = $this->createMock( \WP_AI_Mind\Providers\ProviderFactory::class );
        $factory_mock->method( 'make' )->willReturn( $provider_mock );

        $voice_mock = $this->createMock( \WP_AI_Mind\Voice\VoiceInjector::class );
        $voice_mock->method( 'build_system_prompt' )->willReturn( '' );

        $controller = new class(
            $this->tool_registry,
            $this->tool_executor,
            $store_mock,
            $factory_mock,
            $voice_mock
        ) extends ChatRestController {
            private \WP_AI_Mind\DB\ConversationStore $store_override;
            private \WP_AI_Mind\Providers\ProviderFactory $factory_override;
            private \WP_AI_Mind\Voice\VoiceInjector $voice_override;

            public function __construct(
                ToolRegistry $tr,
                ToolExecutor $te,
                \WP_AI_Mind\DB\ConversationStore $store,
                \WP_AI_Mind\Providers\ProviderFactory $factory,
                \WP_AI_Mind\Voice\VoiceInjector $voice
            ) {
                parent::__construct( $tr, $te );
                $this->store_override   = $store;
                $this->factory_override = $factory;
                $this->voice_override   = $voice;
            }
            protected function make_store(): \WP_AI_Mind\DB\ConversationStore {
                return $this->store_override;
            }
            protected function make_provider_factory(): \WP_AI_Mind\Providers\ProviderFactory {
                return $this->factory_override;
            }
            protected function make_voice_injector(): \WP_AI_Mind\Voice\VoiceInjector {
                return $this->voice_override;
            }
        };

        $request = new \WP_REST_Request( 'POST' );
        $request->set_url_params( [ 'id' => '99' ] );
        $request->set_body_params( [ 'content' => 'Hi', 'provider' => 'claude', 'model' => '' ] );

        $response = $controller->send_message( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 500, $response->get_status() );
        $this->assertStringContainsString( 'limit', $response->data['message'] );
    }
}
