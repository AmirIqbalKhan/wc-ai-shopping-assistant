<?php
/**
 * Store-owner insights for unmatched demand.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Insights dashboard admin page.
 */
class WCAI_Insights {

	/**
	 * Hook menu.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 65 );
	}

	/**
	 * Register submenu.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'AI Insights', 'wc-ai-shopping-assistant' ),
			__( 'AI Insights', 'wc-ai-shopping-assistant' ),
			'manage_woocommerce',
			'wcai-insights',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render insights page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$days      = isset( $_GET['days'] ) ? max( 1, min( 90, absint( $_GET['days'] ) ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$unmatched = WCAI_Analytics::unmatched( $days );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Assistant Insights', 'wc-ai-shopping-assistant' ); ?></h1>
			<p><?php esc_html_e( 'Queries shoppers asked that returned weak or no catalog matches — useful for spotting inventory or content gaps.', 'wc-ai-shopping-assistant' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wcai-insights', 'days' => 7 ), admin_url( 'admin.php' ) ) ); ?>">7 <?php esc_html_e( 'days', 'wc-ai-shopping-assistant' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wcai-insights', 'days' => 30 ), admin_url( 'admin.php' ) ) ); ?>">30 <?php esc_html_e( 'days', 'wc-ai-shopping-assistant' ); ?></a>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Unmatched request', 'wc-ai-shopping-assistant' ); ?></th>
						<th><?php esc_html_e( 'Times asked', 'wc-ai-shopping-assistant' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $unmatched ) ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No unmatched queries in this period.', 'wc-ai-shopping-assistant' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $unmatched as $row ) : ?>
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
