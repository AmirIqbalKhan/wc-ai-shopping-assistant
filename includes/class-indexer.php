<?php
/**
 * Catalog indexer — embed and store products (parent or variation mode).
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and maintains the denormalized product index.
 */
class WCAI_Indexer {

	const BATCH_SIZE           = 20;
	const ACTION_REINDEX_BATCH = 'wcai_reindex_batch';
	const ACTION_INDEX_ONE     = 'wcai_reindex_product';
	const ACTION_REMOVE_ONE    = 'wcai_remove_product';
	const ACTION_STOCK_UPDATE = 'wcai_update_stock';

	/**
	 * Count indexed rows (parents or variations depending on mode).
	 *
	 * @return int
	 */
	public static function indexed_count(): int {
		global $wpdb;
		$table = WCAI_Installer::table_name();
		$mode  = (string) WCAI_Settings::get( 'index_mode', 'parent' );
		if ( 'variation' === $mode ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE variation_id > 0" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE variation_id = 0" );
	}

	/**
	 * Queue a full catalog reindex in batches.
	 */
	public static function queue_full_reindex(): void {
		$ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $ids ) ) {
			update_option(
				'wcai_reindex_state',
				array(
					'status' => 'done',
					'total'  => 0,
					'done'   => 0,
				),
				false
			);
			return;
		}

		$ids    = array_map( 'intval', $ids );
		$chunks = array_chunk( $ids, self::BATCH_SIZE );

		update_option(
			'wcai_reindex_state',
			array(
				'status' => 'running',
				'total'  => count( $ids ),
				'done'   => 0,
			),
			false
		);

		foreach ( $chunks as $chunk ) {
			as_enqueue_async_action(
				self::ACTION_REINDEX_BATCH,
				array( 'product_ids' => $chunk ),
				'wcai'
			);
		}
	}

	/**
	 * Process a batch of product IDs (Action Scheduler callback).
	 *
	 * @param array $product_ids Product IDs.
	 */
	public static function process_batch( array $product_ids ): void {
		$product_ids = array_values( array_filter( array_map( 'absint', $product_ids ) ) );
		if ( empty( $product_ids ) ) {
			return;
		}

		foreach ( $product_ids as $product_id ) {
			self::index_single_product( $product_id );
		}

		self::bump_reindex_progress( count( $product_ids ) );
	}

	/**
	 * Increment reindex progress counters.
	 *
	 * @param int $n Items completed.
	 */
	private static function bump_reindex_progress( int $n ): void {
		$state = get_option( 'wcai_reindex_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state['done'] = (int) ( $state['done'] ?? 0 ) + $n;
		$total         = (int) ( $state['total'] ?? 0 );
		if ( $total > 0 && $state['done'] >= $total ) {
			$state['status'] = 'done';
			$state['done']   = $total;
		} else {
			$state['status'] = 'running';
		}
		update_option( 'wcai_reindex_state', $state, false );
	}

	/**
	 * Index a single product (and variations when configured).
	 *
	 * @param int $product_id Product ID.
	 */
	public static function index_single_product( int $product_id ): void {
		$mode = (string) WCAI_Settings::get( 'index_mode', 'parent' );

		if ( 'variation' === $mode ) {
			self::index_product_variations( $product_id );
			return;
		}

		$built = self::build_product_payload( $product_id );
		if ( null === $built ) {
			self::remove_product( $product_id );
			return;
		}

		$vector = WCAI_OpenAI_Client::embed( $built['summary_text'] );
		if ( is_wp_error( $vector ) ) {
			error_log( 'WCAI single embed failed for #' . $product_id . ': ' . $vector->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		self::upsert_row( $built, $vector );
	}

	/**
	 * Index each variation of a variable product; simple products as parent rows.
	 *
	 * @param int $product_id Parent product ID.
	 */
	private static function index_product_variations( int $product_id ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			self::remove_product( $product_id );
			return;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			$built = self::build_product_payload( $product_id );
			if ( null === $built ) {
				return;
			}
			$vector = WCAI_OpenAI_Client::embed( $built['summary_text'] );
			if ( is_wp_error( $vector ) ) {
				return;
			}
			self::upsert_row( $built, $vector );
			return;
		}

		// Clear parent-only row if present.
		global $wpdb;
		$table = WCAI_Installer::table_name();
		$wpdb->delete(
			$table,
			array(
				'product_id'   => $product_id,
				'variation_id' => 0,
			),
			array( '%d', '%d' )
		);

		$children = $product->get_children();
		$payloads = array();
		foreach ( $children as $variation_id ) {
			$built = self::build_variation_payload( $product_id, (int) $variation_id );
			if ( $built ) {
				$payloads[] = $built;
			}
		}

		if ( empty( $payloads ) ) {
			return;
		}

		$summaries = array_column( $payloads, 'summary_text' );
		$vectors   = WCAI_OpenAI_Client::embed_batch( $summaries );
		if ( is_wp_error( $vectors ) ) {
			error_log( 'WCAI variation batch embed failed: ' . $vectors->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		foreach ( $payloads as $i => $row ) {
			if ( empty( $vectors[ $i ] ) ) {
				continue;
			}
			self::upsert_row( $row, $vectors[ $i ] );
		}
	}

	/**
	 * Build payload for a single variation.
	 *
	 * @param int $product_id   Parent ID.
	 * @param int $variation_id Variation ID.
	 * @return array|null
	 */
	public static function build_variation_payload( int $product_id, int $variation_id ): ?array {
		$variation = wc_get_product( $variation_id );
		$parent    = wc_get_product( $product_id );
		if ( ! $variation || ! $parent ) {
			return null;
		}

		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$attributes = array();
		foreach ( $variation->get_attributes() as $tax => $val ) {
			$attributes[ wc_attribute_label( $tax ) ] = $val;
		}

		$title = $variation->get_name();
		$short = $parent->get_short_description();
		$bits  = array();
		foreach ( $attributes as $label => $value ) {
			if ( '' !== (string) $value ) {
				$bits[] = $label . ': ' . $value;
			}
		}

		$summary = trim(
			$title . '. ' .
			wp_strip_all_tags( $short ) . '. ' .
			( $categories ? 'Categories: ' . implode( ', ', $categories ) . '. ' : '' ) .
			( $bits ? 'Attributes: ' . implode( '; ', $bits ) . '.' : '' )
		);

		return array(
			'product_id'      => $product_id,
			'variation_id'    => $variation_id,
			'title'           => $title,
			'summary_text'    => $summary,
			'price'           => (float) $variation->get_price(),
			'stock_status'    => $variation->get_stock_status(),
			'category_names'  => implode( ', ', $categories ),
			'attributes_json' => wp_json_encode( $attributes ),
			'product_url'     => $variation->get_permalink(),
		);
	}

	/**
	 * Update stock/price only without re-embedding.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	public static function update_stock_fields( int $product_id ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		global $wpdb;
		$table = WCAI_Installer::table_name();

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			$wpdb->update(
				$table,
				array(
					'price'           => (float) $product->get_price(),
					'stock_status'    => $product->get_stock_status(),
					'last_indexed_at' => current_time( 'mysql', true ),
				),
				array(
					'product_id'   => $parent_id,
					'variation_id' => $product_id,
				),
				array( '%f', '%s', '%s' ),
				array( '%d', '%d' )
			);
			return;
		}

		$status_id = $product_id;
		if ( 'publish' !== get_post_status( $status_id ) ) {
			self::remove_product( $product_id );
			return;
		}

		$wpdb->update(
			$table,
			array(
				'price'           => (float) $product->get_price(),
				'stock_status'    => $product->get_stock_status(),
				'last_indexed_at' => current_time( 'mysql', true ),
			),
			array(
				'product_id'   => $product_id,
				'variation_id' => 0,
			),
			array( '%f', '%s', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Remove a product (all variation rows) from the index.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function remove_product( int $product_id ): void {
		global $wpdb;
		$table = WCAI_Installer::table_name();
		$wpdb->delete( $table, array( 'product_id' => $product_id ), array( '%d' ) );
	}

	/**
	 * Build denormalized payload for a parent product.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null
	 */
	public static function build_product_payload( int $product_id ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			return null;
		}

		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
				$name = $attribute->get_name();
				if ( $attribute->is_taxonomy() ) {
					$terms = wc_get_product_terms( $product_id, $name, array( 'fields' => 'names' ) );
					$attributes[ wc_attribute_label( $name ) ] = is_array( $terms ) ? implode( ', ', $terms ) : '';
				} else {
					$attributes[ wc_attribute_label( $name ) ] = implode( ', ', $attribute->get_options() );
				}
			}
		}

		if ( $product->is_type( 'variable' ) ) {
			$variation_attrs = $product->get_variation_attributes();
			$bits            = array();
			foreach ( $variation_attrs as $tax => $opts ) {
				$label = wc_attribute_label( $tax );
				$vals  = is_array( $opts ) ? implode( '/', $opts ) : (string) $opts;
				if ( '' !== $vals ) {
					$bits[] = $label . ': ' . $vals;
				}
			}
			if ( $bits ) {
				$attributes['Available options'] = implode( '; ', $bits );
			}
		}

		$title     = $product->get_name();
		$short     = $product->get_short_description();
		$attr_bits = array();
		foreach ( $attributes as $label => $value ) {
			if ( '' === (string) $value || str_starts_with( (string) $label, '_' ) ) {
				continue;
			}
			$attr_bits[] = $label . ': ' . $value;
		}

		$summary = trim(
			$title . '. ' .
			wp_strip_all_tags( $short ) . '. ' .
			( $categories ? 'Categories: ' . implode( ', ', $categories ) . '. ' : '' ) .
			( $attr_bits ? 'Attributes: ' . implode( '; ', $attr_bits ) . '.' : '' )
		);

		return array(
			'product_id'      => $product_id,
			'variation_id'    => 0,
			'title'           => $title,
			'summary_text'    => $summary,
			'price'           => (float) $product->get_price(),
			'stock_status'    => $product->get_stock_status(),
			'category_names'  => implode( ', ', $categories ),
			'attributes_json' => wp_json_encode( $attributes ),
			'product_url'     => get_permalink( $product_id ),
		);
	}

	/**
	 * Upsert a row with embedding.
	 *
	 * @param array $row    Product payload.
	 * @param array $vector Embedding floats.
	 */
	private static function upsert_row( array $row, array $vector ): void {
		global $wpdb;
		$table        = WCAI_Installer::table_name();
		$variation_id = (int) ( $row['variation_id'] ?? 0 );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d AND variation_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row['product_id'],
				$variation_id
			)
		);

		$data = array(
			'product_id'      => $row['product_id'],
			'variation_id'    => $variation_id,
			'title'           => $row['title'],
			'summary_text'    => $row['summary_text'],
			'price'           => $row['price'],
			'stock_status'    => $row['stock_status'],
			'category_names'  => $row['category_names'],
			'attributes_json' => $row['attributes_json'],
			'embedding'       => self::serialize_embedding( $vector ),
			'last_indexed_at' => current_time( 'mysql', true ),
			'product_url'     => $row['product_url'],
		);

		$formats = array( '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $formats );
		}
	}

	/**
	 * Pack float vector for BLOB storage.
	 *
	 * @param array $vector Floats.
	 * @return string
	 */
	public static function serialize_embedding( array $vector ): string {
		return wp_json_encode( array_values( array_map( 'floatval', $vector ) ) );
	}

	/**
	 * Unpack embedding from storage.
	 *
	 * @param string $blob Stored embedding.
	 * @return float[]
	 */
	public static function unserialize_embedding( string $blob ): array {
		$decoded = json_decode( $blob, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_map( 'floatval', $decoded );
	}
}
