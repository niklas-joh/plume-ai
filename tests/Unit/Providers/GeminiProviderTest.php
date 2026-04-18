<?php
namespace WP_AI_Mind\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Providers\GeminiProvider;
use WP_AI_Mind\Providers\CompletionRequest;
use WP_AI_Mind\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

class GeminiProviderTest extends TestCase {

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

	public function test_get_slug_returns_gemini(): void {
		$this->assertSame( 'gemini', ( new GeminiProvider( 'AIza-test' ) )->get_slug() );
	}

	public function test_is_available_false_without_key(): void {
		$this->assertFalse( ( new GeminiProvider( '' ) )->is_available() );
	}

	public function test_complete_parses_response(): void {
		$this->mock_wpdb();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'candidates'    => [ [ 'content' => [ 'parts' => [ [ 'text' => 'Gemini says hi' ] ] ] ] ],
				'usageMetadata' => [ 'promptTokenCount' => 5, 'candidatesTokenCount' => 3 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new GeminiProvider( 'AIza-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertSame( 'Gemini says hi', $response->content );
		$this->assertSame( 5, $response->prompt_tokens );
	}

	public function test_complete_throws_on_api_error(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 403 ],
			'body'     => json_encode( [ 'error' => [ 'message' => 'API key invalid' ] ] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new GeminiProvider( 'bad-key' );
		$this->expectException( ProviderException::class );
		$provider->complete( new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] ) );
	}

	public function test_function_call_response_detected(): void {
		$this->mock_wpdb();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'candidates' => [
					[
						'content' => [
							'parts' => [
								[
									'functionCall' => [
										'name' => 'get_recent_posts',
										'args' => [ 'count' => 5 ],
									],
								],
							],
						],
					],
				],
				'modelVersion'  => 'gemini-2.5-pro',
				'usageMetadata' => [ 'promptTokenCount' => 10, 'candidatesTokenCount' => 4 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new GeminiProvider( 'AIza-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'list posts' ] ] );
		$response = $provider->complete( $request );

		$this->assertTrue( $response->is_tool_call() );
		$this->assertSame( 'get_recent_posts', $response->tool_call['name'] );
		$this->assertSame( [ 'count' => 5 ], $response->tool_call['arguments'] );
		// The generated call_id must be preserved in raw for history reconstruction.
		$this->assertArrayHasKey( 'call_id', $response->raw );
		$this->assertSame( $response->tool_call['id'], $response->raw['call_id'] );
		$this->assertSame( '', $response->content );
	}

	public function test_normal_response_not_tool_call(): void {
		$this->mock_wpdb();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'candidates'    => [ [ 'content' => [ 'parts' => [ [ 'text' => 'Gemini says hi' ] ] ] ] ],
				'usageMetadata' => [ 'promptTokenCount' => 5, 'candidatesTokenCount' => 3 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new GeminiProvider( 'AIza-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertFalse( $response->is_tool_call() );
		$this->assertNull( $response->tool_call );
	}
}
