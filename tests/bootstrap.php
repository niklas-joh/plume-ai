<?php
declare( strict_types=1 );

// Define constants required for Plugin class in test context.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'STILUS_BASENAME' ) ) {
	define( 'STILUS_BASENAME', 'stilus/stilus.php' );
}
if ( ! defined( 'STILUS_HTTP_TIMEOUT' ) ) {
	define( 'STILUS_HTTP_TIMEOUT', 60 );
}
// Prevent get_proxy_url() from calling get_option() in unit tests.
if ( ! defined( 'STILUS_PROXY_URL' ) ) {
	define( 'STILUS_PROXY_URL', 'https://stilus-proxy.stilus.workers.dev' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress query-result format constants (not provided by Brain Monkey).
if ( ! defined( 'OBJECT' ) )          { define( 'OBJECT',          'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A',         'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) )         { define( 'ARRAY_N',         'ARRAY_N' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS',  86400 ); }

// Brain Monkey setUp/tearDown are called per test via trait.
// WP stubs — Brain Monkey provides them when you call Monkey\setUp().

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		private mixed $data;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_data( string $code = '' ): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int    $ID           = 0;
		public string $post_title   = '';
		public string $post_content = '';
		public string $post_excerpt = '';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params     = [];
		private array $url_params = [];
		private array $headers    = [];
		private string $body      = '';
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function get_param( string $key ) {
			return $this->url_params[ $key ] ?? $this->params[ $key ] ?? null;
		}
		public function get_json_params(): array { return $this->params; }
		public function get_params(): array { return array_merge( $this->params, $this->url_params ); }
		public function set_body_params( array $params ): void { $this->params = array_merge( $this->params, $params ); }
		public function set_url_params( array $params ): void { $this->url_params = array_merge( $this->url_params, $params ); }
		public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
		public function set_header( string $key, string $value ): void {
			// WP stores keys lowercased with underscores so callers can use either case.
			$this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
		}
		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] ?? null;
		}
		public function set_body( string $body ): void { $this->body = $body; }
		public function get_body(): string { return $this->body; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private array $headers = [];
		public function __construct( public mixed $data = null, public int $status = 200 ) {}
		public function get_status(): int { return $this->status; }
		public function header( string $key, string $value, bool $replace = true ): void {
			if ( $replace || ! isset( $this->headers[ $key ] ) ) {
				$this->headers[ $key ] = $value;
			}
		}
		public function get_headers(): array { return $this->headers; }
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const DELETABLE = 'DELETE';
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) { return new \WP_REST_Response( $data ); }
}

// Minimal $wpdb stub so UsageTracker::log_usage() doesn't throw in tests
// that don't set up their own $wpdb mock (e.g. provider tests).
global $wpdb;
if ( null === $wpdb ) {
	$wpdb = \Stilus\Tests\Helpers\WpdbStubFactory::create();
}

