<?php
/**
 * Grounded conversational agent (multi-turn).
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns retrieval candidates + shopper query into a grounded response.
 */
class WCAI_Agent {

	/**
	 * Run the agent for a shopper query.
	 *
	 * @param string $query      Shopper message.
	 * @param array  $candidates Retrieval candidates.
	 * @param array  $session    Session state.
	 * @return array|WP_Error Structured response for the widget.
	 */
	public static function respond( string $query, array $candidates, array $session = array() ) {
		$variant = apply_filters( 'wcai_ab_variant', 'control', $session );

		if ( empty( $candidates ) ) {
			$out = array(
				'reply_text'          => __( "I couldn't find strong matches for that in this store's catalog. Try broadening your search or adjusting the budget.", 'shopask-ai-shopping-assistant' ),
				'products'            => array(),
				'clarifying_question' => __( 'Would you like to try a different category or price range?', 'shopask-ai-shopping-assistant' ),
				'constraints'         => self::extract_constraints_heuristic( $query, $session['constraints'] ?? array() ),
				'ab_variant'          => $variant,
			);
			return $out;
		}

		$candidate_payload = array();
		$allowed_ids       = array();

		foreach ( $candidates as $c ) {
			$id = (int) ( $c['product_id'] ?? 0 );
			$vid = (int) ( $c['variation_id'] ?? 0 );
			$key = $vid > 0 ? $vid : $id;
			if ( ! $key ) {
				continue;
			}
			$allowed_ids[ $key ] = true;
			if ( $id ) {
				$allowed_ids[ $id ] = true;
			}
			$candidate_payload[] = array(
				'id'           => $vid > 0 ? $vid : $id,
				'product_id'   => $id,
				'variation_id' => $vid,
				'title'        => $c['title'] ?? '',
				'price'        => $c['price'] ?? 0,
				'stock_status' => $c['stock_status'] ?? 'instock',
				'category'     => $c['category_names'] ?? '',
				'attributes'   => $c['attributes'] ?? array(),
			);
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$locale   = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		$system = 'You are a shopping assistant for a WooCommerce store. '
			. 'Store locale/language: ' . $locale . '. Reply in the shopper\'s language when clear, otherwise the store locale. '
			. 'You MUST only recommend products from the provided candidate list by their id. '
			. 'Never invent products, prices, or stock status. '
			. 'Only reference price using the numeric price field from the candidate data — do not recalculate or invent prices. '
			. 'For each recommended product include a short, specific reason citing attributes from the data. '
			. 'Use conversation history and constraints for follow-ups like "show me it in blue" or "not that one, something else". '
			. 'Prefer not to repeat product IDs listed in previously_shown unless the shopper asks to see them again. '
			. 'If the query is ambiguous, set clarifying_question and you may still return a short list of products or an empty list. '
			. 'If no candidates fit well, say so plainly and return an empty products array. '
			. 'Also return updated_constraints as a JSON object with any budget, category, color, size, or other attributes mentioned so far. '
			. 'Respond with a JSON object matching: {"reply_text": string, "products": [{"id": number, "reason": string}], "clarifying_question": string|null, "updated_constraints": object}. '
			. 'Keep reply_text concise. Recommend at most 5 products. Store currency: ' . $currency . '. A/B variant: ' . $variant . '.';

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
		);

		// Compact history (last few turns).
		foreach ( array_slice( $session['history'] ?? array(), -8 ) as $turn ) {
			$role = ( $turn['role'] ?? '' ) === 'assistant' ? 'assistant' : 'user';
			$messages[] = array(
				'role'    => $role,
				'content' => (string) ( $turn['content'] ?? '' ),
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => wp_json_encode(
				array(
					'query'              => $query,
					'constraints'        => $session['constraints'] ?? array(),
					'previously_shown'   => $session['shown_product_ids'] ?? array(),
					'turn_count'         => (int) ( $session['turn_count'] ?? 0 ),
					'candidates'         => $candidate_payload,
				)
			),
		);

		$raw = WCAI_OpenAI_Client::chat_json( $messages );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$result = self::ground_and_enrich( $raw, $allowed_ids );
		$result['constraints'] = self::merge_constraints(
			$session['constraints'] ?? array(),
			is_array( $raw['updated_constraints'] ?? null ) ? $raw['updated_constraints'] : array(),
			$query
		);
		$result['ab_variant'] = $variant;

		if ( (int) ( $session['turn_count'] ?? 0 ) >= 4 && empty( $result['products'] ) && empty( $result['clarifying_question'] ) ) {
			$result['clarifying_question'] = __( 'Would you like help narrowing this down by budget, category, or size?', 'shopask-ai-shopping-assistant' );
		}

		return $result;
	}

	/**
	 * Merge constraint objects + heuristics from the latest query.
	 *
	 * @param array  $existing Existing.
	 * @param array  $from_model Model constraints.
	 * @param string $query Query.
	 * @return array
	 */
	private static function merge_constraints( array $existing, array $from_model, string $query ): array {
		$merged = array_merge( $existing, self::sanitize_constraints( $from_model ) );
		return self::extract_constraints_heuristic( $query, $merged );
	}

	/**
	 * Sanitize constraint map.
	 *
	 * @param array $c Constraints.
	 * @return array
	 */
	private static function sanitize_constraints( array $c ): array {
		$out = array();
		foreach ( $c as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $v ) ) {
				$out[ $key ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	/**
	 * Lightweight local constraint extraction.
	 *
	 * @param string $query Query.
	 * @param array  $base  Base constraints.
	 * @return array
	 */
	public static function extract_constraints_heuristic( string $query, array $base = array() ): array {
		$budget = WCAI_Retrieval::extract_budget( $query );
		if ( null !== $budget ) {
			$base['budget'] = $budget;
		}
		if ( preg_match( '/\b(blue|red|green|black|white|navy|pink|brown|grey|gray|yellow|orange|purple)\b/i', $query, $m ) ) {
			$base['color'] = strtolower( $m[1] );
		}
		if ( preg_match( '/\b(size\s*)?(xxs|xs|s|m|l|xl|xxl|small|medium|large)\b/i', $query, $m ) ) {
			$base['size'] = strtolower( $m[2] ?? $m[1] );
		}
		return $base;
	}

	/**
	 * Drop hallucinated IDs and attach live WooCommerce product data.
	 *
	 * @param array $raw         Model JSON.
	 * @param array $allowed_ids Map of allowed product IDs.
	 * @return array
	 */
	private static function ground_and_enrich( array $raw, array $allowed_ids ): array {
		$reply = isset( $raw['reply_text'] ) ? sanitize_text_field( (string) $raw['reply_text'] ) : '';
		$q     = array_key_exists( 'clarifying_question', $raw ) ? $raw['clarifying_question'] : null;
		if ( is_string( $q ) ) {
			$q = sanitize_text_field( $q );
			if ( '' === $q ) {
				$q = null;
			}
		} else {
			$q = null;
		}

		$products_out = array();
		$list         = isset( $raw['products'] ) && is_array( $raw['products'] ) ? $raw['products'] : array();

		foreach ( $list as $item ) {
			$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( ! $id || empty( $allowed_ids[ $id ] ) ) {
				continue;
			}

			$live = self::live_product_card( $id, isset( $item['reason'] ) ? (string) $item['reason'] : '' );
			if ( null === $live ) {
				continue;
			}

			$products_out[] = $live;
		}

		if ( '' === $reply && empty( $products_out ) ) {
			$reply = __( 'I could not find a good match. Try refining your request.', 'shopask-ai-shopping-assistant' );
		}

		return array(
			'reply_text'          => $reply,
			'products'            => $products_out,
			'clarifying_question' => $q,
		);
	}

	/**
	 * Build a product card from live WooCommerce data.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $reason     Model reason.
	 * @return array|null
	 */
	private static function live_product_card( int $product_id, string $reason ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		// Prefer checking the parent for visibility when recommending a variation.
		$check = $product;
		if ( $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$check = $parent;
			}
		}
		if ( ! WCAI_Indexer::is_indexable_product( $check ) ) {
			return null;
		}

		$parent_id = $product->get_parent_id();
		$status_id = $parent_id ? $parent_id : $product_id;
		if ( 'publish' !== get_post_status( $status_id ) ) {
			return null;
		}

		$image_id  = $product->get_image_id();
		if ( ! $image_id && $parent_id ) {
			$parent = wc_get_product( $parent_id );
			$image_id = $parent ? $parent->get_image_id() : 0;
		}
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );

		$type        = $product->get_type();
		$purchasable = $product->is_purchasable() && $product->is_in_stock();
		$can_atc     = $purchasable && in_array( $type, array( 'simple', 'variation' ), true ) && ! $product->is_type( 'variable' );

		return array(
			'id'           => $product_id,
			'title'        => $product->get_name(),
			'price'        => (float) $product->get_price(),
			'price_html'   => wp_kses_post( $product->get_price_html() ),
			'stock_status' => $product->get_stock_status(),
			'url'          => $product->get_permalink(),
			'image'        => $image_url ? $image_url : '',
			'reason'       => sanitize_text_field( $reason ),
			'type'         => $type,
			'purchasable'  => (bool) $purchasable,
			'add_to_cart'  => (bool) $can_atc,
		);
	}
}
