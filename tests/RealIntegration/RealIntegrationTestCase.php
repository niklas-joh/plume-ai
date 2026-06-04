<?php
/**
 * Base class for all real integration tests.
 *
 * Extends IntegrationTestCase so every test has a real WordPress environment,
 * including a live database and functional REST server. Adds skip-if-no-key
 * helpers and tier-configuration shortcuts needed for real API calls.
 *
 * @package Stilus\Tests\RealIntegration
 */

declare( strict_types=1 );

namespace Stilus\Tests\RealIntegration;

use Stilus\Tests\Integration\IntegrationTestCase;
use Stilus\Tiers\TierManager;
use Stilus\Proxy\SiteRegistration;
use Stilus\Settings\ProviderSettings;

/**
 * @since 1.8.0
 */
abstract class RealIntegrationTestCase extends IntegrationTestCase {

	/**
	 * Skip if CLAUDE_API_KEY env var is absent.
	 *
	 * Call from setUpBeforeClass() in test classes that need real Anthropic calls.
	 *
	 * @since 1.8.0
	 */
	protected static function skip_without_api_key(): void {
		if ( '' === ( getenv( 'CLAUDE_API_KEY' ) ?: '' ) ) {
			self::markTestSkipped( 'CLAUDE_API_KEY not set — skipping real API tests.' );
		}
	}

	/**
	 * Skip if STILUS_CI_SITE_TOKEN env var is absent.
	 *
	 * @since 1.8.0
	 */
	protected static function skip_without_proxy_token(): void {
		if ( '' === ( getenv( 'STILUS_CI_SITE_TOKEN' ) ?: '' ) ) {
			self::markTestSkipped( 'STILUS_CI_SITE_TOKEN not set — skipping real proxy tests.' );
		}
	}

	/**
	 * Configure the site and user for the Pro-BYOK tier with a real Anthropic API key.
	 *
	 * Sets the site-level tier option to pro_byok, removes the HMAC secret so
	 * is_site_tier_verified() passes for this unregistered test install, stores
	 * the CLAUDE_API_KEY env var via ProviderSettings so the Claude provider can
	 * make live API calls, and applies the matching user-level tier meta so the
	 * per-user tier resolution is consistent with activate_trial_tier / activate_free_tier.
	 *
	 * @since 1.8.0
	 * @param int $user_id WordPress user ID.
	 */
	protected function activate_byok_tier( int $user_id ): void {
		// set_user_tier() (base class) resets SITE_OPTION to 'free' — call it first
		// so our update_option below is the last write and is not overwritten.
		$this->set_user_tier( $user_id, 'pro_byok' );
		// Now set the site-level option; is_site_tier_verified() returns true when
		// no HMAC secret is registered, so deleting OPTION_SECRET is the unlock.
		update_option( TierManager::SITE_OPTION, 'pro_byok', false );
		delete_option( SiteRegistration::OPTION_SECRET );
		// Store the real API key so the Claude provider can call the Anthropic API directly.
		( new ProviderSettings() )->set_api_key( 'claude', getenv( 'CLAUDE_API_KEY' ) ?: '' );
	}

	/**
	 * Configure a user for the trial tier (routes AI calls through the proxy).
	 *
	 * @since 1.8.0
	 * @param int $user_id WordPress user ID.
	 */
	protected function activate_trial_tier( int $user_id ): void {
		$this->set_user_tier( $user_id, 'trial' );
	}

	/**
	 * Configure a user for the free tier (chat only; all other features blocked).
	 *
	 * @since 1.8.0
	 * @param int $user_id WordPress user ID.
	 */
	protected function activate_free_tier( int $user_id ): void {
		$this->set_user_tier( $user_id, 'free' );
	}
}
