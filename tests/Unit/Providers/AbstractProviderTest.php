<?php
namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Providers\AbstractProvider;
use Plume\Providers\CompletionRequest;
use Plume\Providers\CompletionResponse;
use Plume\Providers\ProviderException;
use Plume\Telemetry\Telemetry;
use Plume\Tests\Helpers\TelemetryHarness;
use PHPUnit\Framework\TestCase;

class AbstractProviderTest extends TestCase {

	protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Telemetry::reset(); Monkey\tearDown(); parent::tearDown(); }

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

	public function test_build_system_field_returns_plain_string_by_default(): void {
		$provider = $this->make_provider( 0 );
		$result   = ( new \ReflectionMethod( $provider, 'build_system_field' ) )
			->invoke( $provider, 'You are a helpful assistant.' );
		$this->assertSame( 'You are a helpful assistant.', $result );
	}

	private function make_provider( int $failures_before_success ): AbstractProvider {
		return new class( $failures_before_success ) extends AbstractProvider {
			private int $calls = 0;
			public function __construct( private int $fail_count ) {}
			public function get_slug(): string         { return 'test'; }
			public function get_models(): array        { return []; }
			public function get_default_model(): string { return 'test-model'; }
			public function is_available(): bool       { return true; }
			protected function do_generate_image( string $p, array $o ): int { return 0; }
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

	// ── Opt-in usage events ───────────────────────────────────────────────────

	private const TURN_ID = '11111111-2222-4333-8444-555555555555';

	/**
	 * Configurable provider for telemetry tests.
	 *
	 * @param array<int, \Throwable|CompletionResponse> $script Results returned (or thrown) per call.
	 */
	private function make_scripted_provider( array $script, string $route = 'byok', array $models = [ 'gpt-4.1' => 'GPT-4.1', 'gpt-4.1-nano' => 'GPT-4.1 nano' ] ): AbstractProvider {
		return new class( $script, $route, $models ) extends AbstractProvider {
			public array $image_options = [];
			public function __construct( private array $script, private string $route, private array $models ) {}
			public function get_slug(): string          { return 'openai'; }
			public function get_models(): array         { return $this->models; }
			public function get_default_model(): string { return 'gpt-4.1'; }
			public function is_available(): bool        { return true; }
			protected function telemetry_route(): string { return $this->route; }
			protected function telemetry_image_model(): string { return 'dall-e-3'; }
			protected function do_generate_image( string $p, array $o ): int {
				$this->image_options = $o;
				$next = array_shift( $this->script );
				if ( $next instanceof \Throwable ) {
					throw $next;
				}
				return 42;
			}
			protected function do_complete( CompletionRequest $r ): CompletionResponse {
				$next = array_shift( $this->script );
				if ( $next instanceof \Throwable ) {
					throw $next;
				}
				return $next;
			}
			protected function do_stream( CompletionRequest $r, callable $cb ): CompletionResponse {
				return $this->do_complete( $r );
			}
		};
	}

	private function active_harness(): TelemetryHarness {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		return $h;
	}

	private function request( string $model = '', array $metadata = [ 'feature' => 'chat' ] ): CompletionRequest {
		return new CompletionRequest( [ [ 'role' => 'user', 'content' => 'secret prompt' ] ], '', $model, 0.7, 2048, $metadata );
	}

	public function test_success_records_one_ai_request(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 10, 5 ) ] );
		$provider->complete( $this->request( '', [ 'feature' => 'seo', 'turn_id' => self::TURN_ID ] ) );

		$queue = $h->flush();
		$this->assertCount( 1, $queue );
		$event = $queue[0];
		$this->assertSame( 'ai_request', $event['name'] );
		$this->assertSame( 'seo', $event['feature'] );
		$this->assertSame( 'openai', $event['provider'] );
		$this->assertSame( 'gpt-4.1', $event['model'] );
		$this->assertSame( 'byok', $event['route'] );
		$this->assertSame( 'ok', $event['outcome'] );
		$this->assertSame( '', $event['error_code'] );
		$this->assertSame( '', $event['first_error_code'] );
		$this->assertSame( 200, $event['http_status'] );
		$this->assertSame( 10, $event['input_tokens'] );
		$this->assertSame( 5, $event['output_tokens'] );
		$this->assertSame( 1, $event['attempts'] );
		$this->assertSame( self::TURN_ID, $event['turn_id'] );
		$this->assertStringNotContainsString( 'secret prompt', (string) json_encode( $queue ) );
	}

	public function test_retry_then_success_records_attempts_and_first_error(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider(
			[
				new ProviderException( 'slow down', 'openai', 429 ),
				new CompletionResponse( 'hi', 'gpt-4.1', 10, 5 ),
			]
		);
		$provider->complete( $this->request() );

		$event = $h->flush()[0];
		$this->assertSame( 2, $event['attempts'] );
		$this->assertSame( 'rate_limited', $event['first_error_code'] );
		$this->assertSame( 'ok', $event['outcome'] );
	}

	public function test_exhausted_retries_record_error_and_rethrow_the_same_exception(): void {
		$h         = $this->active_harness();
		$exception = new ProviderException( 'down', 'openai', 503 );
		$provider  = $this->make_scripted_provider( array_fill( 0, 4, $exception ) );
		try {
			$provider->complete( $this->request() );
			$this->fail( 'Expected exception.' );
		} catch ( ProviderException $e ) {
			$this->assertSame( $exception, $e );
		}

		$event = $h->flush()[0];
		$this->assertSame( 'error', $event['outcome'] );
		$this->assertSame( 'server_error', $event['error_code'] );
		$this->assertSame( 'server_error', $event['first_error_code'] );
		$this->assertSame( 503, $event['http_status'] );
		$this->assertSame( 4, $event['attempts'] );
		$this->assertSame( 0, $event['input_tokens'] );
	}

	public function test_telemetry_throwing_never_breaks_complete(): void {
		$this->active_harness();
		// get_slug() throwing inside the event builder simulates a telemetry failure.
		$provider = new class() extends AbstractProvider {
			private bool $done = false;
			public function get_slug(): string {
				if ( $this->done ) {
					throw new \RuntimeException( 'telemetry boom' );
				}
				return 'openai';
			}
			public function get_models(): array         { return []; }
			public function get_default_model(): string { return 'm'; }
			public function is_available(): bool        { return true; }
			protected function do_generate_image( string $p, array $o ): int { return 0; }
			protected function do_complete( CompletionRequest $r ): CompletionResponse {
				$this->done = true;
				return new CompletionResponse( 'ok', 'm', 1, 1 );
			}
			protected function do_stream( CompletionRequest $r, callable $cb ): CompletionResponse {
				return $this->do_complete( $r );
			}
		};
		$this->assertSame( 'ok', $provider->complete( $this->request() )->content );
	}

	public function test_inactive_records_nothing(): void {
		$h = TelemetryHarness::install();
		Telemetry::refresh();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 10, 5 ) ] );
		$provider->complete( $this->request() );
		Telemetry::persist();
		$this->assertSame( [], $h->queue() );
		$this->assertSame( 0, $h->state_writes );
	}

	public function test_turn_id_is_generated_when_metadata_has_none(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 1, 1 ) ] );
		$provider->stream( $this->request(), fn() => null );
		$this->assertSame( '00000000-0000-4000-8000-000000000001', $h->flush()[0]['turn_id'] );
	}

	public function test_unknown_feature_is_other(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 1, 1 ) ] );
		$provider->complete( $this->request( '', [ 'feature' => 'secret-feature' ] ) );
		$this->assertSame( 'other', $h->flush()[0]['feature'] );
	}

	public function test_generate_image_wrapper_records_image_count_and_strips_turn_id(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ 42 ] );
		$this->assertSame( 42, $provider->generate_image( 'a cat', [ 'size' => '1024x1024', 'turn_id' => self::TURN_ID ] ) );
		$this->assertSame( [ 'size' => '1024x1024' ], $provider->image_options );

		$event = $h->flush()[0];
		$this->assertSame( 'images', $event['feature'] );
		$this->assertSame( 1, $event['image_count'] );
		$this->assertSame( 'dall-e-3', $event['model'] );
		$this->assertSame( self::TURN_ID, $event['turn_id'] );
		$this->assertSame( 'ok', $event['outcome'] );
	}

	public function test_generate_image_failure_records_error_and_rethrows(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new ProviderException( 'x', 'openai', 0, [], null, 'media_save_failed' ) ] );
		try {
			$provider->generate_image( 'a cat' );
			$this->fail( 'Expected exception.' );
		} catch ( ProviderException $e ) {
			$this->assertSame( 'media_save_failed', $e->get_error_code() );
		}
		$event = $h->flush()[0];
		$this->assertSame( 'media_error', $event['error_code'] );
		$this->assertSame( 0, $event['image_count'] );
	}

	public function test_custom_model_name_is_sent_as_custom(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'acme.example.com', 1, 1 ) ] );
		$provider->complete( $this->request( 'acme.example.com' ) );
		$this->assertSame( 'custom', $h->flush()[0]['model'] );
	}

	public function test_dated_snapshot_maps_to_the_longest_known_base_id(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider(
			[
				new CompletionResponse( 'hi', 'gpt-4.1-nano-2025-04-14', 1, 1 ),
				new CompletionResponse( 'hi', 'gpt-4.1-2025-04-14', 1, 1 ),
			]
		);
		$provider->complete( $this->request() );
		$provider->complete( $this->request() );
		$queue = $h->flush();
		$this->assertSame( 'gpt-4.1-nano', $queue[0]['model'] );
		$this->assertSame( 'gpt-4.1', $queue[1]['model'] );
	}

	public function test_empty_model_falls_back_to_the_default(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', '', 1, 1 ) ] );
		$provider->complete( $this->request() );
		$this->assertSame( 'gpt-4.1', $h->flush()[0]['model'] );
	}

	public function test_proxy_route_sends_real_tokens(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 1234, 567 ) ], 'proxy' );
		$provider->complete( $this->request() );
		$event = $h->flush()[0];
		$this->assertSame( 'proxy', $event['route'] );
		$this->assertSame( 1234, $event['input_tokens'] );
		$this->assertSame( 567, $event['output_tokens'] );
	}

	public function test_proxy_route_sends_zero_tokens_when_managed_service_statistics_are_off(): void {
		$h = $this->active_harness();
		$h->options[ \Plume\Proxy\ProxyClient::OPTION_SHARE_USAGE_STATS ] = false;
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 1234, 567 ) ], 'proxy' );
		$provider->complete( $this->request() );
		$event = $h->flush()[0];
		$this->assertSame( 'proxy', $event['route'] );
		$this->assertSame( 0, $event['input_tokens'] );
		$this->assertSame( 0, $event['output_tokens'] );
	}

	public function test_byok_route_sends_tokens_when_managed_service_statistics_are_off(): void {
		$h = $this->active_harness();
		$h->options[ \Plume\Proxy\ProxyClient::OPTION_SHARE_USAGE_STATS ] = false;
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 12, 5 ) ] );
		$provider->complete( $this->request() );
		$this->assertSame( 12, $h->flush()[0]['input_tokens'] );
	}

	public function test_ollama_route_model_is_always_custom(): void {
		$h        = $this->active_harness();
		$provider = $this->make_scripted_provider( [ new CompletionResponse( 'hi', 'gpt-4.1', 3, 4 ) ], 'ollama' );
		$provider->complete( $this->request() );
		$event = $h->flush()[0];
		$this->assertSame( 'custom', $event['model'] );
		$this->assertSame( 3, $event['input_tokens'] );
	}
}
