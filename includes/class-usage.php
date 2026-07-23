<?php
/**
 * Usage plan caps and monthly counters.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Soft local usage tiers (no payment gateway).
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
	 * Effective monthly cap from settings/plan.
	 *
	 * @return int
	 */
	public static function monthly_cap(): int {
		$plan = (string) WCAI_Settings::get( 'plan', 'free' );
		$caps = self::plan_caps();
		$cap  = $caps[ $plan ] ?? 500;
		$override = (int) WCAI_Settings::get( 'monthly_query_cap', 0 );
		if ( $override > 0 ) {
			$cap = $override;
		}
		return max( 1, $cap );
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
	 * Queries used this month.
	 *
	 * @return int
	 */
	public static function used(): int {
		$data = get_option( 'wcai_usage', array() );
		if ( ! is_array( $data ) || ( $data['month'] ?? '' ) !== self::month_key() ) {
			return 0;
		}
		return (int) ( $data['count'] ?? 0 );
	}

	/**
	 * Whether another query is allowed.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_allowed() {
		if ( self::used() >= self::monthly_cap() ) {
			return new WP_Error(
				'wcai_plan_limit',
				__( 'Monthly query limit reached for this plan. Upgrade the plan in AI Assistant settings.', 'wc-ai-shopping-assistant' ),
				array( 'status' => 402 )
			);
		}
		return true;
	}

	/**
	 * Increment usage counter.
	 */
	public static function increment(): void {
		$month = self::month_key();
		$data  = get_option( 'wcai_usage', array() );
		if ( ! is_array( $data ) || ( $data['month'] ?? '' ) !== $month ) {
			$data = array(
				'month' => $month,
				'count' => 0,
			);
		}
		$data['count'] = (int) $data['count'] + 1;
		update_option( 'wcai_usage', $data, false );
	}
}
