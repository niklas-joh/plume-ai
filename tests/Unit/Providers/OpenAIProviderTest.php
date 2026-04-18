<?php
namespace WP_AI_Mind\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Providers\OpenAIProvider;
use WP_AI_Mind\Providers\CompletionRequest;
use WP_AI_Mind\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

class OpenAIProviderTest extends TestCase {

	protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	private function mock_wpdb(): void {
		global $wpdb;
		$wpdb = new class extends \stdClass {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public string $prefix        = 'wpaim_';
			public function insert(): int { return 1; }
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { return 1; }
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
	}

	public function test_get_slug_returns_openai(): void {
		$provider = new OpenAIProvider( 'sk-test' );
		$this->assertSame( 'openai', $provider->get_slug() );
	}

	public function test_is_available_false_without_key(): void {
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
