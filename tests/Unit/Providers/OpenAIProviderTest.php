<?php
namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Providers\OpenAIProvider;
use Plume\Providers\CompletionRequest;
use Plume\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

class OpenAIProviderTest extends TestCase {

	protected function setUp(): void    {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
	}
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	private function mock_wpdb(): void {
		global $wpdb;
		$wpdb = new class extends \stdClass {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public string $prefix        = 'plume_';
			public function insert(): int { return 1; }
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { return 1; }
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Tier is now site-level, so we stub the SITE_OPTION not user meta.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
	}

	public function test_get_slug_returns_openai(): void {
		$provider = new OpenAIProvider( 'sk-test' );
		$this->assertSame( 'openai', $provider->get_slug() );
	}

	public function test_is_available_false_without_key(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		$this->assertFalse( ( new OpenAIProvider( '' ) )->is_available() );
	}

	public function test_complete_parses_chat_response(): void {
		$this->mock_wpdb();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'choices' => [ [ 'message' => [ 'content' => 'Hello from GPT' ] ] ],
				'usage'   => [ 'prompt_tokens' => 8, 'completion_tokens' => 4 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new OpenAIProvider( 'sk-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertSame( 'Hello from GPT', $response->content );
		$this->assertSame( 8, $response->prompt_tokens );
	}

	public function test_complete_throws_on_api_error(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 401 ],
			'body'     => json_encode( [ 'error' => [ 'message' => 'Invalid API key' ] ] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new OpenAIProvider( 'sk-bad' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$this->expectException( ProviderException::class );
		$provider->complete( $request );
	}

	public function test_tool_calls_response_detected(): void {
		$this->mock_wpdb();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'choices' => [
					[
						'finish_reason' => 'tool_calls',
						'message'       => [
							'role'       => 'assistant',
							'tool_calls' => [
								[
									'id'       => 'call_abc123',
									'type'     => 'function',
									'function' => [
										'name'      => 'get_recent_posts',
										'arguments' => json_encode( [ 'count' => 5 ] ),
									],
								],
							],
						],
					],
				],
				'model' => 'gpt-4o',
				'usage' => [ 'prompt_tokens' => 15, 'completion_tokens' => 6 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new OpenAIProvider( 'sk-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'list posts' ] ] );
		$response = $provider->complete( $request );

		$this->assertTrue( $response->is_tool_call() );
		$this->assertSame( 'get_recent_posts', $response->tool_call['name'] );
		$this->assertSame( 'call_abc123', $response->tool_call['id'] );
		$this->assertSame( [ 'count' => 5 ], $response->tool_call['arguments'] );
		$this->assertSame( '', $response->content );
	}

	// ── Proxy path tests ─────────────────────────────────────────────────────

	private function mock_free_tier_proxy(): void {
		global $wpdb;
		$wpdb = new class extends \stdClass {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public string $prefix        = 'plume_';
			public function insert(): int { return 1; }
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { return 1; }
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_option' )->justReturn( 'test-site-token' );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	public function test_is_available_true_for_free_tier_when_registered(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_option' )->justReturn( 'test-site-token' );
		$this->assertTrue( ( new OpenAIProvider( '' ) )->is_available() );
	}

	public function test_complete_via_proxy_happy_path(): void {
		$this->mock_free_tier_proxy();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => 'OpenAI proxy says hi',
				'usage'   => [ 'input_tokens' => 8, 'output_tokens' => 4 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new OpenAIProvider( '' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertSame( 'OpenAI proxy says hi', $response->content );
		$this->assertSame( 8, $response->prompt_tokens );
		$this->assertSame( 4, $response->completion_tokens );
		$this->assertFalse( $response->is_tool_call() );
	}

	public function test_complete_via_proxy_tool_call(): void {
		$this->mock_free_tier_proxy();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content'    => '',
				'usage'      => [ 'input_tokens' => 15, 'output_tokens' => 6 ],
				'tool_calls' => [
					[ 'id' => 'call_abc123', 'name' => 'get_posts', 'arguments' => [ 'count' => 5 ] ],
				],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new OpenAIProvider( '' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'list posts' ] ] );
		$response = $provider->complete( $request );

		// The Worker now emits tool_calls (plural); the provider exposes the first entry.
		$this->assertTrue( $response->is_tool_call() );
		$this->assertSame( 'get_posts', $response->tool_call['name'] );
		$this->assertSame( 'call_abc123', $response->tool_call['id'] );
		$this->assertSame( [ 'count' => 5 ], $response->tool_call['arguments'] );
	}

	public function test_complete_via_proxy_tool_call_singular_fallback(): void {
		// Backward-compat: any in-flight response may still carry the old singular tool_call shape.
		$this->mock_free_tier_proxy();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content'   => '',
				'usage'     => [ 'input_tokens' => 15, 'output_tokens' => 6 ],
				'tool_call' => [ 'id' => 'call_abc123', 'name' => 'get_posts', 'arguments' => [ 'count' => 5 ] ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new OpenAIProvider( '' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'list posts' ] ] );
		$response = $provider->complete( $request );

		$this->assertTrue( $response->is_tool_call() );
		$this->assertSame( 'get_posts', $response->tool_call['name'] );
		$this->assertSame( 'call_abc123', $response->tool_call['id'] );
		$this->assertSame( [ 'count' => 5 ], $response->tool_call['arguments'] );
	}

	public function test_complete_via_proxy_forwards_feature_from_request_metadata(): void {
		$this->mock_free_tier_proxy();
		$captured_body = null;
		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return [
				'response' => [ 'code' => 200 ],
				'body'     => json_encode( [
					'content' => 'ok',
					'usage'   => [ 'input_tokens' => 1, 'output_tokens' => 1 ],
				] ),
			];
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new OpenAIProvider( '' );
		$request  = new CompletionRequest(
			messages: [ [ 'role' => 'user', 'content' => 'Write a meta description' ] ],
			metadata: [ 'feature' => 'seo' ],
		);
		$provider->complete( $request );

		$this->assertNotNull( $captured_body );
		$this->assertSame( 'seo', $captured_body['feature'] );
	}

	public function test_complete_via_proxy_defaults_feature_to_chat_when_metadata_absent(): void {
		$this->mock_free_tier_proxy();
		$captured_body = null;
		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return [
				'response' => [ 'code' => 200 ],
				'body'     => json_encode( [
					'content' => 'ok',
					'usage'   => [ 'input_tokens' => 1, 'output_tokens' => 1 ],
				] ),
			];
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new OpenAIProvider( '' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$provider->complete( $request );

		$this->assertNotNull( $captured_body );
		$this->assertSame( 'chat', $captured_body['feature'] );
	}

	public function test_complete_via_proxy_wp_error_throws_provider_exception(): void {
		global $wpdb;
		$wpdb = new class extends \stdClass {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public string $prefix        = 'plume_';
			public function insert(): int { return 1; }
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { return 1; }
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_option' )->justReturn( 'test-site-token' );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'Connection refused' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$provider = new OpenAIProvider( '' );
		$this->expectException( ProviderException::class );
		$provider->complete( new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] ) );
	}

	public function test_proxy_logged_suppresses_parent_log(): void {
		global $wpdb;
		$wpdb = new class extends \stdClass {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public string $prefix        = 'plume_';
			public int    $query_calls   = 0;
			public function __construct() {}
			public function insert(): int { return 1; }
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { $this->query_calls++; return 1; }
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_option' )->justReturn( 'test-site-token' );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content'         => 'ok',
				'usage'           => [ 'input_tokens' => 2, 'output_tokens' => 1 ],
				'credits_charged' => 1,
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new OpenAIProvider( '' );
		// Use a non-chat feature so ProxyClient logs the credit; the proxy_logged flag must
		// then prevent maybe_log() from issuing a second query (chat credits skip ProxyClient
		// logging and are recorded once by ChatRestController instead).
		$provider->complete( new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ], metadata: [ 'feature' => 'seo' ] ) );

		// ProxyClient::chat() logs once; proxy_logged suppresses the parent maybe_log() call.
		$this->assertSame( 1, $wpdb->query_calls );
	}

	public function test_normal_response_not_tool_call(): void {
		$this->mock_wpdb();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'choices' => [ [ 'message' => [ 'content' => 'Hello from GPT' ] ] ],
				'usage'   => [ 'prompt_tokens' => 8, 'completion_tokens' => 4 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new OpenAIProvider( 'sk-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertFalse( $response->is_tool_call() );
		$this->assertNull( $response->tool_call );
	}
}
