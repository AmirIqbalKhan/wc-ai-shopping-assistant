<?php
/**
 * Uninstall cleanup.
 *
 * @package WCAI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tables = array(
	$wpdb->prefix . 'ai_product_index',
	$wpdb->prefix . 'ai_chat_sessions',
	$wpdb->prefix . 'ai_query_log',
	$wpdb->prefix . 'ai_click_log',
	$wpdb->prefix . 'ai_rate_limits',
	$wpdb->prefix . 'ai_usage_counters',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = array(
	'wcai_settings',
	'wcai_secrets',
	'wcai_usage',
	'wcai_reindex_state',
	'wcai_db_version',
	'wcai_rate_limit_keys',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Session tokens stored on users for privacy export/erase.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'wcai_session_token'" );

// Debounce transients from product hooks.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wcai_debounce_%' OR option_name LIKE '_transient_timeout_wcai_debounce_%'" );

// Pending Action Scheduler jobs in the wcai group.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'wcai_reindex_batch', 'wcai_reindex_product', 'wcai_remove_product', 'wcai_update_stock' ) as $hook ) {
		as_unschedule_all_actions( $hook, array(), 'wcai' );
	}
}

wp_clear_scheduled_hook( 'wcai_cleanup_rate_limits' );
wp_clear_scheduled_hook( 'wcai_cleanup_sessions' );
wp_clear_scheduled_hook( 'wcai_cleanup_analytics' );
