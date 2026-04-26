<?php
declare( strict_types=1 );

// Define constants required for Plugin class in test context.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WP_AI_MIND_BASENAME' ) ) {
	define( 'WP_AI_MIND_BASENAME', 'wp-ai-mind/wp-ai-mind.php' );
}
if ( ! defined( 'WP_AI_MIND_HTTP_TIMEOUT' ) ) {
	define( 'WP_AI_MIND_HTTP_TIMEOUT', 60 );
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
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function get_param( string $key ) {
			return $this->url_params[ $key ] ?? $this->params[ $key ] ?? null;
		}
		public function get_json_params(): array { return $this->params; }
		public function get_params(): array { return array_merge( $this->params, $this->url_params ); }
		public function set_body_params( array $params ): void { $this->params = array_merge( $this->params, $params ); }
		public function set_url_params( array $params ): void { $this->url_params = array_merge( $this->url_params, $params ); }
		public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public mixed $data = null, public int $status = 200 ) {}
		public function get_status(): int { return $this->status; }
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

// Minimal $wpdb stub so NJ_Usage_Tracker::log_usage() doesn't throw in tests
// that don't set up their own $wpdb mock (e.g. provider tests).
global $wpdb;
if ( null === $wpdb ) {
	$wpdb               = new class() {
		public string $usermeta      = 'wp_usermeta';
		public int    $rows_affected = 1;
		public function prepare( string $sql, ...$args ): string { return $sql; }
		public function query( string $sql ): int { return 1; }
	};
}

