<?php
/**
 * Runs when the plugin is deleted via the WordPress admin to remove all plugin data.
 *
 * @package Plume
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plume_conversations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plume_messages" );

// Delete options.
$prefix = $wpdb->esc_like( 'plume_' ) . '%'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) );

// Delete user meta.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $prefix ) );
