<?php
/**
 * Public API helpers (webhook).
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Optional outbound webhook for query events.
 */
class WCAI_Public_API {

	/**
	 * POST anonymized payload to configured webhook URL.
	 *
	 * @param array $payload Query result payload.
	 */
	public static function maybe_send_webhook( array $payload ): void {
		$url = trim( (string) WCAI_Settings::get( 'webhook_url', '' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		$body = array(
			'event'         => 'wcai.query_completed',
			'session_token' => substr( (string) ( $payload['session_token'] ?? '' ), 0, 8 ) . '…',
			'product_ids'   => array_map(
				static function ( $p ) {
					return (int) ( $p['id'] ?? 0 );
				},
				$payload['products'] ?? array()
			),
			'product_count' => count( $payload['products'] ?? array() ),
			'ab_variant'    => $payload['ab_variant'] ?? 'control',
			'timestamp'     => gmdate( 'c' ),
		);

		wp_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
			)
		);
	}
}
