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
		$exporters['shopask-ai-shopping-assistant'] = array(
			'exporter_friendly_name' => __( 'ShopAsk AI – Shopping Assistant for WooCommerce', 'shopask-ai-shopping-assistant' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['shopask-ai-shopping-assistant'] = array(
			'eraser_friendly_name' => __( 'ShopAsk AI – Shopping Assistant for WooCommerce', 'shopask-ai-shopping-assistant' ),
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
			'When you use ShopAsk AI on this site, your search text (up to 500 characters) and a session token may be stored in this site’s database for analytics for up to 90 days. The same search text, together with short product catalog snippets (titles and descriptions), may be sent to the AI provider configured by the store owner (for example OpenAI, Anthropic Claude, Google Gemini, LongCat, OpenRouter, or a custom API base URL) so ShopAsk can return product recommendations. Catalog indexing may also send product titles and descriptions to that provider to create search embeddings. If the store owner enables an optional webhook, search-related data may be sent to the HTTPS URL they configure. Recommendations are limited to products in the store catalog. This plugin does not send data to the plugin author’s servers.',
			'shopask-ai-shopping-assistant'
		);
		wp_add_privacy_policy_content( 'ShopAsk AI – Shopping Assistant for WooCommerce', wp_kses_post( wpautop( $content ) ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy export one-off
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT query_text, created_at FROM %i WHERE session_token = %s ORDER BY id DESC LIMIT 500',
				$qtable,
				$token
			),
			ARRAY_A
		) ?: array();

		$export = array();
		foreach ( $rows as $row ) {
			$export[] = array(
				'name'  => __( 'ShopAsk AI query', 'shopask-ai-shopping-assistant' ),
				'value' => (string) $row['query_text'] . ' @ ' . (string) $row['created_at'],
			);
		}

		return array(
			'data' => $export
				? array(
					array(
						'group_id'          => 'shopask',
						'group_label'       => __( 'ShopAsk AI', 'shopask-ai-shopping-assistant' ),
						'group_description' => __( 'Queries submitted to ShopAsk AI.', 'shopask-ai-shopping-assistant' ),
						'item_id'           => 'shopask-queries',
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy erase
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_token = %s', $ctable, $token ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy erase
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_token = %s', $qtable, $token ) );
		delete_user_meta( $user->ID, 'wcai_session_token' );

		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array( __( 'ShopAsk AI query and click logs removed for this user session.', 'shopask-ai-shopping-assistant' ) ),
			'done'           => true,
		);
	}
}
