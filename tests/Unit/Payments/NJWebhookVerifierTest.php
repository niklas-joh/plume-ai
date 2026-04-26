<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Payments\NJ_Webhook_Verifier;

/**
 * Tests for NJ_Webhook_Verifier.
 *
 * NJ_Webhook_Verifier uses only native PHP functions (hash_hmac, hash_equals),
 * so Brain Monkey is not required here.
 *
 * @since 1.2.1
 */
class NJWebhookVerifierTest extends TestCase {

	// ── False-positive guards ──────────────────────────────────────────────────

	public function test_returns_false_when_signature_is_null(): void {
		$this->assertFalse( NJ_Webhook_Verifier::verify( 'body', null, 'secret' ) );
	}

	public function test_returns_false_when_signature_is_empty_string(): void {
		$this->assertFalse( NJ_Webhook_Verifier::verify( 'body', '', 'secret' ) );
	}

	public function test_returns_false_when_secret_is_empty_string(): void {
		$sig = hash_hmac( 'sha256', 'body', '' );
		$this->assertFalse( NJ_Webhook_Verifier::verify( 'body', $sig, '' ) );
	}

	// ── Correct HMAC ──────────────────────────────────────────────────────────

	public function test_returns_true_for_correct_hmac_sha256_signature(): void {
		$body   = '{"event":"test"}';
		$secret = 'my-webhook-secret';
		$sig    = hash_hmac( 'sha256', $body, $secret );

		$this->assertTrue( NJ_Webhook_Verifier::verify( $body, $sig, $secret ) );
	}

	// ── Incorrect HMAC ────────────────────────────────────────────────────────

	public function test_returns_false_for_incorrect_signature(): void {
		$body   = '{"event":"test"}';
		$secret = 'my-webhook-secret';

		$this->assertFalse( NJ_Webhook_Verifier::verify( $body, 'wrong-signature-value', $secret ) );
	}

	public function test_returns_false_when_secret_differs_from_signing_secret(): void {
		$body           = '{"event":"test"}';
		$signing_secret = 'correct-secret';
		$verify_secret  = 'wrong-secret';
		$sig            = hash_hmac( 'sha256', $body, $signing_secret );

		// Correct HMAC for signing_secret but verified against a different secret.
		$this->assertFalse( NJ_Webhook_Verifier::verify( $body, $sig, $verify_secret ) );
	}

	// ── hash_equals timing-safe comparison (both sides verified) ──────────────

	public function test_hash_equals_fails_for_non_matching_hmac(): void {
		$body          = 'payload-data';
		$secret        = 'shared-secret';
		$correct_sig   = hash_hmac( 'sha256', $body, $secret );
		$tampered_sig  = substr_replace( $correct_sig, '0', 0, 1 );

		// Confirms that even a one-character change in the signature fails.
		$this->assertFalse( NJ_Webhook_Verifier::verify( $body, $tampered_sig, $secret ) );
	}
}
