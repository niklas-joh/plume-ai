<?php
namespace Stilus\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Stilus\Providers\GeminiProvider;
use Stilus\Providers\CompletionRequest;
use Stilus\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

class GeminiProviderTest extends TestCase {

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
			public string $prefix        = 'wpaim_';
			public function insert(): int { return 1; }
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { return 1; }
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Return 'pro_byok' so do_complete() routes direct — preserving existing test behaviour.
		// Tier is now site-level, so we stub the SITE_OPTION not user meta.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'stilus_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
	}

	public function test_get_slug_returns_gemini(): void {
		$this->assertSame( 'gemini', ( new GeminiProvider( 'AIza-test' ) )->get_slug() );
	}

	public function test_is_available_false_without_key(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
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
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'stilus_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
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

	// ── Proxy path tests ─────────────────────────────────────────────────────

	private function mock_free_tier_proxy(): void {
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
		// First call returns the tier ('free'), subsequent calls return 0 for usage count.
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
		$this->assertTrue( ( new GeminiProvider( '' ) )->is_available() );
	}

	public function test_complete_via_proxy_happy_path(): void {
		$this->mock_free_tier_proxy();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => 'Gemini proxy says hi',
				'usage'   => [ 'input_tokens' => 5, 'output_tokens' => 3 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new GeminiProvider( '' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertSame( 'Gemini proxy says hi', $response->content );
		$this->assertSame( 5, $response->prompt_tokens );
		$this->assertSame( 3, $response->completion_tokens );
		$this->assertFalse( $response->is_tool_call() );
	}

	public function test_complete_via_proxy_tool_call(): void {
		$this->mock_free_tier_proxy();
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content'   => '',
				'usage'     => [ 'input_tokens' => 10, 'output_tokens' => 4 ],
				'tool_call' => [ 'id' => 'gemini_123', 'name' => 'get_posts', 'arguments' => [ 'count' => 3 ] ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new GeminiProvider( '' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'list posts' ] ] );
		$response = $provider->complete( $request );

		$this->assertTrue( $response->is_tool_call() );
		$this->assertSame( 'get_posts', $response->tool_call['name'] );
		$this->assertSame( [ 'count' => 3 ], $response->tool_call['arguments'] );
	}

	public function test_complete_via_proxy_wp_error_throws_provider_exception(): void {
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
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_option' )->justReturn( 'test-site-token' );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		// Simulate wp_remote_post returning a WP_Error (e.g. connection refused).
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'Connection refused' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$provider = new GeminiProvider( '' );
		$this->expectException( ProviderException::class );
		$provider->complete( new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] ) );
	}

	public function test_proxy_logged_suppresses_parent_log(): void {
		$query_count = 0;
		global $wpdb;
		$wpdb = new class( $query_count ) extends \stdClass {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public string $prefix        = 'wpaim_';
			public int    $query_calls   = 0;
			public function __construct( int $dummy ) {}
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
				'content' => 'ok',
				'usage'   => [ 'input_tokens' => 2, 'output_tokens' => 1 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		$provider = new GeminiProvider( '' );
		$provider->complete( new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] ) );

		// ProxyClient::chat() logs usage once (one wpdb->query call).
		// proxy_logged flag must prevent parent::maybe_log() from logging a second time.
		$this->assertSame( 1, $wpdb->query_calls );
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
