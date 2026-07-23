<?php
/**
 * Multi-provider AI client (OpenAI, Claude, Gemini, LongCat, OpenRouter).
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP wrapper for embeddings + chat completions across providers.
 */
class WCAI_OpenAI_Client {

	/**
	 * Current provider ID.
	 *
	 * @return string
	 */
	public static function provider(): string {
		$p = (string) WCAI_Settings::get( 'provider', 'openai' );
		return in_array( $p, WCAI_Providers::ids(), true ) ? $p : 'openai';
	}

	/**
	 * Resolve chat/embeddings API base URL.
	 *
	 * @return string
	 */
	public static function api_base(): string {
		$provider = self::provider();
		$custom   = trim( (string) WCAI_Settings::get( 'api_base', '' ) );
		$preset   = WCAI_Providers::default_base( $provider );

		if ( 'custom' === $provider ) {
			$base = $custom ?: 'https://api.openai.com/v1';
		} elseif ( $custom ) {
			$base = $custom;
		} else {
			$base = $preset ?: 'https://api.openai.com/v1';
		}

		$base = untrailingslashit( $base );

		// Anthropic base already ends with /v1.
		if ( 'claude' === $provider ) {
			if ( ! preg_match( '#/v1$#', $base ) ) {
				$base .= '/v1';
			}
			return $base;
		}

		// Gemini OpenAI-compat base is .../v1beta/openai (no trailing /v1).
		if ( 'gemini' === $provider ) {
			return $base;
		}

		// LongCat (and similar): docs SDK base is .../openai; our client appends /chat/completions,
		// so normalize to .../openai/v1 → https://api.longcat.chat/openai/v1/chat/completions.
		if ( 'longcat' === $provider || false !== strpos( $base, '/openai' ) ) {
			if ( preg_match( '#/openai$#', $base ) ) {
				$base .= '/v1';
			} elseif ( ! preg_match( '#/v1$#', $base ) && false === strpos( $base, '/v1beta' ) ) {
				$base .= '/v1';
			}
			return $base;
		}

		if ( ! preg_match( '#/v1$#', $base ) ) {
			$base .= '/v1';
		}

		return $base;
	}

	/**
	 * Whether embeddings should use the local hasher.
	 *
	 * @return bool
	 */
	public static function use_local_embeddings(): bool {
		$mode = (string) WCAI_Settings::get( 'embedding_mode', 'auto' );
		if ( 'local' === $mode ) {
			return true;
		}
		if ( 'api' === $mode ) {
			return false;
		}
		return WCAI_Providers::prefers_local_embeddings( self::provider() );
	}

	/**
	 * Create an embedding vector for the given text.
	 *
	 * @param string $text Input text.
	 * @return array|WP_Error
	 */
	public static function embed( string $text ) {
		$text = wp_strip_all_tags( $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );

		if ( '' === $text ) {
			return new WP_Error( 'wcai_empty_text', __( 'Cannot embed empty text.', 'wc-ai-shopping-assistant' ) );
		}

		if ( self::use_local_embeddings() ) {
			$allowed = WCAI_Usage::assert_allowed( 'embed' );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			$vec = WCAI_Local_Embeddings::embed( $text );
			WCAI_Usage::increment( 'embed', 1 );
			return $vec;
		}

		$allowed = WCAI_Usage::assert_allowed( 'embed' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$api_key = WCAI_Settings::get( 'api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'wcai_no_api_key', __( 'API key is not configured.', 'wc-ai-shopping-assistant' ) );
		}

		$model = WCAI_Settings::get( 'embedding_model', 'text-embedding-3-small' );

		$response = self::request_openai(
			'/embeddings',
			array(
				'model' => $model,
				'input' => $text,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'][0]['embedding'] ) || ! is_array( $response['data'][0]['embedding'] ) ) {
			return new WP_Error( 'wcai_bad_embedding', __( 'Unexpected embedding response from the API.', 'wc-ai-shopping-assistant' ) );
		}

		WCAI_Usage::increment( 'embed', 1 );
		return array_map( 'floatval', $response['data'][0]['embedding'] );
	}

	/**
	 * Batch-embed multiple texts.
	 *
	 * @param string[] $texts Texts.
	 * @return array|WP_Error
	 */
	public static function embed_batch( array $texts ) {
		$clean = array();
		foreach ( $texts as $text ) {
			$t = wp_strip_all_tags( (string) $text );
			$t = trim( preg_replace( '/\s+/', ' ', $t ) ?? '' );
			$clean[] = '' === $t ? ' ' : $t;
		}

		$n       = max( 1, count( $clean ) );
		$allowed = WCAI_Usage::assert_allowed( 'embed' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( self::use_local_embeddings() ) {
			$out = WCAI_Local_Embeddings::embed_batch( $clean );
			WCAI_Usage::increment( 'embed', $n );
			return $out;
		}

		$api_key = WCAI_Settings::get( 'api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'wcai_no_api_key', __( 'API key is not configured.', 'wc-ai-shopping-assistant' ) );
		}

		$model = WCAI_Settings::get( 'embedding_model', 'text-embedding-3-small' );

		$response = self::request_openai(
			'/embeddings',
			array(
				'model' => $model,
				'input' => $clean,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new WP_Error( 'wcai_bad_embedding', __( 'Unexpected embedding response from the API.', 'wc-ai-shopping-assistant' ) );
		}

		usort(
			$response['data'],
			static function ( $a, $b ) {
				return ( $a['index'] ?? 0 ) <=> ( $b['index'] ?? 0 );
			}
		);

		$out = array();
		foreach ( $response['data'] as $row ) {
			$out[] = array_map( 'floatval', $row['embedding'] ?? array() );
		}

		WCAI_Usage::increment( 'embed', $n );
		return $out;
	}

	/**
	 * Chat completion that expects JSON object output.
	 *
	 * @param array $messages OpenAI-style messages.
	 * @return array|WP_Error
	 */
	public static function chat_json( array $messages ) {
		$api_key = WCAI_Settings::get( 'api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'wcai_no_api_key', __( 'API key is not configured.', 'wc-ai-shopping-assistant' ) );
		}

		$provider = self::provider();
		$meta     = WCAI_Providers::get( $provider );

		if ( 'anthropic' === ( $meta['api_style'] ?? '' ) ) {
			return self::chat_json_anthropic( $messages );
		}

		return self::chat_json_openai( $messages, $provider );
	}

	/**
	 * OpenAI-compatible chat JSON.
	 *
	 * @param array  $messages Messages.
	 * @param string $provider Provider ID.
	 * @return array|WP_Error
	 */
	private static function chat_json_openai( array $messages, string $provider ) {
		$model = WCAI_Settings::get( 'chat_model', 'gpt-4o-mini' );

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => 0.2,
			'max_tokens'  => 2048,
		);

		if ( in_array( $provider, array( 'openai', 'openrouter', 'custom' ), true ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		} else {
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => 'Respond with a single valid JSON object only. No markdown fences, no extra text.',
				)
			);
			$body['messages'] = $messages;
		}

		$response = self::request_openai( '/chat/completions', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = $response['choices'][0]['message']['content'] ?? '';
		if ( ! is_string( $content ) || '' === $content ) {
			return new WP_Error( 'wcai_empty_chat', __( 'Empty chat response from the API.', 'wc-ai-shopping-assistant' ) );
		}

		$decoded = self::parse_json_content( $content );
		if ( null === $decoded ) {
			return new WP_Error( 'wcai_invalid_json', __( 'Chat response was not valid JSON.', 'wc-ai-shopping-assistant' ) );
		}

		return $decoded;
	}

	/**
	 * Anthropic Messages API chat JSON.
	 *
	 * @param array $messages OpenAI-style messages.
	 * @return array|WP_Error
	 */
	private static function chat_json_anthropic( array $messages ) {
		$model  = WCAI_Settings::get( 'chat_model', 'claude-sonnet-4-5' );
		$system = 'Respond with a single valid JSON object only. No markdown fences, no extra text.';
		$anth   = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			$text = (string) ( $msg['content'] ?? '' );
			if ( 'system' === $role ) {
				$system .= "\n\n" . $text;
				continue;
			}
			if ( 'assistant' !== $role ) {
				$role = 'user';
			}
			$anth[] = array(
				'role'    => $role,
				'content' => $text,
			);
		}

		if ( empty( $anth ) ) {
			return new WP_Error( 'wcai_empty_chat', __( 'No messages to send.', 'wc-ai-shopping-assistant' ) );
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => 2048,
			'system'     => $system,
			'messages'   => $anth,
		);

		$response = self::request_anthropic( '/messages', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = '';
		if ( ! empty( $response['content'] ) && is_array( $response['content'] ) ) {
			foreach ( $response['content'] as $block ) {
				if ( ( $block['type'] ?? '' ) === 'text' ) {
					$content .= (string) ( $block['text'] ?? '' );
				}
			}
		}

		if ( '' === $content ) {
			return new WP_Error( 'wcai_empty_chat', __( 'Empty chat response from the API.', 'wc-ai-shopping-assistant' ) );
		}

		$decoded = self::parse_json_content( $content );
		if ( null === $decoded ) {
			return new WP_Error( 'wcai_invalid_json', __( 'Chat response was not valid JSON.', 'wc-ai-shopping-assistant' ) );
		}

		return $decoded;
	}

	/**
	 * Extract JSON object from model content.
	 *
	 * @param string $content Raw content.
	 * @return array|null
	 */
	private static function parse_json_content( string $content ): ?array {
		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/\{[\s\S]*\}/', $content, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * OpenAI-compatible POST.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 * @return array|WP_Error
	 */
	private static function request_openai( string $path, array $body ) {
		$api_key = WCAI_Settings::get( 'api_key' );
		$url     = self::api_base() . $path;
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		);

		// OpenRouter recommends these optional headers.
		if ( 'openrouter' === self::provider() ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = 'WooCommerce AI Shopping Assistant';
		}

		return self::http_post( $url, $headers, $body );
	}

	/**
	 * Anthropic Messages POST.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 * @return array|WP_Error
	 */
	private static function request_anthropic( string $path, array $body ) {
		$api_key = WCAI_Settings::get( 'api_key' );
		$url     = self::api_base() . $path;
		$headers = array(
			'x-api-key'         => $api_key,
			'anthropic-version' => '2023-06-01',
			'Content-Type'      => 'application/json',
		);

		return self::http_post( $url, $headers, $body );
	}

	/**
	 * Shared HTTP POST JSON helper.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param array  $body    Body.
	 * @return array|WP_Error
	 */
	private static function http_post( string $url, array $headers, array $body ) {
		if ( WCAI_Installer::is_blocked_url( $url ) ) {
			return new WP_Error( 'wcai_blocked_url', __( 'API base URL points to a blocked or private host.', 'wc-ai-shopping-assistant' ) );
		}

		$http = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $http ) ) {
			return $http;
		}

		$code = (int) wp_remote_retrieve_response_code( $http );
		$raw  = wp_remote_retrieve_body( $http );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = '';
			if ( is_array( $data ) ) {
				if ( isset( $data['error']['message'] ) ) {
					$message = (string) $data['error']['message'];
				} elseif ( isset( $data['error']['type'] ) ) {
					$message = (string) $data['error']['type'];
				} elseif ( isset( $data['message'] ) ) {
					$message = (string) $data['message'];
				}
			}
			if ( '' === $message ) {
				$message = sprintf( /* translators: %d: HTTP status */ __( 'API error (HTTP %d).', 'wc-ai-shopping-assistant' ), $code );
			}

			return new WP_Error( 'wcai_openai_http', $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wcai_openai_parse', __( 'Could not parse API response.', 'wc-ai-shopping-assistant' ) );
		}

		return $data;
	}
}
