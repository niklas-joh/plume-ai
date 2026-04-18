<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NJ_Webhook_Verifier {

	public static function verify( string $body, ?string $signature, string $secret ): bool {
		if ( ! $signature || empty( $secret ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $expected, $signature );
	}
}
