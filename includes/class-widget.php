<?php
/**
 * Frontend widgets: floating, panel, search bar, button + shortcode/block.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues and mounts storefront assistant UI variants.
 */
class WCAI_Widget {

	/**
	 * Whether assets were enqueued this request.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Allowed widget layout types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( 'floating', 'panel', 'search', 'button' );
	}

	/**
	 * Hook frontend assets.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_floating_root' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'maybe_auto_insert_search' ), 20 );
		add_shortcode( 'shopask_ai', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'shopask_assistant', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'wc_ai_assistant', array( __CLASS__, 'shortcode' ) ); // Legacy alias.
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Register Gutenberg block with layout attribute.
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$block_dir = WCAI_PLUGIN_DIR . 'blocks/ai-assistant-block';
		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		wp_register_script(
			'wcai-ai-assistant-editor',
			WCAI_PLUGIN_URL . 'blocks/ai-assistant-block/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			WCAI_VERSION,
			true
		);

		$block_args = array(
			'editor_script'   => 'wcai-ai-assistant-editor',
			'render_callback' => array( __CLASS__, 'render_block' ),
			'attributes'      => array(
				'type'  => array(
					'type'    => 'string',
					'default' => 'panel',
				),
				'label' => array(
					'type'    => 'string',
					'default' => '',
				),
				'align' => array(
					'type' => 'string',
				),
			),
		);

		register_block_type( $block_dir, $block_args );

		// Legacy block name for content saved before the ShopAsk rename.
		register_block_type( 'wcai/ai-assistant', $block_args );
	}

	/**
	 * Whether floating launcher should show (site-wide).
	 *
	 * @return bool
	 */
	private static function show_floating(): bool {
		if ( is_admin() || ! self::widgets_enabled() ) {
			return false;
		}
		return '1' === (string) WCAI_Settings::get( 'show_floating', '1' );
	}

	/**
	 * Master enable switch.
	 *
	 * @return bool
	 */
	private static function widgets_enabled(): bool {
		return '1' === (string) WCAI_Settings::get( 'widget_enabled', '1' );
	}

	/**
	 * Enqueue when floating or auto-insert is on.
	 */
	public static function maybe_enqueue(): void {
		if ( is_admin() || ! self::widgets_enabled() ) {
			return;
		}
		$auto = (string) WCAI_Settings::get( 'auto_search_location', 'none' );
		if ( self::show_floating() || ( 'none' !== $auto && '' !== $auto ) ) {
			self::ensure_assets();
			return;
		}

		global $post;
		if ( $post instanceof WP_Post ) {
			$content = (string) $post->post_content;
			if (
				has_shortcode( $content, 'shopask_ai' )
				|| has_shortcode( $content, 'shopask_assistant' )
				|| has_shortcode( $content, 'wc_ai_assistant' )
			) {
				self::ensure_assets();
				return;
			}
			if (
				function_exists( 'has_block' )
				&& ( has_block( 'shopask/ai-assistant', $post ) || has_block( 'wcai/ai-assistant', $post ) )
			) {
				self::ensure_assets();
			}
		}
	}

	/**
	 * Ensure CSS/JS are loaded once.
	 */
	public static function ensure_assets(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		$title = (string) WCAI_Settings::get( 'widget_title', '' );
		if ( '' === $title ) {
			$title = __( 'ShopAsk AI', 'shopask-ai-shopping-assistant' );
		}

		wp_enqueue_style(
			'wcai-widget',
			WCAI_PLUGIN_URL . 'assets/css/widget.css',
			array(),
			WCAI_VERSION
		);

		$accent = (string) WCAI_Settings::get( 'accent_color', '#0d9488' );
		wp_add_inline_style( 'wcai-widget', ':root{--wcai-accent:' . esc_attr( $accent ) . ';}' );

		wp_enqueue_script(
			'wcai-widget',
			WCAI_PLUGIN_URL . 'assets/js/widget.js',
			array(),
			WCAI_VERSION,
			true
		);

		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

		wp_localize_script(
			'wcai-widget',
			'wcaiWidget',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'shopask/v1/query' ) ),
				'clickUrl'     => esc_url_raw( rest_url( 'shopask/v1/click' ) ),
				'ajaxUrl'      => esc_url_raw( home_url( '/' ) ),
				'cartNonce'    => wp_create_nonce( 'wc_store_api' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'currency'     => $symbol,
				'hideBranding' => '1' === (string) WCAI_Settings::get( 'hide_branding', '0' ),
				'accent'       => $accent,
				'suggestions'  => array(
					/* translators: %s: currency symbol */
					sprintf( __( 'Under %s50', 'shopask-ai-shopping-assistant' ), $symbol ),
					__( 'Gift ideas', 'shopask-ai-shopping-assistant' ),
					__( 'Bestsellers', 'shopask-ai-shopping-assistant' ),
					__( 'Something new', 'shopask-ai-shopping-assistant' ),
				),
				'i18n'         => array(
					'title'             => $title,
					'placeholder'       => __( 'Describe what you are looking for…', 'shopask-ai-shopping-assistant' ),
					'searchPlaceholder' => __( 'Ask ShopAsk — e.g. rain jacket under $80', 'shopask-ai-shopping-assistant' ),
					'send'              => __( 'Send', 'shopask-ai-shopping-assistant' ),
					'search'            => __( 'Search', 'shopask-ai-shopping-assistant' ),
					'askAi'             => __( 'Ask ShopAsk', 'shopask-ai-shopping-assistant' ),
					'empty'             => __( 'Tell me what you need — budget, occasion, or style. Tap a suggestion or type your own.', 'shopask-ai-shopping-assistant' ),
					'error'             => __( 'Something went wrong. Please try again.', 'shopask-ai-shopping-assistant' ),
					'thinking'          => __( 'Searching the catalog…', 'shopask-ai-shopping-assistant' ),
					'openLabel'         => __( 'Open ShopAsk AI', 'shopask-ai-shopping-assistant' ),
					'closeLabel'        => __( 'Close ShopAsk AI', 'shopask-ai-shopping-assistant' ),
					'poweredBy'         => __( 'Powered by ShopAsk AI', 'shopask-ai-shopping-assistant' ),
					'voice'             => __( 'Voice input', 'shopask-ai-shopping-assistant' ),
					'listening'         => __( 'Listening…', 'shopask-ai-shopping-assistant' ),
					'addToCart'         => __( 'Add to cart', 'shopask-ai-shopping-assistant' ),
					'adding'            => __( 'Adding…', 'shopask-ai-shopping-assistant' ),
					'added'             => __( 'Added to cart', 'shopask-ai-shopping-assistant' ),
					'viewProduct'       => __( 'View product', 'shopask-ai-shopping-assistant' ),
					'cartError'         => __( 'Could not add to cart. Open the product page instead.', 'shopask-ai-shopping-assistant' ),
				),
			)
		);
	}

	/**
	 * Site-wide floating launcher.
	 */
	public static function render_floating_root(): void {
		if ( ! self::show_floating() ) {
			return;
		}
		self::ensure_assets();
		echo '<div id="wcai-assistant-root" class="wcai-root" data-wcai-mode="floating"></div>';
	}

	/**
	 * Optional auto-insert of search bar (hero / top of page).
	 */
	public static function maybe_auto_insert_search(): void {
		if ( is_admin() || ! self::widgets_enabled() ) {
			return;
		}
		$loc = (string) WCAI_Settings::get( 'auto_search_location', 'none' );
		if ( 'body_open' !== $loc ) {
			return;
		}
		echo self::render_mount( 'search', array( 'class' => 'wcai-auto-search' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Shortcode: [shopask_ai type="search|button|panel|floating" label="..." class="..."]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ): string {
		if ( ! self::widgets_enabled() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'type'  => 'panel',
				'label' => '',
				'class' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'shopask_ai'
		);

		$type = sanitize_key( $atts['type'] );
		if ( ! in_array( $type, self::types(), true ) ) {
			$type = 'panel';
		}

		return self::render_mount(
			$type,
			array(
				'label' => sanitize_text_field( $atts['label'] ),
				'class' => sanitize_html_class( $atts['class'] ),
			)
		);
	}

	/**
	 * Dynamic block render.
	 *
	 * @param array $attributes Block attrs.
	 * @return string
	 */
	public static function render_block( $attributes = array() ): string {
		$type  = isset( $attributes['type'] ) ? sanitize_key( $attributes['type'] ) : 'panel';
		$label = isset( $attributes['label'] ) ? sanitize_text_field( $attributes['label'] ) : '';
		return self::shortcode(
			array(
				'type'  => $type,
				'label' => $label,
			)
		);
	}

	/**
	 * Markup for a widget mount point.
	 *
	 * @param string $type Mode.
	 * @param array  $args Extra: label, class.
	 * @return string
	 */
	public static function render_mount( string $type, array $args = array() ): string {
		if ( ! self::widgets_enabled() ) {
			return '';
		}
		if ( ! in_array( $type, self::types(), true ) ) {
			$type = 'panel';
		}

		self::ensure_assets();

		$id    = 'wcai-' . $type . '-' . wp_unique_id();
		$class = 'wcai-root wcai-' . $type . '-root';
		if ( ! empty( $args['class'] ) ) {
			$class .= ' ' . $args['class'];
		}

		$label = isset( $args['label'] ) ? (string) $args['label'] : '';

		return sprintf(
			'<div id="%1$s" class="%2$s" data-wcai-mode="%3$s" data-wcai-label="%4$s"></div>',
			esc_attr( $id ),
			esc_attr( $class ),
			esc_attr( $type ),
			esc_attr( $label )
		);
	}
}
