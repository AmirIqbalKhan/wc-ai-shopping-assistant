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

	const DB_VERSION = '3';

	const SECRET_KEYS = array( 'api_key', 'public_api_key', 'agency_license_key' );

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::create_tables();
		self::maybe_add_fulltext();
		self::maybe_add_indexes();
		self::seed_options();
		self::migrate_secrets();
		self::force_options_autoload_no();
		update_option( 'wcai_db_version', self::DB_VERSION, false );

		if ( ! wp_next_scheduled( 'wcai_cleanup_rate_limits' ) ) {
			wp_schedule_event( time(), 'hourly', 'wcai_cleanup_rate_limits' );
		}
		if ( ! wp_next_scheduled( 'wcai_cleanup_sessions' ) ) {
			wp_schedule_event( time(), 'hourly', 'wcai_cleanup_sessions' );
		}
		if ( ! wp_next_scheduled( 'wcai_cleanup_analytics' ) ) {
			wp_schedule_event( time(), 'daily', 'wcai_cleanup_analytics' );
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wcai_cleanup_rate_limits' );
		wp_clear_scheduled_hook( 'wcai_cleanup_sessions' );
		wp_clear_scheduled_hook( 'wcai_cleanup_analytics' );
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
		self::maybe_add_indexes();
		self::seed_options();
		self::migrate_secrets();
		self::force_options_autoload_no();
		delete_option( 'wcai_rate_limit_keys' );
		update_option( 'wcai_db_version', self::DB_VERSION, false );
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
			KEY stock_price (stock_status, price),
			KEY last_indexed_at (last_indexed_at)
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
			result_product_ids LONGTEXT NULL,
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
			KEY query_id (query_id),
			UNIQUE KEY query_product (query_id, product_id)
		) {$charset_collate};"
		);

		$rates = self::rate_limits_table();
		dbDelta(
			"CREATE TABLE {$rates} (
			rate_key VARCHAR(191) NOT NULL,
			window_start INT UNSIGNED NOT NULL DEFAULT 0,
			hit_count INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (rate_key),
			KEY window_start (window_start)
		) {$charset_collate};"
		);

		$usage = self::usage_counters_table();
		dbDelta(
			"CREATE TABLE {$usage} (
			counter_key VARCHAR(64) NOT NULL,
			period_key VARCHAR(32) NOT NULL,
			hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (counter_key, period_key)
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
	 * Add missing indexes / columns for upgrades.
	 */
	public static function maybe_add_indexes(): void {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$idx = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'last_indexed_at'" );
		if ( ! $idx ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD KEY last_indexed_at (last_indexed_at)" );
		}

		$qtable = self::query_log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_results( "SHOW COLUMNS FROM {$qtable} LIKE 'result_product_ids'" );
		if ( empty( $col ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$qtable} ADD COLUMN result_product_ids LONGTEXT NULL AFTER result_count" );
		}

		$ctable = self::click_log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$uq = $wpdb->get_var( "SHOW INDEX FROM {$ctable} WHERE Key_name = 'query_product'" );
		if ( ! $uq ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$ctable} ADD UNIQUE KEY query_product (query_id, product_id)" );
		}
	}

	/**
	 * Default plugin options (merge, never wipe). Secrets stay in wcai_secrets.
	 */
	public static function seed_options(): void {
		$defaults = self::default_settings();
		foreach ( self::SECRET_KEYS as $key ) {
			unset( $defaults[ $key ] );
		}
		$existing = get_option( 'wcai_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		foreach ( self::SECRET_KEYS as $key ) {
			unset( $existing[ $key ] );
		}
		update_option( 'wcai_settings', array_merge( $defaults, $existing ), false );

		$secrets = get_option( 'wcai_secrets', array() );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}
		$secret_defaults = array(
			'api_key'            => '',
			'public_api_key'     => '',
			'agency_license_key' => '',
		);
		update_option( 'wcai_secrets', array_merge( $secret_defaults, $secrets ), false );
	}

	/**
	 * Move secrets out of wcai_settings into wcai_secrets.
	 */
	public static function migrate_secrets(): void {
		$settings = get_option( 'wcai_settings', array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$secrets = get_option( 'wcai_secrets', array() );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}

		$moved = false;
		foreach ( self::SECRET_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== (string) $settings[ $key ] && empty( $secrets[ $key ] ) ) {
				$secrets[ $key ] = $settings[ $key ];
				$moved           = true;
			}
			unset( $settings[ $key ] );
		}

		if ( $moved || isset( $settings['api_key'] ) ) {
			update_option( 'wcai_settings', $settings, false );
			update_option( 'wcai_secrets', $secrets, false );
		}
	}

	/**
	 * Force autoload=no for settings and secrets.
	 */
	public static function force_options_autoload_no(): void {
		global $wpdb;
		foreach ( array( 'wcai_settings', 'wcai_secrets', 'wcai_usage', 'wcai_reindex_state' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				array( 'autoload' => 'no' ),
				array( 'option_name' => $name ),
				array( '%s' ),
				array( '%s' )
			);
		}
	}

	/**
	 * Default settings map (includes secret key placeholders for merge in get_all).
	 *
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'provider'             => 'openai',
			'api_key'              => '',
			'api_base'             => 'https://api.openai.com/v1',
			'embedding_mode'       => 'auto',
			'embedding_model'      => 'text-embedding-3-small',
			'chat_model'           => 'gpt-4o-mini',
			'widget_enabled'       => '1',
			'show_floating'        => '1',
			'auto_search_location' => 'none',
			'placement_mode'       => 'both',
			'top_n'                => 20,
			'similarity_threshold' => 0.25,
			'prefilter_limit'      => 300,
			'index_mode'           => 'parent',
			'rate_limit_anon'      => 20,
			'rate_limit_user'      => 60,
			'rate_limit_per_min'   => 20,
			'plan'                 => 'free',
			'monthly_query_cap'    => 500,
			'daily_query_cap'      => 0,
			'hide_branding'        => '0',
			'widget_title'         => '',
			'accent_color'         => '#0d9488',
			'agency_license_key'   => '',
			'public_api_key'       => '',
			'webhook_url'          => '',
		);
	}

	/**
	 * Whether a URL host is private / link-local (SSRF risk).
	 *
	 * @param string $url URL.
	 * @return bool True if blocked.
	 */
	public static function is_blocked_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return true;
		}
		$host = strtolower( $host );
		if ( in_array( $host, array( 'localhost', 'metadata.google.internal' ), true ) ) {
			return true;
		}
		$ip = gethostbyname( $host );
		if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// Unresolved hostname — allow (DNS may be external); wp_safe_remote_post still applies.
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
			// Link-local 169.254.0.0/16.
			if ( 0 === strpos( $ip, '169.254.' ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_product_index';
	}

	/** @return string */
	public static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_chat_sessions';
	}

	/** @return string */
	public static function query_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_query_log';
	}

	/** @return string */
	public static function click_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_click_log';
	}

	/** @return string */
	public static function rate_limits_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_rate_limits';
	}

	/** @return string */
	public static function usage_counters_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ai_usage_counters';
	}
}
