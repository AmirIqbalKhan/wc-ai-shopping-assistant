<?php
/**
 * REST API for chat queries, clicks, and public search.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers /wcai/v1 routes and rate limiting.
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
				'permission_callback' => '__return_true',
				'args'                => array(
					'query' => array(
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
					'product_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'query_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'session_token' => array(
						'required' => false,
						'type'     => 'string',
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
	}

	/**
	 * Permission for public search: manage_woocommerce or API key header.
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
		if ( ! $provided ) {
			$provided = $request->get_param( 'api_key' );
		}
		return is_string( $provided ) && hash_equals( $key, $provided );
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

		$usage = WCAI_Usage::assert_allowed();
		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		if ( ! WCAI_Settings::get( 'api_key' ) ) {
			return new WP_Error(
				'wcai_not_configured',
				__( 'The AI assistant is not configured yet.', 'wc-ai-shopping-assistant' ),
				array( 'status' => 503 )
			);
		}

		$query = trim( (string) $request->get_param( 'query' ) );
		if ( '' === $query || mb_strlen( $query ) > 500 ) {
			return new WP_Error(
				'wcai_bad_query',
				__( 'Please enter a query between 1 and 500 characters.', 'wc-ai-shopping-assistant' ),
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
		$matched  = ! empty( $result['products'] );
		$query_id = WCAI_Analytics::log_query(
			$query,
			$session['session_token'],
			count( $result['products'] ?? array() ),
			$matched,
			$candidates ? $best : null
		);

		$constraints = is_array( $result['constraints'] ?? null ) ? $result['constraints'] : array();
		$session     = WCAI_Session::after_turn( $session, $query, $result, $constraints );

		WCAI_Usage::increment();

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
		$product_id = absint( $request->get_param( 'product_id' ) );
		if ( ! $product_id ) {
			return new WP_Error( 'wcai_bad_product', __( 'Invalid product.', 'wc-ai-shopping-assistant' ), array( 'status' => 400 ) );
		}
		WCAI_Analytics::log_click(
			$product_id,
			absint( $request->get_param( 'query_id' ) ),
			(string) $request->get_param( 'session_token' )
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Public search for themes/apps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_public_search( WP_REST_Request $request ) {
		$usage = WCAI_Usage::assert_allowed();
		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		$query = trim( (string) ( $request->get_param( 'query' ) ?: $request->get_param( 'q' ) ) );
		if ( '' === $query ) {
			return new WP_Error( 'wcai_bad_query', __( 'Query required.', 'wc-ai-shopping-assistant' ), array( 'status' => 400 ) );
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

		WCAI_Usage::increment();
		WCAI_Analytics::log_query( $query, 'api', count( $result['products'] ?? array() ), ! empty( $result['products'] ), null );

		return rest_ensure_response(
			array(
				'reply_text' => $result['reply_text'] ?? '',
				'products'   => $result['products'] ?? array(),
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
	 * Per-IP (anon) or per-user rate limit.
	 *
	 * @return true|WP_Error
	 */
	private static function check_rate_limit() {
		if ( is_user_logged_in() ) {
			$limit = (int) WCAI_Settings::get( 'rate_limit_user', 60 );
			$key   = 'wcai_rl_u_' . get_current_user_id();
		} else {
			$limit = (int) WCAI_Settings::get( 'rate_limit_anon', WCAI_Settings::get( 'rate_limit_per_min', 20 ) );
			$key   = 'wcai_rl_a_' . md5( self::client_ip() );
		}

		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		if ( ( time() - (int) $data['start'] ) >= MINUTE_IN_SECONDS ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, MINUTE_IN_SECONDS );

		$keys = get_option( 'wcai_rate_limit_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		$keys[ $key ] = time();
		update_option( 'wcai_rate_limit_keys', $keys, false );

		if ( $data['count'] > $limit ) {
			return new WP_Error(
				'wcai_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'wc-ai-shopping-assistant' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Prune stale rate-limit key tracking option.
	 */
	public static function cleanup_rate_limits(): void {
		$keys = get_option( 'wcai_rate_limit_keys', array() );
		if ( ! is_array( $keys ) ) {
			return;
		}

		$now     = time();
		$trimmed = array();
		foreach ( $keys as $key => $ts ) {
			if ( ( $now - (int) $ts ) < HOUR_IN_SECONDS ) {
				$trimmed[ $key ] = $ts;
			} else {
				delete_transient( $key );
			}
		}
		update_option( 'wcai_rate_limit_keys', $trimmed, false );
	}
}
