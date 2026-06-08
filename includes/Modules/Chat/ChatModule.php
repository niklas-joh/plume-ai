<?php
/**
 * Chat module bootstrap — registers assets and REST routes for the chat feature.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Modules\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Tools\ToolRegistry;
use Stilus\Tools\ToolExecutor;

/**
 * Bootstraps the Chat module by registering its REST routes on rest_api_init.
 */
class ChatModule {

	/**
	 * Register the rest_api_init hook that wires up both REST controllers.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			function () {
				$tool_registry = new ToolRegistry();
				$tool_executor = new ToolExecutor( $tool_registry );
				( new ChatRestController( $tool_registry, $tool_executor ) )->register_routes();
				( new PlansRestController( $tool_executor ) )->register_routes();
				( new SettingsRestController() )->register_routes();
			}
		);
	}
}
