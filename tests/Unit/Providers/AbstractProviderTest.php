<?php
namespace WP_AI_Mind\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Providers\AbstractProvider;
use WP_AI_Mind\Providers\CompletionRequest;
use WP_AI_Mind\Providers\CompletionResponse;
use WP_AI_Mind\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

class AbstractProviderTest extends TestCase {

	protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	public function test_with_retry_succeeds_on_first_attempt(): void {
		$this->mock_usage_logger();
		$provider = $this->make_provider( 0 );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );
		$this->assertSame( 'hello', $response->content );
	}

	public function test_with_retry_retries_on_retryable_error_and_succeeds(): void {
		$this->mock_usage_logger();
		$provider = $this->make_provider( 1 );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$response = $provider->complete( $request );
		$this->assertSame( 'hello', $response->content );
	}

	public function test_with_retry_throws_after_max_retries(): void {
		$provider = $this->make_provider( 5 );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] );
		$this->expectException( ProviderException::class );
		$provider->complete( $request );
	}

	private function mock_usage_logger(): void {
		global $wpdb;
		$wpdb = new \stdClass();
		$wpdb->prefix = 'wp_';
		// Mock wpdb->insert() method.
		$wpdb->insert = function() { return 1; };
		// Allow the method to be called via __call if needed.
		$wpdb = new class extends \stdClass {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public string $prefix        = 'wp_';
			public function insert(): int { return 1; }
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { return 1; }
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
	}

	private function make_provider( int $failures_before_success ): AbstractProvider {
		return new class( $failures_before_success ) extends AbstractProvider {
			private int $calls = 0;
			public function __construct( private int $fail_count ) {}
			public function get_slug(): string         { return 'test'; }
			public function get_models(): array        { return []; }
			public function get_default_model(): string { return 'test-model'; }
			public function is_available(): bool       { return true; }
			public function generate_image( string $p, array $o = [] ): int { return 0; }
			protected function do_complete( CompletionRequest $r ): CompletionResponse {
				if ( $this->calls++ < $this->fail_count ) {
					throw new ProviderException( 'rate limit', 'test', 429 );
				}
				return new CompletionResponse( 'hello', 'test-model', 10, 5 );
			}
			protected function do_stream( CompletionRequest $r, callable $cb ): CompletionResponse {
				$cb( 'hello' );
				return new CompletionResponse( 'hello', 'test-model', 10, 5 );
			}
		};
	}
}
