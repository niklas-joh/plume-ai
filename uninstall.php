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

// Drop custom tables — table identifiers cannot be bound via prepare(); $wpdb->prefix is trusted.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plume_conversations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plume_messages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared

// Stop the opt-in usage send job. Literal hook name: the plugin autoloader is not loaded here.
// Nothing is sent at uninstall.
wp_clear_scheduled_hook( 'plume_telemetry_send' );

// The plume_% delete below also removes plume_telemetry_consent, plume_install_id,
// the plume_telemetry_state queue and plume_activated_at.
// Delete options — no WP helper exists for bulk LIKE-pattern option removal; value is escaped via esc_like().
$plume_option_like = $wpdb->esc_like( 'plume_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $plume_option_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Delete user meta — no WP helper exists for bulk LIKE-pattern meta removal; value is escaped via esc_like().
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $plume_option_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
