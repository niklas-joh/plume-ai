<?php
declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpaim_conversations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpaim_messages" );

// Delete options.
$prefix = $wpdb->esc_like( 'wp_ai_mind_' ) . '%'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) );

// Delete user meta.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $prefix ) );
