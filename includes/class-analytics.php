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
	 * Hook admin page.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
	}

	/**
	 * Analytics submenu.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'AI Analytics', 'wc-ai-shopping-assistant' ),
			__( 'AI Analytics', 'wc-ai-shopping-assistant' ),
			'manage_woocommerce',
			'wcai-analytics',
			array( __CLASS__, 'render_page' )
		);
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
	 * Stats for last N days.
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
	 * Render analytics page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$stats = self::stats( 30 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'AI Analytics', 'wc-ai-shopping-assistant' ); ?></h1>
			<p><?php echo esc_html__( 'Last 30 days. Query text is retained for 90 days then deleted automatically.', 'wc-ai-shopping-assistant' ); ?></p>
			<ul>
				<li><?php printf( /* translators: %d: count */ esc_html__( 'Queries: %d', 'wc-ai-shopping-assistant' ), (int) $stats['queries'] ); ?></li>
				<li><?php printf( /* translators: %d: count */ esc_html__( 'Validated clicks: %d', 'wc-ai-shopping-assistant' ), (int) $stats['clicks'] ); ?></li>
				<li><?php printf( /* translators: %s: percent */ esc_html__( 'CTR: %s%%', 'wc-ai-shopping-assistant' ), esc_html( (string) $stats['ctr'] ) ); ?></li>
				<li><?php printf( /* translators: %d: count */ esc_html__( 'Unmatched: %d', 'wc-ai-shopping-assistant' ), (int) $stats['unmatched'] ); ?></li>
				<li><?php printf( /* translators: 1: used 2: cap */ esc_html__( 'Monthly usage: %1$d / %2$d', 'wc-ai-shopping-assistant' ), (int) WCAI_Usage::used(), (int) WCAI_Usage::monthly_cap() ); ?></li>
			</ul>
			<h2><?php echo esc_html__( 'Top queries', 'wc-ai-shopping-assistant' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Query', 'wc-ai-shopping-assistant' ); ?></th><th><?php echo esc_html__( 'Count', 'wc-ai-shopping-assistant' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $stats['top'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['query_text'] ); ?></td>
						<td><?php echo esc_html( (string) $row['cnt'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
