<?php
namespace WP_AI_Mind\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Providers\ClaudeProvider;
use WP_AI_Mind\Providers\CompletionRequest;
use WP_AI_Mind\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

class ClaudeProviderTest extends TestCase {

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
		// Return 'pro_byok' so routing goes direct — preserving existing test behaviour.
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
	}

	public function test_get_slug_returns_claude(): void {
		$provider = new ClaudeProvider( 'sk-ant-test' );
		$this->assertSame( 'claude', $provider->get_slug() );
	}

	public function test_is_available_false_without_key(): void {
		// No API key and user is pro_byok (not proxy-routed) → unavailable.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		$provider = new ClaudeProvider( '' );
		$this->assertFalse( $provider->is_available() );
	}

	public function test_is_available_true_with_key(): void {
		$provider = new ClaudeProvider( 'sk-ant-test' );
		$this->assertTrue( $provider->is_available() );
	}

	public function test_is_available_true_for_proxy_tier_with_registered_site(): void {
		// No API key, free tier, site registered → proxy-routed users are available.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_option' )->justReturn( 'some-token' );
		$provider = new ClaudeProvider( '' );
		$this->assertTrue( $provider->is_available() );
	}

	public function test_is_available_false_for_proxy_tier_without_registration(): void {
		// No API key, free tier, but site not registered → unavailable.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_option' )->justReturn( '' );
		$provider = new ClaudeProvider( '' );
		$this->assertFalse( $provider->is_available() );
	}

	public function test_complete_parses_response(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => [ [ 'type' => 'text', 'text' => 'Hello world' ] ],
				'model'   => 'claude-sonnet-4-6',
				'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );
		$this->mock_wpdb();

		$provider = new ClaudeProvider( 'sk-ant-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertSame( 'Hello world', $response->content );
		$this->assertSame( 10, $response->prompt_tokens );
	}

	public function test_complete_throws_on_api_error(): void {
		// Stub tier resolution so routing goes direct (pro_byok path).
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );

		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 401 ],
			'body'     => json_encode( [ 'error' => [ 'message' => 'Unauthorised' ] ] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );

		$provider = new ClaudeProvider( 'sk-ant-bad-key' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$this->expectException( ProviderException::class );
		$provider->complete( $request );
	}

	public function test_tool_use_response_returns_tool_call(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => [
					[
						'type'  => 'tool_use',
						'id'    => 'toolu_01',
						'name'  => 'get_recent_posts',
						'input' => [ 'count' => 5 ],
					],
				],
				'model'   => 'claude-sonnet-4-6',
				'usage'   => [ 'input_tokens' => 20, 'output_tokens' => 8 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );
		$this->mock_wpdb();

		$provider = new ClaudeProvider( 'sk-ant-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'list posts' ] ] );
		$response = $provider->complete( $request );

		$this->assertTrue( $response->is_tool_call() );
		$this->assertSame( 'get_recent_posts', $response->tool_call['name'] );
		$this->assertSame( 'toolu_01', $response->tool_call['id'] );
		$this->assertSame( [ 'count' => 5 ], $response->tool_call['arguments'] );
		$this->assertSame( '', $response->content );
	}

	public function test_normal_response_not_tool_call(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => [ [ 'type' => 'text', 'text' => 'Hello world' ] ],
				'model'   => 'claude-sonnet-4-6',
				'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );
		$this->mock_wpdb();

		$provider = new ClaudeProvider( 'sk-ant-test' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );

		$this->assertFalse( $response->is_tool_call() );
		$this->assertNull( $response->tool_call );
	}

	public function test_complete_routes_free_tier_to_proxy_returns_error_when_not_registered(): void {
		// Mock get_current_user_id — called by NJ_Tier_Manager::get_user_tier() and NJ_Proxy_Client::chat().
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		// Mock get_user_meta to return 'free' tier (called by NJ_Tier_Manager::get_user_tier()).
		Functions\when( 'get_user_meta' )
			->justReturn( 'free' );

		// Mock get_option to return empty token (site not registered — called by NJ_Site_Registration::get_site_token()).
		Functions\when( 'get_option' )
			->justReturn( '' );

		// is_wp_error must return true when passed the WP_Error returned by NJ_Proxy_Client::chat().
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );

		// Stub translation functions used inside NJ_Proxy_Client::chat().
		Functions\stubs( [ '__' => fn( $str ) => $str ] );

		$provider = new ClaudeProvider( 'test-api-key' );
		$request  = new CompletionRequest(
			messages: [ [ 'role' => 'user', 'content' => 'Hi' ] ],
			max_tokens: 100,
		);

		// For a free-tier user, do_complete() routes through NJ_Proxy_Client.
		// NJ_Proxy_Client::chat() returns WP_Error('not_registered') when site token is missing.
		// ClaudeProvider converts this WP_Error to a ProviderException.
		$this->expectException( ProviderException::class );
		$provider->complete( $request );
	}

	public function test_complete_routes_pro_byok_direct_not_via_proxy(): void {
		// Mock get_current_user_id — called by NJ_Tier_Manager::get_user_tier().
		Functions\when( 'get_current_user_id' )->justReturn( 2 );

		// Mock get_user_meta to return 'pro_byok' tier.
		Functions\when( 'get_user_meta' )
			->justReturn( 'pro_byok' );

		// pro_byok users route to parent::do_complete() which calls wp_remote_post directly.
		// Stub wp_remote_post to return a valid response.
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => [ [ 'type' => 'text', 'text' => 'Direct API response' ] ],
				'model'   => 'claude-sonnet-4-6',
				'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		$this->mock_wpdb();

		$provider = new ClaudeProvider( 'sk-ant-byok-key' );
		$request  = new CompletionRequest(
			messages: [ [ 'role' => 'user', 'content' => 'Hi' ] ],
			max_tokens: 100,
		);

		$response = $provider->complete( $request );

		// Verify it returned a direct API result (not a proxy result).
		$this->assertSame( 'Direct API response', $response->content );
	}

	public function test_tools_injected_in_request_body(): void {
		$captured_body = null;
		Functions\when( 'wp_remote_post' )->alias( function( $url, $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return [
				'response' => [ 'code' => 200 ],
				'body'     => json_encode( [
					'content' => [ [ 'type' => 'text', 'text' => 'done' ] ],
					'model'   => 'claude-sonnet-4-6',
					'usage'   => [ 'input_tokens' => 5, 'output_tokens' => 2 ],
				] ),
			];
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn($v) => json_encode($v) );
		$this->mock_wpdb();

		$tools    = [ [ 'name' => 'get_recent_posts', 'description' => 'Fetches recent posts.' ] ];
		$provider = new ClaudeProvider( 'sk-ant-test' );
		$request  = new CompletionRequest(
			messages: [ [ 'role' => 'user', 'content' => 'list posts' ] ],
			tools: $tools,
		);
		$provider->complete( $request );

		$this->assertArrayHasKey( 'tools', $captured_body );
		$this->assertSame( $tools, $captured_body['tools'] );
	}
}
