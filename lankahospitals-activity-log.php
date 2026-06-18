<?php
/**
 * Plugin Name:       Lanka Hospitals Multisite Activity Log
 * Plugin URI:        https://weblankan.com/
 * Description:       Network-wide activity log for WordPress Multisite. Records logins, content changes, user management, plugin/theme actions and settings updates across every site into one searchable log.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            WebLankan
 * Author URI:        https://weblankan.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lh-activity-log
 * Network:           true
 *
 * @package LH_Activity_Log
 */

defined( 'ABSPATH' ) || exit;

define( 'LH_AL_VERSION', '1.0.0' );
define( 'LH_AL_FILE', __FILE__ );
define( 'LH_AL_DIR', plugin_dir_path( __FILE__ ) );
define( 'LH_AL_URL', plugin_dir_url( __FILE__ ) );
define( 'LH_AL_TABLE', 'lh_activity_log' );

require_once LH_AL_DIR . 'includes/class-lh-al-db.php';
require_once LH_AL_DIR . 'includes/class-lh-al-logger.php';
require_once LH_AL_DIR . 'includes/class-lh-al-hooks.php';

if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once LH_AL_DIR . 'includes/class-lh-al-admin.php';
}

/**
 * Bootstrap the plugin once all plugins are loaded.
 */
function lh_al_init() {
	LH_AL_DB::instance();
	LH_AL_Hooks::instance();

	if ( is_admin() ) {
		LH_AL_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'lh_al_init' );

/**
 * Create the (network-shared) log table on activation.
 *
 * @param bool $network_wide Whether the plugin was network-activated.
 */
function lh_al_activate( $network_wide ) {
	require_once LH_AL_DIR . 'includes/class-lh-al-db.php';
	LH_AL_DB::install();
}
register_activation_hook( __FILE__, 'lh_al_activate' );

/**
 * Ensure the table exists when a new site is added to the network.
 *
 * The log is stored in a single shared table, so we only need to make sure it
 * exists; no per-site tables are created.
 */
function lh_al_on_new_site() {
	LH_AL_DB::install();
}
add_action( 'wp_initialize_site', 'lh_al_on_new_site', 900 );

/**
 * Schedule the daily retention purge on activation.
 */
function lh_al_schedule_cron() {
	if ( ! wp_next_scheduled( 'lh_al_daily_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lh_al_daily_purge' );
	}
}
register_activation_hook( __FILE__, 'lh_al_schedule_cron' );

/**
 * Clear the scheduled purge on deactivation.
 */
function lh_al_clear_cron() {
	$timestamp = wp_next_scheduled( 'lh_al_daily_purge' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'lh_al_daily_purge' );
	}
}
register_deactivation_hook( __FILE__, 'lh_al_clear_cron' );

/**
 * Daily cron: delete entries older than the configured retention window.
 */
function lh_al_run_daily_purge() {
	$days = (int) get_site_option( 'lh_al_retention_days', 0 );
	if ( $days > 0 ) {
		LH_AL_DB::instance()->purge_older_than( $days );
	}
}
add_action( 'lh_al_daily_purge', 'lh_al_run_daily_purge' );