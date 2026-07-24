<?php
/**
 * Whitelisted table names and object-cache helpers.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves plugin table names from a fixed suffix map (never from user input).
 */
class WCAI_DB {

	const CACHE_GROUP = 'shopask_ai';

	/**
	 * Suffix keys → unprefixed table names.
	 *
	 * @var array<string, string>
	 */
	const TABLES = array(
		'product_index'   => 'ai_product_index',
		'sessions'        => 'ai_chat_sessions',
		'query_log'       => 'ai_query_log',
		'click_log'       => 'ai_click_log',
		'rate_limits'     => 'ai_rate_limits',
		'usage_counters'  => 'ai_usage_counters',
	);

	/**
	 * Full table name for a whitelisted key.
	 *
	 * @param string $key Suffix key from TABLES.
	 * @return string Empty string if unknown.
	 */
	public static function table( string $key ): string {
		if ( ! isset( self::TABLES[ $key ] ) ) {
			return '';
		}
		global $wpdb;
		return $wpdb->prefix . self::TABLES[ $key ];
	}

	/**
	 * All plugin table names (for uninstall / DROP).
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		$out = array();
		foreach ( array_keys( self::TABLES ) as $key ) {
			$name = self::table( $key );
			if ( $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Object-cache get.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	public static function cache_get( string $key ) {
		return wp_cache_get( $key, self::CACHE_GROUP );
	}

	/**
	 * Object-cache set.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 * @return bool
	 */
	public static function cache_set( string $key, $value, int $ttl = 60 ): bool {
		return wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
	}

	/**
	 * Object-cache delete.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public static function cache_delete( string $key ): bool {
		return wp_cache_delete( $key, self::CACHE_GROUP );
	}
}
