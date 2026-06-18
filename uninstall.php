<?php
/**
 * Uninstall routine — drops the log table and removes options.
 *
 * @package LH_Activity_Log
 */

// Exit if accessed directly or not via the uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;
$table  = $prefix . 'lh_activity_log';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Remove network/site options.
delete_site_option( 'lh_al_db_version' );
delete_site_option( 'lh_al_retention_days' );

// Clear scheduled cron, if any.
$timestamp = wp_next_scheduled( 'lh_al_daily_purge' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'lh_al_daily_purge' );
}
