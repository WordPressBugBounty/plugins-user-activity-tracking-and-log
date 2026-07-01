<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes the
 * activity log table, every option the plugin owns, and any scheduled
 * cron events.
 *
 * @package user-activity-tracking-and-log
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Respect the "Keep data on uninstall" advanced setting (default: keep).
// Only cron events are unscheduled in keep mode, because leaving stale
// hooks behind would fatal on the next tick once the plugin code is gone.
$keep_data = '1' === get_option( 'uat_keep_data_on_uninstall', '1' );

if ( ! $keep_data ) {
	// Drop the activity log table.
	$table = $wpdb->prefix . 'moove_activity_log';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore

	// Delete every option the plugin owns. Use a single DELETE so this stays
	// O(1) regardless of how many sites with autoloaded options.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE 'uat\\_%'
		    OR option_name LIKE 'moove\\_uat\\_%'
		    OR option_name = 'moove_post_act'
		    OR option_name = 'moove_importer_has_database'
		    OR option_name = 'moove_importer_has_extras'
		    OR option_name = 'moove_tracking_settings_act'
		    OR option_name = 'moove-activity-timezone-offset'"
	); // phpcs:ignore

	// Delete per-user meta written by the plugin.
	delete_metadata( 'user', 0, 'moove_activity_screen_options', '', true );
}

// Always clear scheduled events — the hooks would fatal on the next tick
// once the plugin code is gone, regardless of the keep-data preference.
$hooks = array(
	'uat_daily_maintenance',
	'uat_resolve_geo_async',
	'uat_import_legacy_logs',
);
foreach ( $hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
	wp_clear_scheduled_hook( $hook );
}
