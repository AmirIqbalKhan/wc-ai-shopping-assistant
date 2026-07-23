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

wp_clear_scheduled_hook( 'wcai_cleanup_rate_limits' );
wp_clear_scheduled_hook( 'wcai_cleanup_sessions' );
wp_clear_scheduled_hook( 'wcai_cleanup_analytics' );
