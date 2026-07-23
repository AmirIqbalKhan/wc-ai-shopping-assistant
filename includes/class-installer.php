<?php
/**
 * Plugin install / upgrade helpers.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates custom tables and default options on activation.
 */
class WCAI_Installer {

	const DB_VERSION = '2';

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::create_tables();
		self::maybe_add_fulltext();
		self::seed_options();
		update_option( 'wcai_db_version', self::DB_VERSION );

		if ( ! wp_next_scheduled( 'wcai_cleanup_rate_limits' ) ) {
			wp_schedule_event( time(), 'hourly', 'wcai_cleanup_rate_limits' );
		}
		if ( ! wp_next_scheduled( 'wcai_cleanup_sessions' ) ) {
			wp_schedule_event( time(), 'hourly', 'wcai_cleanup_sessions' );
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wcai_cleanup_rate_limits' );
		wp_clear_scheduled_hook( 'wcai_cleanup_sessions' );
	}

	/**
	 * Upgrade schema if needed (plugins_loaded).
	 */
	public static function maybe_upgrade(): void {
		$current = (string) get_option( 'wcai_db_version', '1' );
		if ( version_compare( $current, self::DB_VERSION, '>=' ) ) {
			return;
		}
		self::create_tables();
		self::maybe_add_fulltext();
		self::seed_options();
		update_option( 'wcai_db_version', self::DB_VERSION );
	}

	/**
	 * Create all plugin tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$index = self::table_name();
		dbDelta(
			"CREATE TABLE {$index} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title TEXT NOT NULL,
			summary_text TEXT NOT NULL,
			price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			stock_status VARCHAR(20) NOT NULL DEFAULT 'instock',
			category_names TEXT NULL,
			attributes_json LONGTEXT NULL,
			embedding LONGBLOB NULL,
			last_indexed_at DATETIME NULL,
			product_url TEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_variation (product_id, variation_id),
			KEY stock_status (stock_status),
			KEY stock_price (stock_status, price)
		) {$charset_collate};"
		);

		$sessions = self::sessions_table();
		dbDelta(
			"CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_token VARCHAR(64) NOT NULL,
			constraints_json LONGTEXT NULL,
			shown_product_ids LONGTEXT NULL,
			history_json LONGTEXT NULL,
			turn_count INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_token (session_token),
			KEY updated_at (updated_at)
		) {$charset_collate};"
		);

		$queries = self::query_log_table();
		dbDelta(
			"CREATE TABLE {$queries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_token VARCHAR(64) NULL,
			query_text TEXT NOT NULL,
			query_hash VARCHAR(32) NOT NULL,
			result_count INT UNSIGNED NOT NULL DEFAULT 0,
			matched TINYINT(1) NOT NULL DEFAULT 0,
			confidence DECIMAL(6,4) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY query_hash (query_hash),
			KEY created_at (created_at),
			KEY matched (matched)
		) {$charset_collate};"
		);

		$clicks = self::click_log_table();
		dbDelta(
			"CREATE TABLE {$clicks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			query_id BIGINT UNSIGNED NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			session_token VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY created_at (created_at),
			KEY query_id (query_id)
		) {$charset_collate};"
		);
	}

	/**
	 * Add FULLTEXT index on summary_text when supported.
	 */
	public static function maybe_add_fulltext(): void {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'summary_ft'" );
		if ( $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT summary_ft (summary_text)" );
	}

	/**
	 * Default plugin options (merge, never wipe).
	 */
	public static function seed_options(): void {
		$defaults = self::default_settings();
		$existing = get_option( 'wcai_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		update_option( 'wcai_settings', array_merge( $defaults, $existing ) );
	}

	/**
	 * Default settings map.
	 *
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'provider'                => 'openai',
			'api_key'                 => '',
			'api_base'                => 'https://api.openai.com/v1',
			'embedding_mode'          => 'auto',
			'embedding_model'         => 'text-embedding-3-small',
			'chat_model'              => 'gpt-4o-mini',
			'widget_enabled'          => '1',
			'placement_mode'          => 'floating',
			'top_n'                   => 20,
			'similarity_threshold'    => 0.25,
			'prefilter_limit'         => 300,
			'index_mode'              => 'parent',
			'rate_limit_anon'         => 20,
			'rate_limit_user'         => 60,
			'rate_limit_per_min'      => 20,
			'plan'                    => 'free',
			'monthly_query_cap'       => 500,
			'hide_branding'           => '0',
			'widget_title'            => '',
			'accent_color'            => '#0d9488',
			'agency_license_key'      => '',
			'public_api_key'          => '',
			'webhook_url'             => '',
		);
	}

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_product_index';
	}

	/**
	 * @return string
	 */
	public static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_chat_sessions';
	}

	/**
	 * @return string
	 */
	public static function query_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_query_log';
	}

	/**
	 * @return string
	 */
	public static function click_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_click_log';
	}
}
