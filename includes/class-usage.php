<?php
/**
 * Usage counters and soft spend caps.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks query/embed volume and enforces a daily soft-cap (default 500).
 */
class WCAI_Usage {

	const DEFAULT_DAILY_CAP = 500;

	/**
	 * Whether usage caps are enforced.
	 *
	 * @return bool
	 */
	public static function limits_enabled(): bool {
		return true;
	}

	/**
	 * Plan caps map (reserved; monthly override uses settings).
	 *
	 * @return array
	 */
	public static function plan_caps(): array {
		return array(
			'free'   => 0,
			'pro'    => 0,
			'agency' => 0,
		);
	}

	/**
	 * Effective monthly query cap. 0 = unlimited.
	 *
	 * @return int
	 */
	public static function monthly_cap(): int {
		if ( ! self::limits_enabled() ) {
			return 0;
		}
		return max( 0, (int) WCAI_Settings::get( 'monthly_query_cap', 0 ) );
	}

	/**
	 * Daily query ceiling. Default 500 when unset/invalid; 0 = unlimited.
	 *
	 * @return int
	 */
	public static function daily_cap(): int {
		if ( ! self::limits_enabled() ) {
			return 0;
		}
		$raw = WCAI_Settings::get( 'daily_query_cap', self::DEFAULT_DAILY_CAP );
		if ( null === $raw || '' === $raw ) {
			return self::DEFAULT_DAILY_CAP;
		}
		return max( 0, (int) $raw );
	}

	/**
	 * Current month key YYYY-MM.
	 *
	 * @return string
	 */
	public static function month_key(): string {
		return gmdate( 'Y-m' );
	}

	/**
	 * Current day key YYYY-MM-DD.
	 *
	 * @return string
	 */
	public static function day_key(): string {
		return gmdate( 'Y-m-d' );
	}

	/**
	 * Queries used this month.
	 *
	 * @return int
	 */
	public static function used(): int {
		return self::get_count( 'query_month', self::month_key() );
	}

	/**
	 * Queries used today.
	 *
	 * @return int
	 */
	public static function used_today(): int {
		return self::get_count( 'query_day', self::day_key() );
	}

	/**
	 * Embeddings used this month.
	 *
	 * @return int
	 */
	public static function embeds_used(): int {
		return self::get_count( 'embed_month', self::month_key() );
	}

	/**
	 * Cache key for a usage counter.
	 *
	 * @param string $counter Counter name.
	 * @param string $period  Period key.
	 * @return string
	 */
	private static function cache_key( string $counter, string $period ): string {
		return 'usage_' . $counter . '_' . $period;
	}

	/**
	 * Read counter.
	 *
	 * @param string $counter Counter name.
	 * @param string $period  Period key.
	 * @return int
	 */
	private static function get_count( string $counter, string $period ): int {
		$cache_key = self::cache_key( $counter, $period );
		$cached    = WCAI_DB::cache_get( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = WCAI_Installer::usage_counters_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above
		$val = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT hit_count FROM %i WHERE counter_key = %s AND period_key = %s',
				$table,
				$counter,
				$period
			)
		);
		if ( null !== $val ) {
			$count = (int) $val;
			WCAI_DB::cache_set( $cache_key, $count, 30 );
			return $count;
		}

		if ( 'query_month' === $counter ) {
			$data = get_option( 'wcai_usage', array() );
			if ( is_array( $data ) && ( $data['month'] ?? '' ) === $period ) {
				$count = (int) ( $data['count'] ?? 0 );
				WCAI_DB::cache_set( $cache_key, $count, 30 );
				return $count;
			}
		}
		WCAI_DB::cache_set( $cache_key, 0, 30 );
		return 0;
	}

	/**
	 * Atomically increment a counter.
	 *
	 * @param string $counter Counter name.
	 * @param string $period  Period key.
	 * @param int    $n       Amount.
	 */
	private static function bump( string $counter, string $period, int $n = 1 ): void {
		global $wpdb;
		$table = WCAI_Installer::usage_counters_table();
		$n     = max( 1, $n );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write path
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (counter_key, period_key, hit_count) VALUES (%s, %s, %d)
				ON DUPLICATE KEY UPDATE hit_count = hit_count + VALUES(hit_count)',
				$table,
				$counter,
				$period,
				$n
			)
		);
		WCAI_DB::cache_delete( self::cache_key( $counter, $period ) );
	}

	/**
	 * Whether another query/embed is allowed under soft-caps.
	 *
	 * @param string $kind query|embed.
	 * @return true|WP_Error
	 */
	public static function assert_allowed( string $kind = 'query' ) {
		if ( ! self::limits_enabled() ) {
			return true;
		}

		if ( 'query' === $kind ) {
			$daily = self::daily_cap();
			if ( $daily > 0 && self::used_today() >= $daily ) {
				return new WP_Error(
					'wcai_daily_limit',
					__( 'The store’s daily ShopAsk AI query limit has been reached. Please try again tomorrow.', 'shopask-ai-shopping-assistant' ),
					array( 'status' => 429 )
				);
			}
			$monthly = self::monthly_cap();
			if ( $monthly > 0 && self::used() >= $monthly ) {
				return new WP_Error(
					'wcai_monthly_limit',
					__( 'The store’s monthly ShopAsk AI query limit has been reached.', 'shopask-ai-shopping-assistant' ),
					array( 'status' => 429 )
				);
			}
		}

		return true;
	}

	/**
	 * Increment usage counter(s) for analytics.
	 *
	 * @param string $kind query|embed.
	 * @param int    $n    Units.
	 */
	public static function increment( string $kind = 'query', int $n = 1 ): void {
		$n = max( 1, $n );
		if ( 'embed' === $kind ) {
			self::bump( 'embed_month', self::month_key(), $n );
			return;
		}
		self::bump( 'query_month', self::month_key(), $n );
		self::bump( 'query_day', self::day_key(), $n );
	}
}
