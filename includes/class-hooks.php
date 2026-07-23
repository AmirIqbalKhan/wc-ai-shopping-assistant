<?php
/**
 * WooCommerce hook listeners for index sync.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues index updates via Action Scheduler.
 */
class WCAI_Hooks {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_reindex' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_reindex' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation_reindex' ), 20, 1 );

		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'queue_stock_from_product' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue_stock_update' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'queue_parent_stock_from_variation' ), 20, 3 );

		add_action( 'before_delete_post', array( __CLASS__, 'queue_remove' ), 10, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'queue_remove' ), 10, 1 );

		add_action( WCAI_Indexer::ACTION_REINDEX_BATCH, array( 'WCAI_Indexer', 'process_batch' ), 10, 1 );
		add_action( WCAI_Indexer::ACTION_INDEX_ONE, array( __CLASS__, 'run_index_one' ), 10, 1 );
		add_action( WCAI_Indexer::ACTION_REMOVE_ONE, array( __CLASS__, 'run_remove_one' ), 10, 1 );
		add_action( WCAI_Indexer::ACTION_STOCK_UPDATE, array( __CLASS__, 'run_stock_update' ), 10, 1 );

		add_action( 'wcai_cleanup_rate_limits', array( 'WCAI_REST', 'cleanup_rate_limits' ) );
		add_action( 'wcai_cleanup_sessions', array( 'WCAI_Session', 'cleanup' ) );
		add_action( 'wcai_cleanup_analytics', array( 'WCAI_Analytics', 'cleanup_retention' ) );
	}

	/**
	 * Debounce and queue a full re-embed for a product.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function queue_reindex( $product_id ): void {
		$product_id = absint( $product_id );
		if ( ! $product_id || ! self::is_product( $product_id ) ) {
			return;
		}

		// Debounce rapid successive edits within 30 seconds.
		$transient = 'wcai_debounce_' . $product_id;
		if ( get_transient( $transient ) ) {
			return;
		}
		set_transient( $transient, 1, 30 );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( WCAI_Indexer::ACTION_INDEX_ONE, array( 'product_id' => $product_id ), 'wcai' ) ) {
			return;
		}

		WCAI_Indexer::enqueue_action(
			WCAI_Indexer::ACTION_INDEX_ONE,
			array( 'product_id' => $product_id )
		);
	}

	/**
	 * Queue reindex of parent when a variation changes.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public static function queue_variation_reindex( $variation_id ): void {
		$variation_id = absint( $variation_id );
		$parent_id    = (int) wp_get_post_parent_id( $variation_id );
		if ( $parent_id ) {
			self::queue_reindex( $parent_id );
		}
	}

	/**
	 * Queue stock-only update from stock status hook.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $stock_status Status.
	 * @param object $product      Product object.
	 */
	public static function queue_stock_update( $product_id, $stock_status = '', $product = null ): void {
		unset( $stock_status, $product );
		self::enqueue_stock( absint( $product_id ) );
	}

	/**
	 * Queue stock update when WC_Product stock quantity changes.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function queue_stock_from_product( $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			self::enqueue_stock( (int) $product->get_id() );
		}
	}

	/**
	 * When a variation stock changes, refresh the parent row's stock/price.
	 *
	 * @param int    $variation_id Variation ID.
	 * @param string $stock_status Status.
	 * @param object $variation    Variation object.
	 */
	public static function queue_parent_stock_from_variation( $variation_id, $stock_status = '', $variation = null ): void {
		unset( $stock_status );
		$variation_id = absint( $variation_id );
		$parent_id    = 0;

		if ( is_object( $variation ) && method_exists( $variation, 'get_parent_id' ) ) {
			$parent_id = (int) $variation->get_parent_id();
		} elseif ( $variation_id ) {
			$parent_id = (int) wp_get_post_parent_id( $variation_id );
		}

		if ( $parent_id ) {
			self::enqueue_stock( $parent_id );
		}
		if ( $variation_id && 'variation' === WCAI_Settings::get( 'index_mode', 'parent' ) ) {
			self::enqueue_stock( $variation_id );
		}
	}

	/**
	 * Enqueue stock metadata update.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private static function enqueue_stock( int $product_id ): void {
		if ( ! $product_id ) {
			return;
		}
		$type = get_post_type( $product_id );
		if ( 'product' !== $type && 'product_variation' !== $type ) {
			return;
		}

		WCAI_Indexer::enqueue_action(
			WCAI_Indexer::ACTION_STOCK_UPDATE,
			array( 'product_id' => $product_id )
		);
	}

	/**
	 * Queue removal from index.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue_remove( $post_id ): void {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! self::is_product( $post_id ) ) {
			return;
		}

		WCAI_Indexer::enqueue_action(
			WCAI_Indexer::ACTION_REMOVE_ONE,
			array( 'product_id' => $post_id )
		);
	}

	/**
	 * AS callback: index one product.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function run_index_one( $product_id ): void {
		WCAI_Indexer::index_single_product( absint( $product_id ) );
	}

	/**
	 * AS callback: remove one product.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function run_remove_one( $product_id ): void {
		WCAI_Indexer::remove_product( absint( $product_id ) );
	}

	/**
	 * AS callback: stock-only update.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function run_stock_update( $product_id ): void {
		WCAI_Indexer::update_stock_fields( absint( $product_id ) );
	}

	/**
	 * Whether the post is a product (not variation).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_product( int $post_id ): bool {
		return 'product' === get_post_type( $post_id );
	}
}
