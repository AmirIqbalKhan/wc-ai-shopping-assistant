<?php
/**
 * Hybrid in-DB retrieval: metadata/FULLTEXT prefilter + cosine re-rank.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds top-N candidate products for a shopper query.
 */
class WCAI_Retrieval {

	/**
	 * Retrieve ranked candidates for a natural-language query.
	 *
	 * @param string $query   Shopper query.
	 * @param array  $session Optional session (constraints, excluded IDs).
	 * @return array|WP_Error List of candidate product arrays, or error.
	 */
	public static function search( string $query, array $session = array() ) {
		$query = trim( wp_strip_all_tags( $query ) );
		if ( '' === $query ) {
			return new WP_Error( 'wcai_empty_query', __( 'Query cannot be empty.', 'wc-ai-shopping-assistant' ), array( 'status' => 400 ) );
		}

		$constraints = WCAI_Agent::extract_constraints_heuristic(
			$query,
			is_array( $session['constraints'] ?? null ) ? $session['constraints'] : array()
		);

		$embedding = WCAI_OpenAI_Client::embed( $query );
		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		$rows = self::load_candidates( $query, $constraints, $session['shown_product_ids'] ?? array() );

		if ( empty( $rows ) ) {
			return array();
		}

		$exclude = array_map( 'intval', $session['shown_product_ids'] ?? array() );
		$exclude_map = array_fill_keys( $exclude, true );
		$wants_other = (bool) preg_match( '/\b(not that|something else|different|another)\b/i', $query );

		$scored = array();
		foreach ( $rows as $row ) {
			$vector = WCAI_Indexer::unserialize_embedding( (string) $row['embedding'] );
			if ( empty( $vector ) ) {
				continue;
			}

			$pid = (int) $row['product_id'];
			$vid = (int) ( $row['variation_id'] ?? 0 );
			$id  = $vid > 0 ? $vid : $pid;

			if ( $wants_other && ( isset( $exclude_map[ $id ] ) || isset( $exclude_map[ $pid ] ) ) ) {
				continue;
			}

			$score = self::cosine_similarity( $embedding, $vector );
			$scored[] = array(
				'product_id'     => $pid,
				'variation_id'   => $vid,
				'title'          => $row['title'],
				'price'          => (float) $row['price'],
				'stock_status'   => $row['stock_status'],
				'category_names' => $row['category_names'],
				'attributes'     => json_decode( (string) $row['attributes_json'], true ) ?: array(),
				'product_url'    => $row['product_url'],
				'score'          => $score,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$threshold = (float) WCAI_Settings::get( 'similarity_threshold', 0.25 );
		$top_n     = (int) WCAI_Settings::get( 'top_n', 20 );

		$filtered = array_values(
			array_filter(
				$scored,
				static function ( $item ) use ( $threshold ) {
					return $item['score'] >= $threshold;
				}
			)
		);

		return array_slice( $filtered, 0, $top_n );
	}

	/**
	 * Load a bounded candidate set via SQL prefilter (+ optional FULLTEXT).
	 *
	 * @param string     $query       Query text.
	 * @param array      $constraints Constraints.
	 * @param array      $exclude_ids Shown IDs (soft).
	 * @return array
	 */
	private static function load_candidates( string $query, array $constraints, array $exclude_ids ): array {
		global $wpdb;
		$table = WCAI_Installer::table_name();
		$limit = max( 50, min( 1000, (int) WCAI_Settings::get( 'prefilter_limit', 300 ) ) );
		$mode  = (string) WCAI_Settings::get( 'index_mode', 'parent' );

		$where  = array( 'embedding IS NOT NULL', 'stock_status = %s' );
		$params = array( 'instock' );

		if ( 'variation' === $mode ) {
			$where[] = 'variation_id > 0';
		} else {
			$where[] = 'variation_id = 0';
		}

		$max_price = null;
		if ( isset( $constraints['budget'] ) && is_numeric( $constraints['budget'] ) ) {
			$max_price = (float) $constraints['budget'];
		} else {
			$max_price = self::extract_budget( $query );
		}
		if ( null !== $max_price ) {
			$where[]  = 'price <= %f';
			$params[] = $max_price;
		}

		if ( ! empty( $constraints['color'] ) ) {
			$where[]  = 'attributes_json LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $constraints['color'] ) . '%';
		}
		if ( ! empty( $constraints['category'] ) ) {
			$where[]  = 'category_names LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $constraints['category'] ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$ft_terms  = self::fulltext_terms( $query );
		$rows      = array();

		if ( $ft_terms ) {
			$ft_where = $where_sql . ' AND MATCH(summary_text) AGAINST (%s IN BOOLEAN MODE)';
			$ft_params = array_merge( $params, array( $ft_terms ) );
			$sql = "SELECT product_id, variation_id, title, price, stock_status, category_names, attributes_json, embedding, product_url,
				MATCH(summary_text) AGAINST (%s IN BOOLEAN MODE) AS ft_score
				FROM {$table}
				WHERE {$ft_where}
				ORDER BY ft_score DESC
				LIMIT %d";
			// AGAINST appears twice — bind query terms twice + limit.
			$bind = array_merge( array( $ft_terms ), $ft_params, array( $limit ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$prepared = $wpdb->prepare( $sql, $bind );
			if ( $prepared ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( $prepared, ARRAY_A ) ?: array();
			}
		}

		if ( count( $rows ) < 10 ) {
			$sql = "SELECT product_id, variation_id, title, price, stock_status, category_names, attributes_json, embedding, product_url
				FROM {$table}
				WHERE {$where_sql}
				ORDER BY last_indexed_at DESC
				LIMIT %d";
			$bind = array_merge( $params, array( $limit ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$prepared = $wpdb->prepare( $sql, $bind );
			if ( $prepared ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$fallback = $wpdb->get_results( $prepared, ARRAY_A ) ?: array();
				if ( empty( $rows ) ) {
					$rows = $fallback;
				} else {
					$seen = array();
					foreach ( $rows as $r ) {
						$seen[ $r['product_id'] . ':' . $r['variation_id'] ] = true;
					}
					foreach ( $fallback as $r ) {
						$key = $r['product_id'] . ':' . $r['variation_id'];
						if ( empty( $seen[ $key ] ) ) {
							$rows[] = $r;
						}
						if ( count( $rows ) >= $limit ) {
							break;
						}
					}
				}
			}
		}

		return $rows;
	}

	/**
	 * Build a BOOLEAN MODE FULLTEXT query string.
	 *
	 * @param string $query Raw query.
	 * @return string
	 */
	private static function fulltext_terms( string $query ): string {
		$words = preg_split( '/[^a-zA-Z0-9]+/', strtolower( $query ) ) ?: array();
		$stop  = array( 'a', 'an', 'the', 'for', 'and', 'or', 'to', 'of', 'in', 'on', 'with', 'my', 'me', 'i', 'something', 'show', 'find', 'looking', 'want', 'need', 'under', 'below', 'less', 'than' );
		$out   = array();
		foreach ( $words as $w ) {
			if ( strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
				continue;
			}
			$out[] = '+' . $w . '*';
		}
		return implode( ' ', array_slice( $out, 0, 8 ) );
	}

	/**
	 * Cosine similarity between two equal-length vectors.
	 *
	 * @param float[] $a Vector A.
	 * @param float[] $b Vector B.
	 * @return float
	 */
	public static function cosine_similarity( array $a, array $b ): float {
		$len = min( count( $a ), count( $b ) );
		if ( $len < 1 ) {
			return 0.0;
		}

		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$av  = (float) $a[ $i ];
			$bv  = (float) $b[ $i ];
			$dot += $av * $bv;
			$na  += $av * $av;
			$nb  += $bv * $bv;
		}

		if ( $na <= 0.0 || $nb <= 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	/**
	 * Best-effort budget extraction from query text.
	 *
	 * @param string $query Query text.
	 * @return float|null
	 */
	public static function extract_budget( string $query ): ?float {
		$patterns = array(
			'/\b(?:under|below|less than|max(?:imum)?|up to|budget of)\s*\$?\s*(\d+(?:\.\d{1,2})?)/i',
			'/\$\s*(\d+(?:\.\d{1,2})?)\s*(?:or less|max|budget)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $query, $m ) ) {
				return (float) $m[1];
			}
		}

		return null;
	}
}
