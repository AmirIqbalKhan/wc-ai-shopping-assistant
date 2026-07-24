<?php
/**
 * Uninstall cleanup.
 *
 * @package WCAI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$shopask_plugin_dir = plugin_dir_path( __FILE__ );
require_once $shopask_plugin_dir . 'includes/class-db.php';

$shopask_tables = WCAI_DB::all_tables();

foreach ( $shopask_tables as $shopask_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall DROP
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $shopask_table ) );
}

$shopask_options = array(
	'wcai_settings',
	'wcai_secrets',
	'wcai_usage',
	'wcai_reindex_state',
	'wcai_db_version',
	'wcai_rate_limit_keys',
);

foreach ( $shopask_options as $shopask_option ) {
	delete_option( $shopask_option );
}

// Session tokens stored on users for privacy export/erase.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->usermeta, 'wcai_session_token' ) );

// Debounce transients from product hooks.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_wcai_debounce_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wcai_debounce_' ) . '%'
	)
);

// Pending Action Scheduler jobs in the wcai group.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'wcai_reindex_batch', 'wcai_reindex_product', 'wcai_remove_product', 'wcai_update_stock' ) as $shopask_hook ) {
		as_unschedule_all_actions( $shopask_hook, array(), 'wcai' );
	}
}

wp_clear_scheduled_hook( 'wcai_cleanup_rate_limits' );
wp_clear_scheduled_hook( 'wcai_cleanup_sessions' );
wp_clear_scheduled_hook( 'wcai_cleanup_analytics' );
