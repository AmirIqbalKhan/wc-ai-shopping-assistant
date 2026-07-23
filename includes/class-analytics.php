<?php
/**
 * Query / click analytics logging.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persists query and click events with retention.
 */
class WCAI_Analytics {

	const RETENTION_DAYS = 90;

	/**
	 * Hook cleanup (UI is served via AI Assistant hub tabs).
	 */
	public static function init(): void {
		// Intentionally no separate submenu — see WCAI_Settings::render_page().
	}

	/**
	 * Log a query event.
	 *
	 * @param string     $query          Query text.
	 * @param string     $session_token  Session.
	 * @param int        $result_count   Products returned.
	 * @param bool       $matched        Whether strong matches existed.
	 * @param float|null $confidence     Best similarity score.
	 * @param int[]      $product_ids    Result product IDs.
	 * @return int Insert ID.
	 */
	public static function log_query( string $query, string $session_token, int $result_count, bool $matched, ?float $confidence = null, array $product_ids = array() ): int {
		global $wpdb;
		$table = WCAI_Installer::query_log_table();

		$ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );

		$wpdb->insert(
			$table,
			array(
				'session_token'      => $session_token ? substr( $session_token, 0, 64 ) : null,
				'query_text'         => function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 500 ) : substr( $query, 0, 500 ),
				'query_hash'         => md5( strtolower( trim( $query ) ) ),
				'result_count'       => $result_count,
				'result_product_ids' => wp_json_encode( $ids ),
				'matched'            => $matched ? 1 : 0,
				'confidence'         => $confidence,
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%f', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Log a product card click with validation.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $query_id   Related query log ID.
	 * @param string $session    Session token.
	 * @return true|WP_Error|'duplicate'
	 */
	public static function log_click( int $product_id, int $query_id = 0, string $session = '' ) {
		global $wpdb;

		if ( ! $product_id || ! $query_id || '' === $session ) {
			return new WP_Error(
				'wcai_click_invalid',
				__( 'Click requires product_id, query_id, and session_token.', 'wc-ai-shopping-assistant' ),
				array( 'status' => 400 )
			);
		}

		$qtable = WCAI_Installer::query_log_table();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, session_token, result_product_ids FROM {$qtable} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'wcai_click_unknown_query', __( 'Unknown query.', 'wc-ai-shopping-assistant' ), array( 'status' => 403 ) );
		}

		if ( (string) ( $row['session_token'] ?? '' ) !== substr( $session, 0, 64 ) ) {
			return new WP_Error( 'wcai_click_session', __( 'Session does not match query.', 'wc-ai-shopping-assistant' ), array( 'status' => 403 ) );
		}

		$allowed = json_decode( (string) ( $row['result_product_ids'] ?? '[]' ), true );
		if ( ! is_array( $allowed ) ) {
			$allowed = array();
		}
		$allowed = array_map( 'intval', $allowed );
		if ( ! in_array( $product_id, $allowed, true ) ) {
			return new WP_Error( 'wcai_click_product', __( 'Product was not in query results.', 'wc-ai-shopping-assistant' ), array( 'status' => 403 ) );
		}

		$ctable = WCAI_Installer::click_log_table();
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$ctable} WHERE query_id = %d AND product_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_id,
				$product_id
			)
		);
		if ( $exists ) {
			return 'duplicate';
		}

		$ok = $wpdb->insert(
			$ctable,
			array(
				'query_id'      => $query_id,
				'product_id'    => $product_id,
				'session_token' => substr( $session, 0, 64 ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( false === $ok ) {
			// Unique race → treat as duplicate.
			if ( false !== strpos( (string) $wpdb->last_error, 'Duplicate' ) ) {
				return 'duplicate';
			}
			return new WP_Error( 'wcai_click_db', __( 'Could not log click.', 'wc-ai-shopping-assistant' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Delete analytics older than retention window.
	 */
	public static function cleanup_retention(): void {
		global $wpdb;
		$since  = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$qtable = WCAI_Installer::query_log_table();
		$ctable = WCAI_Installer::click_log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ctable} WHERE created_at < %s", $since ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$qtable} WHERE created_at < %s", $since ) );
	}

	/**
	 * Aggregate stats for a period.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function stats( int $days = 30 ): array {
		global $wpdb;
		$qtable = WCAI_Installer::query_log_table();
		$ctable = WCAI_Installer::click_log_table();
		$since  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$queries = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$qtable} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);
		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$ctable} c
				INNER JOIN {$qtable} q ON q.id = c.query_id
				WHERE c.created_at >= %s AND q.created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				$since
			)
		);
		$unmatched = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$qtable} WHERE created_at >= %s AND matched = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);

		$top = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text, COUNT(*) AS cnt FROM {$qtable} WHERE created_at >= %s GROUP BY query_hash ORDER BY cnt DESC LIMIT 15", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		) ?: array();

		$ctr = $queries > 0 ? round( ( $clicks / $queries ) * 100, 1 ) : 0.0;

		return array(
			'queries'   => $queries,
			'clicks'    => $clicks,
			'ctr'       => $ctr,
			'unmatched' => $unmatched,
			'top'       => $top,
		);
	}

	/**
	 * Unmatched queries for insights.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function unmatched( int $days = 30 ): array {
		global $wpdb;
		$qtable = WCAI_Installer::query_log_table();
		$since  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text, COUNT(*) AS cnt FROM {$qtable} WHERE created_at >= %s AND matched = 0 GROUP BY query_hash ORDER BY cnt DESC LIMIT 30", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Resolve period from request (7 / 30 / 90).
	 *
	 * @return int
	 */
	public static function request_days(): int {
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) {
			return 30;
		}
		return $days;
	}

	/**
	 * Period chip navigation for hub tabs.
	 *
	 * @param string $tab  analytics|insights.
	 * @param int    $days Active days.
	 */
	public static function render_period_chips( string $tab, int $days ): void {
		echo '<div class="wcai-period" role="navigation" aria-label="' . esc_attr__( 'Time period', 'wc-ai-shopping-assistant' ) . '">';
		foreach ( array( 7, 30, 90 ) as $d ) {
			printf(
				'<a class="%s" href="%s">%s</a>',
				$d === $days ? 'is-active' : '',
				esc_url( WCAI_Admin::url( $tab, array( 'days' => $d ) ) ),
				esc_html(
					sprintf(
						/* translators: %d: number of days */
						_n( '%d day', '%d days', $d, 'wc-ai-shopping-assistant' ),
						$d
					)
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Render analytics tab (inside hub wrap).
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$days  = self::request_days();
		$stats = self::stats( $days );
		self::render_period_chips( 'analytics', $days );
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %d: retention days */
				esc_html__( 'Query text is retained for %d days then deleted automatically.', 'wc-ai-shopping-assistant' ),
				(int) self::RETENTION_DAYS
			);
			?>
		</p>

		<div class="wcai-metrics">
			<div class="wcai-metric">
				<span class="wcai-metric__label"><?php esc_html_e( 'Queries', 'wc-ai-shopping-assistant' ); ?></span>
				<span class="wcai-metric__value"><?php echo esc_html( (string) $stats['queries'] ); ?></span>
			</div>
			<div class="wcai-metric">
				<span class="wcai-metric__label"><?php esc_html_e( 'Clicks', 'wc-ai-shopping-assistant' ); ?></span>
				<span class="wcai-metric__value"><?php echo esc_html( (string) $stats['clicks'] ); ?></span>
			</div>
			<div class="wcai-metric">
				<span class="wcai-metric__label"><?php esc_html_e( 'CTR', 'wc-ai-shopping-assistant' ); ?></span>
				<span class="wcai-metric__value"><?php echo esc_html( (string) $stats['ctr'] ); ?>%</span>
			</div>
			<div class="wcai-metric">
				<span class="wcai-metric__label"><?php esc_html_e( 'Unmatched', 'wc-ai-shopping-assistant' ); ?></span>
				<span class="wcai-metric__value"><?php echo esc_html( (string) $stats['unmatched'] ); ?></span>
			</div>
		</div>

		<p class="wcai-link-row">
			<a href="<?php echo esc_url( WCAI_Admin::url( 'insights', array( 'days' => $days ) ) ); ?>">
				<?php esc_html_e( 'View unmatched demand →', 'wc-ai-shopping-assistant' ); ?>
			</a>
		</p>

		<section class="wcai-card">
			<h2><?php esc_html_e( 'Top queries', 'wc-ai-shopping-assistant' ); ?></h2>
			<?php if ( empty( $stats['top'] ) ) : ?>
				<div class="wcai-empty-state">
					<p><?php esc_html_e( 'No queries yet in this period. Shoppers’ searches will appear here once the assistant is live.', 'wc-ai-shopping-assistant' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Query', 'wc-ai-shopping-assistant' ); ?></th><th><?php esc_html_e( 'Count', 'wc-ai-shopping-assistant' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $stats['top'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['query_text'] ); ?></td>
							<td><?php echo esc_html( (string) $row['cnt'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}
}
