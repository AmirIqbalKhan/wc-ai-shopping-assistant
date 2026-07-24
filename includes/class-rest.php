<?php
/**
 * REST API for chat queries, clicks, and public search.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers /wcai/v1 routes and durable rate limiting.
 */
class WCAI_REST {

	/**
	 * Hook REST routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register endpoints.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'wcai/v1',
			'/query',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_query' ),
				'permission_callback' => array( __CLASS__, 'query_permission' ),
				'args'                => array(
					'query'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'session_token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'wcai/v1',
			'/click',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_click' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id'    => array(
						'required' => true,
						'type'     => 'integer',
					),
					'query_id'      => array(
						'required' => true,
						'type'     => 'integer',
					),
					'session_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'wcai/v1',
			'/search',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'handle_public_search' ),
				'permission_callback' => array( __CLASS__, 'public_search_permission' ),
				'args'                => array(
					'query' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'q'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'wcai/v1',
			'/reindex-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_reindex_status' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);

		register_rest_route(
			'wcai/v1',
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_test_connection' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * Browser chat requires a valid wp_rest nonce (localized on the storefront).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function query_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( is_string( $nonce ) && '' !== $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error(
			'wcai_rest_forbidden',
			__( 'Missing or invalid REST nonce.', 'shopask-ai-shopping-assistant' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Permission for public search: manage_woocommerce or X-WCAI-API-Key header only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function public_search_permission( WP_REST_Request $request ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$key = (string) WCAI_Settings::get( 'public_api_key', '' );
		if ( '' === $key ) {
			return false;
		}
		$provided = $request->get_header( 'X-WCAI-API-Key' );
		return is_string( $provided ) && '' !== $provided && hash_equals( $key, $provided );
	}

	/**
	 * Handle a shopper query (multi-turn).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_query( WP_REST_Request $request ) {
		$limit_check = self::check_rate_limit();
		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}

		$usage = WCAI_Usage::assert_allowed( 'query' );
		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		if ( ! WCAI_Settings::get( 'api_key' ) ) {
			return new WP_Error(
				'wcai_not_configured',
				__( 'The AI assistant is not configured yet.', 'shopask-ai-shopping-assistant' ),
				array( 'status' => 503 )
			);
		}

		$query = trim( (string) $request->get_param( 'query' ) );
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $query ) : strlen( $query );
		if ( '' === $query || $len > 500 ) {
			return new WP_Error(
				'wcai_bad_query',
				__( 'Please enter a query between 1 and 500 characters.', 'shopask-ai-shopping-assistant' ),
				array( 'status' => 400 )
			);
		}

		$session = WCAI_Session::get_or_create( (string) $request->get_param( 'session_token' ) );

		$candidates = WCAI_Retrieval::search( $query, $session );
		if ( is_wp_error( $candidates ) ) {
			$status = $candidates->get_error_data()['status'] ?? 500;
			$candidates->add_data( array( 'status' => (int) $status ) );
			return $candidates;
		}

		$result = WCAI_Agent::respond( $query, $candidates, $session );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$best = 0.0;
		foreach ( $candidates as $c ) {
			$best = max( $best, (float) ( $c['score'] ?? 0 ) );
		}
		$matched     = ! empty( $result['products'] );
		$product_ids = array_map(
			static function ( $p ) {
				return (int) ( $p['id'] ?? 0 );
			},
			$result['products'] ?? array()
		);
		$query_id    = WCAI_Analytics::log_query(
			$query,
			$session['session_token'],
			count( $result['products'] ?? array() ),
			$matched,
			$candidates ? $best : null,
			$product_ids
		);

		$constraints = is_array( $result['constraints'] ?? null ) ? $result['constraints'] : array();
		$session     = WCAI_Session::after_turn( $session, $query, $result, $constraints );

		WCAI_Usage::increment( 'query' );

		$payload = array(
			'session_token'       => $session['session_token'],
			'query_id'            => $query_id,
			'reply_text'          => $result['reply_text'] ?? '',
			'products'            => $result['products'] ?? array(),
			'clarifying_question' => $result['clarifying_question'] ?? null,
			'ab_variant'          => $result['ab_variant'] ?? 'control',
		);

		do_action( 'wcai_query_completed', $payload );
		WCAI_Public_API::maybe_send_webhook( $payload );

		return rest_ensure_response( $payload );
	}

	/**
	 * Log product card click.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_click( WP_REST_Request $request ) {
		$limit_check = self::check_rate_limit( 'click' );
		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}

		$product_id = absint( $request->get_param( 'product_id' ) );
		$query_id   = absint( $request->get_param( 'query_id' ) );
		$session    = (string) $request->get_param( 'session_token' );

		$result = WCAI_Analytics::log_click( $product_id, $query_id, $session );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'duplicate' === $result ) {
			return rest_ensure_response(
				array(
					'ok'        => true,
					'duplicate' => true,
				)
			);
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Public search for themes/apps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_public_search( WP_REST_Request $request ) {
		$limit_check = self::check_rate_limit( 'search' );
		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}

		$usage = WCAI_Usage::assert_allowed( 'query' );
		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		$query = trim( (string) ( $request->get_param( 'query' ) ?: $request->get_param( 'q' ) ) );
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $query ) : strlen( $query );
		if ( '' === $query || $len > 500 ) {
			return new WP_Error(
				'wcai_bad_query',
				__( 'Please enter a query between 1 and 500 characters.', 'shopask-ai-shopping-assistant' ),
				array( 'status' => 400 )
			);
		}

		$session = array(
			'session_token'     => 'api',
			'constraints'       => array(),
			'shown_product_ids' => array(),
			'history'           => array(),
			'turn_count'        => 0,
		);

		$candidates = WCAI_Retrieval::search( $query, $session );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$result = WCAI_Agent::respond( $query, $candidates, $session );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$product_ids = array_map(
			static function ( $p ) {
				return (int) ( $p['id'] ?? 0 );
			},
			$result['products'] ?? array()
		);

		WCAI_Usage::increment( 'query' );
		WCAI_Analytics::log_query( $query, 'api', count( $result['products'] ?? array() ), ! empty( $result['products'] ), null, $product_ids );

		return rest_ensure_response(
			array(
				'reply_text' => $result['reply_text'] ?? '',
				'products'   => $result['products'] ?? array(),
			)
		);
	}

	/**
	 * Admin connection test.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_test_connection() {
		if ( ! WCAI_Settings::get( 'api_key' ) ) {
			return new WP_Error( 'wcai_no_api_key', __( 'API key is not configured.', 'shopask-ai-shopping-assistant' ), array( 'status' => 400 ) );
		}
		$result = WCAI_OpenAI_Client::chat_json(
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with JSON only: {"ok":true}',
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 502 )
			);
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'provider' => WCAI_OpenAI_Client::provider(),
				'api_base' => WCAI_OpenAI_Client::api_base(),
			)
		);
	}

	/**
	 * Reindex progress for admin UI.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_reindex_status() {
		$state = get_option( 'wcai_reindex_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return rest_ensure_response(
			array_merge(
				array(
					'status' => 'idle',
					'total'  => 0,
					'done'   => 0,
				),
				$state
			)
		);
	}

	/**
	 * Durable per-IP (anon) or per-user rate limit via custom table.
	 *
	 * @param string $bucket Optional bucket suffix.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( string $bucket = 'query' ) {
		global $wpdb;

		if ( is_user_logged_in() ) {
			$limit = (int) WCAI_Settings::get( 'rate_limit_user', 60 );
			$key   = 'u_' . get_current_user_id() . '_' . $bucket;
		} else {
			$limit = (int) WCAI_Settings::get( 'rate_limit_anon', WCAI_Settings::get( 'rate_limit_per_min', 20 ) );
			$key   = 'a_' . md5( self::client_ip() ) . '_' . $bucket;
		}

		$table     = WCAI_Installer::rate_limits_table();
		$now       = time();
		$start     = $now;
		$cache_key = 'rate_' . $key;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write path
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (rate_key, window_start, hit_count) VALUES (%s, %d, 1)
				ON DUPLICATE KEY UPDATE
					hit_count = IF( ( %d - window_start ) >= %d, 1, hit_count + 1 ),
					window_start = IF( ( %d - window_start ) >= %d, %d, window_start )',
				$table,
				$key,
				$start,
				$now,
				MINUTE_IN_SECONDS,
				$now,
				MINUTE_IN_SECONDS,
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- immediately after write
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT hit_count FROM %i WHERE rate_key = %s',
				$table,
				$key
			)
		);
		WCAI_DB::cache_set( $cache_key, $count, 60 );

		if ( $count > $limit ) {
			return new WP_Error(
				'wcai_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'shopask-ai-shopping-assistant' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Best-effort client IP (REMOTE_ADDR only).
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Prune stale rate-limit rows.
	 */
	public static function cleanup_rate_limits(): void {
		global $wpdb;
		$table = WCAI_Installer::rate_limits_table();
		$cut   = time() - ( 2 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled prune
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE window_start < %d', $table, $cut ) );
		delete_option( 'wcai_rate_limit_keys' );
	}
}
