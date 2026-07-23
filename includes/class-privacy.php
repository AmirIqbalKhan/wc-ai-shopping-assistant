<?php
/**
 * Privacy exporter / eraser for query and click logs.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * GDPR tools — shopper free-text may contain personal data.
 */
class WCAI_Privacy {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wc-ai-shopping-assistant'] = array(
			'exporter_friendly_name' => __( 'WooCommerce AI Shopping Assistant', 'wc-ai-shopping-assistant' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['wc-ai-shopping-assistant'] = array(
			'eraser_friendly_name' => __( 'WooCommerce AI Shopping Assistant', 'wc-ai-shopping-assistant' ),
			'callback'             => array( __CLASS__, 'eraser' ),
		);
		return $erasers;
	}

	/**
	 * Suggest privacy policy text.
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = __(
			'When you use the on-site AI shopping assistant, your search text (up to 500 characters) and session token may be stored for analytics for up to 90 days, and may be sent to the configured AI provider to generate product recommendations. Product recommendations use catalog data only.',
			'wc-ai-shopping-assistant'
		);
		wp_add_privacy_policy_content( 'WooCommerce AI Shopping Assistant', wp_kses_post( wpautop( $content ) ) );
	}

	/**
	 * Export by email — we key off session tokens stored in user meta if present; otherwise empty.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$token = (string) get_user_meta( $user->ID, 'wcai_session_token', true );
		if ( '' === $token ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		$qtable = WCAI_Installer::query_log_table();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text, created_at FROM {$qtable} WHERE session_token = %s ORDER BY id DESC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token
			),
			ARRAY_A
		) ?: array();

		$export = array();
		foreach ( $rows as $row ) {
			$export[] = array(
				'name'  => __( 'AI assistant query', 'wc-ai-shopping-assistant' ),
				'value' => (string) $row['query_text'] . ' @ ' . (string) $row['created_at'],
			);
		}

		return array(
			'data' => $export
				? array(
					array(
						'group_id'          => 'wcai',
						'group_label'       => __( 'AI Shopping Assistant', 'wc-ai-shopping-assistant' ),
						'group_description' => __( 'Queries submitted to the shopping assistant.', 'wc-ai-shopping-assistant' ),
						'item_id'           => 'wcai-queries',
						'data'              => $export,
					),
				)
				: array(),
			'done' => true,
		);
	}

	/**
	 * Erase analytics rows for a user's stored session token.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function eraser( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$token = (string) get_user_meta( $user->ID, 'wcai_session_token', true );
		if ( '' === $token ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;
		$qtable = WCAI_Installer::query_log_table();
		$ctable = WCAI_Installer::click_log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ctable} WHERE session_token = %s", $token ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$qtable} WHERE session_token = %s", $token ) );
		delete_user_meta( $user->ID, 'wcai_session_token' );

		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array( __( 'AI assistant query and click logs removed for this user session.', 'wc-ai-shopping-assistant' ) ),
			'done'           => true,
		);
	}
}
