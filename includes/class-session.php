<?php
/**
 * Server-side chat session state.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages multi-turn conversation sessions.
 */
class WCAI_Session {

	const TTL_HOURS = 24;

	/**
	 * Get or create a session by token.
	 *
	 * @param string|null $token Existing token or empty.
	 * @return array Session row as array with keys: session_token, constraints, shown_product_ids, history, turn_count.
	 */
	public static function get_or_create( ?string $token ): array {
		$token = is_string( $token ) ? preg_replace( '/[^a-zA-Z0-9]/', '', $token ) : '';
		if ( $token && strlen( $token ) >= 16 ) {
			$row = self::load( $token );
			if ( $row ) {
				return $row;
			}
		}
		return self::create();
	}

	/**
	 * Create a new empty session.
	 *
	 * @return array
	 */
	public static function create(): array {
		global $wpdb;
		$table = WCAI_Installer::sessions_table();
		$token = wp_generate_password( 32, false, false );

		$wpdb->insert(
			$table,
			array(
				'session_token'     => $token,
				'constraints_json'  => wp_json_encode( new stdClass() ),
				'shown_product_ids' => wp_json_encode( array() ),
				'history_json'      => wp_json_encode( array() ),
				'turn_count'        => 0,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return array(
			'session_token'     => $token,
			'constraints'       => array(),
			'shown_product_ids' => array(),
			'history'           => array(),
			'turn_count'        => 0,
		);
	}

	/**
	 * Load session by token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function load( string $token ): ?array {
		global $wpdb;
		$table = WCAI_Installer::sessions_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_token = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$updated = strtotime( $row['updated_at'] . ' UTC' );
		if ( $updated && ( time() - $updated ) > self::TTL_HOURS * HOUR_IN_SECONDS ) {
			self::delete( $token );
			return null;
		}

		$constraints = json_decode( (string) $row['constraints_json'], true );
		$shown       = json_decode( (string) $row['shown_product_ids'], true );
		$history     = json_decode( (string) $row['history_json'], true );

		return array(
			'session_token'     => $row['session_token'],
			'constraints'       => is_array( $constraints ) ? $constraints : array(),
			'shown_product_ids' => is_array( $shown ) ? array_map( 'intval', $shown ) : array(),
			'history'           => is_array( $history ) ? $history : array(),
			'turn_count'        => (int) $row['turn_count'],
		);
	}

	/**
	 * Persist session state after a turn.
	 *
	 * @param array $session Session array.
	 */
	public static function save( array $session ): void {
		global $wpdb;
		$table = WCAI_Installer::sessions_table();

		$wpdb->update(
			$table,
			array(
				'constraints_json'  => wp_json_encode( $session['constraints'] ?? array() ),
				'shown_product_ids' => wp_json_encode( array_values( array_unique( array_map( 'intval', $session['shown_product_ids'] ?? array() ) ) ) ),
				'history_json'      => wp_json_encode( array_slice( $session['history'] ?? array(), -20 ) ),
				'turn_count'        => (int) ( $session['turn_count'] ?? 0 ),
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'session_token' => $session['session_token'] ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Delete a session.
	 *
	 * @param string $token Token.
	 */
	public static function delete( string $token ): void {
		global $wpdb;
		$wpdb->delete( WCAI_Installer::sessions_table(), array( 'session_token' => $token ), array( '%s' ) );
	}

	/**
	 * Purge expired sessions.
	 */
	public static function cleanup(): void {
		global $wpdb;
		$table = WCAI_Installer::sessions_table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - self::TTL_HOURS * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $cut ) );
	}

	/**
	 * Append a turn and merge constraints / shown IDs.
	 *
	 * @param array  $session  Session.
	 * @param string $query    User query.
	 * @param array  $response Agent response.
	 * @param array  $new_constraints Extracted/merged constraints.
	 * @return array Updated session.
	 */
	public static function after_turn( array $session, string $query, array $response, array $new_constraints = array() ): array {
		$session['turn_count']  = (int) ( $session['turn_count'] ?? 0 ) + 1;
		$session['constraints'] = array_merge( $session['constraints'] ?? array(), $new_constraints );

		$history   = $session['history'] ?? array();
		$history[] = array(
			'role'    => 'user',
			'content' => $query,
		);
		$history[] = array(
			'role'    => 'assistant',
			'content' => (string) ( $response['reply_text'] ?? '' ),
			'products'=> array_map(
				static function ( $p ) {
					return (int) ( $p['id'] ?? 0 );
				},
				$response['products'] ?? array()
			),
		);
		$session['history'] = array_slice( $history, -20 );

		$shown = $session['shown_product_ids'] ?? array();
		foreach ( $response['products'] ?? array() as $p ) {
			$id = (int) ( $p['id'] ?? 0 );
			if ( $id ) {
				$shown[] = $id;
			}
		}
		$session['shown_product_ids'] = array_values( array_unique( $shown ) );

		self::save( $session );
		return $session;
	}
}
