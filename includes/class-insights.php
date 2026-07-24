<?php
/**
 * Store-owner insights for unmatched demand.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Insights dashboard (hub tab).
 */
class WCAI_Insights {

	/**
	 * Hook (UI is served via AI Assistant hub tabs).
	 */
	public static function init(): void {
		// Intentionally no separate submenu — see WCAI_Settings::render_page().
	}

	/**
	 * Render insights tab (inside hub wrap).
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$days      = WCAI_Analytics::request_days();
		$unmatched = WCAI_Analytics::unmatched( $days );
		WCAI_Analytics::render_period_chips( 'insights', $days );
		?>
		<p class="description"><?php esc_html_e( 'Queries shoppers asked that returned weak or no catalog matches — useful for spotting inventory or content gaps.', 'shopask-ai-shopping-assistant' ); ?></p>

		<p class="wcai-link-row">
			<a href="<?php echo esc_url( WCAI_Admin::url( 'analytics', array( 'days' => $days ) ) ); ?>">
				<?php esc_html_e( '← Back to analytics', 'shopask-ai-shopping-assistant' ); ?>
			</a>
		</p>

		<section class="wcai-card">
			<h2><?php esc_html_e( 'Unmatched demand', 'shopask-ai-shopping-assistant' ); ?></h2>
			<?php if ( empty( $unmatched ) ) : ?>
				<div class="wcai-empty-state">
					<p><?php esc_html_e( 'No unmatched queries in this period. When shoppers ask for items you do not stock, they will show up here.', 'shopask-ai-shopping-assistant' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Unmatched request', 'shopask-ai-shopping-assistant' ); ?></th>
							<th><?php esc_html_e( 'Times asked', 'shopask-ai-shopping-assistant' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $unmatched as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['query_text'] ); ?></td>
							<td><?php echo (int) $row['cnt']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}
}
