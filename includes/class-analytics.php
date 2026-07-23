<?php
/**
 * Query / click analytics logging.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persists anonymized query and click events.
 */
class WCAI_Analytics {

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
	 * @param string   $query          Query text.
	 * @param string   $session_token  Session.
	 * @param int      $result_count   Products returned.
	 * @param bool     $matched        Whether strong matches existed.
	 * @param float|null $confidence   Best similarity score.
	 * @return int Insert ID.
	 */
	public static function log_query( string $query, string $session_token, int $result_count, bool $matched, ?float $confidence = null ): int {
		global $wpdb;
		$table = WCAI_Installer::query_log_table();

		$wpdb->insert(
			$table,
			array(
				'session_token' => $session_token ? substr( $session_token, 0, 64 ) : null,
				'query_text'    => mb_substr( $query, 0, 500 ),
				'query_hash'    => md5( strtolower( trim( $query ) ) ),
				'result_count'  => $result_count,
				'matched'       => $matched ? 1 : 0,
				'confidence'    => $confidence,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%f', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Log a product card click.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $query_id   Related query log ID.
	 * @param string $session    Session token.
	 */
	public static function log_click( int $product_id, int $query_id = 0, string $session = '' ): void {
		global $wpdb;
		$wpdb->insert(
			WCAI_Installer::click_log_table(),
			array(
				'query_id'      => $query_id ?: null,
				'product_id'    => $product_id,
				'session_token' => $session ? substr( $session, 0, 64 ) : null,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
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
				"SELECT COUNT(*) FROM {$ctable} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Render analytics admin page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$days  = isset( $_GET['days'] ) ? max( 1, min( 90, absint( $_GET['days'] ) ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stats = self::stats( $days );
		$used  = WCAI_Usage::used();
		$cap   = WCAI_Usage::monthly_cap();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Assistant Analytics', 'wc-ai-shopping-assistant' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: used 2: cap */
					esc_html__( 'Plan usage this month: %1$d / %2$d queries', 'wc-ai-shopping-assistant' ),
					$used,
					$cap
				);
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wcai-analytics', 'days' => 7 ), admin_url( 'admin.php' ) ) ); ?>">7 <?php esc_html_e( 'days', 'wc-ai-shopping-assistant' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wcai-analytics', 'days' => 30 ), admin_url( 'admin.php' ) ) ); ?>">30 <?php esc_html_e( 'days', 'wc-ai-shopping-assistant' ); ?></a>
			</p>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr><th><?php esc_html_e( 'Queries', 'wc-ai-shopping-assistant' ); ?></th><td><?php echo (int) $stats['queries']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Product clicks', 'wc-ai-shopping-assistant' ); ?></th><td><?php echo (int) $stats['clicks']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Click-through rate', 'wc-ai-shopping-assistant' ); ?></th><td><?php echo esc_html( (string) $stats['ctr'] ); ?>%</td></tr>
					<tr><th><?php esc_html_e( 'Unmatched queries', 'wc-ai-shopping-assistant' ); ?></th><td><?php echo (int) $stats['unmatched']; ?></td></tr>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Top queries', 'wc-ai-shopping-assistant' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Query', 'wc-ai-shopping-assistant' ); ?></th><th><?php esc_html_e( 'Count', 'wc-ai-shopping-assistant' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $stats['top'] ) ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No queries yet.', 'wc-ai-shopping-assistant' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $stats['top'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['query_text'] ); ?></td>
							<td><?php echo (int) $row['cnt']; ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
