<?php
/**
 * Usage plan caps and atomic counters.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Soft local usage tiers (queries + embedding units).
 */
class WCAI_Usage {

	/**
	 * Plan caps (queries per calendar month).
	 *
	 * @return array
	 */
	public static function plan_caps(): array {
		return array(
			'free'   => 500,
			'pro'    => 5000,
			'agency' => 50000,
		);
	}

	/**
	 * Effective monthly query cap from settings/plan.
	 *
	 * @return int
	 */
	public static function monthly_cap(): int {
		$plan     = (string) WCAI_Settings::get( 'plan', 'free' );
		$caps     = self::plan_caps();
		$cap      = $caps[ $plan ] ?? 500;
		$override = (int) WCAI_Settings::get( 'monthly_query_cap', 0 );
		if ( $override > 0 ) {
			$cap = $override;
		}
		return max( 1, $cap );
	}

	/**
	 * Daily query ceiling.
	 *
	 * @return int
	 */
	public static function daily_cap(): int {
		$override = (int) WCAI_Settings::get( 'daily_query_cap', 0 );
		if ( $override > 0 ) {
			return max( 1, $override );
		}
		return max( 50, (int) ceil( self::monthly_cap() / 30 ) );
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
	 * Read counter.
	 *
	 * @param string $counter Counter name.
	 * @param string $period  Period key.
	 * @return int
	 */
	private static function get_count( string $counter, string $period ): int {
		global $wpdb;
		$table = WCAI_Installer::usage_counters_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hit_count FROM {$table} WHERE counter_key = %s AND period_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$counter,
				$period
			)
		);
		if ( null !== $val ) {
			return (int) $val;
		}

		// Legacy option fallback for month queries.
		if ( 'query_month' === $counter ) {
			$data = get_option( 'wcai_usage', array() );
			if ( is_array( $data ) && ( $data['month'] ?? '' ) === $period ) {
				return (int) ( $data['count'] ?? 0 );
			}
		}
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
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (counter_key, period_key, hit_count) VALUES (%s, %s, %d)
				ON DUPLICATE KEY UPDATE hit_count = hit_count + VALUES(hit_count)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$counter,
				$period,
				$n
			)
		);
	}

	/**
	 * Whether another query is allowed (monthly + daily).
	 *
	 * @param string $kind query|embed.
	 * @return true|WP_Error
	 */
	public static function assert_allowed( string $kind = 'query' ) {
		if ( 'embed' === $kind ) {
			// Embeddings share the monthly query budget (1 embed unit ≈ 1 query unit).
			if ( self::embeds_used() + self::used() >= self::monthly_cap() ) {
				return new WP_Error(
					'wcai_plan_limit',
					__( 'Monthly AI usage limit reached (queries + indexing). Upgrade the plan in AI Assistant settings.', 'wc-ai-shopping-assistant' ),
					array( 'status' => 402 )
				);
			}
			return true;
		}

		if ( self::used() >= self::monthly_cap() ) {
			return new WP_Error(
				'wcai_plan_limit',
				__( 'Monthly query limit reached for this plan. Upgrade the plan in AI Assistant settings.', 'wc-ai-shopping-assistant' ),
				array( 'status' => 402 )
			);
		}
		if ( self::used_today() >= self::daily_cap() ) {
			return new WP_Error(
				'wcai_daily_limit',
				__( 'Daily query limit reached. Try again tomorrow or raise the daily cap in settings.', 'wc-ai-shopping-assistant' ),
				array( 'status' => 402 )
			);
		}
		return true;
	}

	/**
	 * Increment usage counter(s).
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
