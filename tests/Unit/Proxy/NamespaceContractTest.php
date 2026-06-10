<?php
/**
 * Structural contract test: Worker TypeScript source and PHP controllers
 * must both use the stilus/v1 REST namespace.
 *
 * Runs in the standard phpunit (unit) job — no wp-env, no secrets, ~0 ms.
 * If this fails it means the Worker callback URL and the WordPress REST route
 * have diverged — the same class of bug that caused PR #596.
 *
 * @package Stilus\Tests\Unit\Proxy
 */

declare( strict_types=1 );

namespace Stilus\Tests\Unit\Proxy;

use PHPUnit\Framework\TestCase;

/**
 * @since 1.8.0
 */
class NamespaceContractTest extends TestCase {

	private const WORKER_SRC = __DIR__ . '/../../../stilus-proxy/src';

	/**
	 * Worker registration.ts callback URL must use the stilus/v1 namespace.
	 *
	 * @since 1.8.0
	 */
	public function test_worker_registration_callback_uses_stilus_v1(): void {
		$source = file_get_contents( self::WORKER_SRC . '/registration.ts' );
		$this->assertIsString( $source );
		$this->assertStringContainsString(
			'/wp-json/stilus/v1/activation-verify',
			$source,
			'Worker registration.ts callback URL must match the stilus/v1 REST namespace.'
		);
	}

	/**
	 * No Worker source file may contain a legacy /wp-ai-mind/ namespace URL.
	 *
	 * @since 1.8.0
	 */
	public function test_all_worker_source_files_free_of_legacy_namespace(): void {
		$ts_files = glob( self::WORKER_SRC . '/*.ts' );
		$this->assertNotEmpty( $ts_files, 'Expected at least one .ts file in stilus-proxy/src/.' );
		foreach ( $ts_files as $path ) {
			$this->assertStringNotContainsString(
				'/wp-json/wp-ai-mind/',
				file_get_contents( $path ),
				basename( $path ) . ' contains a legacy /wp-json/wp-ai-mind/ reference.'
			);
		}
	}

	/**
	 * Core PHP REST controllers must declare the stilus/v1 namespace, not the legacy one.
	 *
	 * Uses RecursiveDirectoryIterator discovery so newly added controllers anywhere
	 * under includes/ are covered automatically without maintaining path patterns.
	 * All current *Controller.php files under includes/ register routes under stilus/v1;
	 * add an explicit exclusion if a non-REST controller file is ever added here.
	 *
	 * @since 1.8.0
	 * @since 1.9.0 Switched from a hardcoded list to recursive file discovery.
	 */
	public function test_php_rest_controllers_use_stilus_v1_namespace(): void {
		$iterator        = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( __DIR__ . '/../../../includes' )
		);
		$all_controllers = [];
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && str_ends_with( $file->getFilename(), 'Controller.php' ) ) {
				$all_controllers[] = $file->getPathname();
			}
		}

		$this->assertNotEmpty( $all_controllers, 'No controller files found — includes/ directory missing or empty' );

		// The shared constant is the canonical declaration of the namespace.
		$rest_api_source = file_get_contents( __DIR__ . '/../../../includes/Core/RestApi.php' );
		$this->assertNotFalse( $rest_api_source, 'Could not read includes/Core/RestApi.php' );
		$this->assertStringContainsString(
			"'stilus/v1'",
			$rest_api_source,
			"RestApi::API_NAMESPACE must be 'stilus/v1'."
		);

		foreach ( $all_controllers as $file_path ) {
			$source = file_get_contents( $file_path );
			$this->assertNotFalse( $source, "Could not read: $file_path" );
			// Controllers either reference the shared RestApi::API_NAMESPACE constant
			// or (legacy style) declare the 'stilus/v1' literal locally.
			$this->assertTrue(
				str_contains( $source, 'RestApi::API_NAMESPACE' ) || str_contains( $source, "'stilus/v1'" ),
				basename( $file_path ) . " must use RestApi::API_NAMESPACE or the 'stilus/v1' literal."
			);
			$this->assertStringNotContainsString(
				"'wp-ai-mind/v1'",
				$source,
				basename( $file_path ) . " must not use the legacy 'wp-ai-mind/v1' namespace."
			);
		}
	}
}
