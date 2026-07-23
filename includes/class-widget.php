<?php
/**
 * Frontend chat widget, shortcode, and block mount.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues and mounts the storefront assistant UI.
 */
class WCAI_Widget {

	/**
	 * Whether assets were enqueued this request.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Hook frontend assets.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_floating_root' ) );
		add_shortcode( 'wc_ai_assistant', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Register Gutenberg block.
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
			array( 'wp-blocks', 'wp-element', 'wp-block-editor' ),
			WCAI_VERSION,
			true
		);

		register_block_type(
			$block_dir,
			array(
				'editor_script'   => 'wcai-ai-assistant-editor',
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/**
	 * Placement mode helper.
	 *
	 * @return string
	 */
	private static function placement(): string {
		return (string) WCAI_Settings::get( 'placement_mode', 'floating' );
	}

	/**
	 * Whether floating launcher should show.
	 *
	 * @return bool
	 */
	private static function show_floating(): bool {
		if ( is_admin() || '1' !== (string) WCAI_Settings::get( 'widget_enabled', '1' ) ) {
			return false;
		}
		$mode = self::placement();
		return in_array( $mode, array( 'floating', 'both' ), true );
	}

	/**
	 * Whether embedded mounts are allowed.
	 *
	 * @return bool
	 */
	private static function allow_embedded(): bool {
		if ( is_admin() || '1' !== (string) WCAI_Settings::get( 'widget_enabled', '1' ) ) {
			return false;
		}
		$mode = self::placement();
		return in_array( $mode, array( 'embedded', 'both' ), true );
	}

	/**
	 * Enqueue when floating or when shortcode/block may appear (both/embedded load on demand too).
	 */
	public static function maybe_enqueue(): void {
		if ( is_admin() || '1' !== (string) WCAI_Settings::get( 'widget_enabled', '1' ) ) {
			return;
		}
		// Always enqueue when floating; for embedded-only, shortcode/block will call ensure_assets.
		if ( self::show_floating() ) {
			self::ensure_assets();
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
			$title = __( 'Shopping Assistant', 'wc-ai-shopping-assistant' );
		}

		wp_enqueue_style(
			'wcai-widget',
			WCAI_PLUGIN_URL . 'assets/css/widget.css',
			array(),
			WCAI_VERSION
		);

		$accent = (string) WCAI_Settings::get( 'accent_color', '#0d9488' );
		$custom = ':root{--wcai-accent:' . esc_attr( $accent ) . ';}';
		wp_add_inline_style( 'wcai-widget', $custom );

		wp_enqueue_script(
			'wcai-widget',
			WCAI_PLUGIN_URL . 'assets/js/widget.js',
			array(),
			WCAI_VERSION,
			true
		);

		wp_localize_script(
			'wcai-widget',
			'wcaiWidget',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'wcai/v1/query' ) ),
				'clickUrl'     => esc_url_raw( rest_url( 'wcai/v1/click' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'currency'     => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
				'hideBranding' => '1' === (string) WCAI_Settings::get( 'hide_branding', '0' ),
				'accent'       => $accent,
				'i18n'         => array(
					'title'       => $title,
					'placeholder' => __( 'Describe what you are looking for…', 'wc-ai-shopping-assistant' ),
					'send'        => __( 'Send', 'wc-ai-shopping-assistant' ),
					'empty'       => __( 'Ask me to find products — e.g. “lightweight rain jacket under $80”. You can refine with follow-ups.', 'wc-ai-shopping-assistant' ),
					'error'       => __( 'Something went wrong. Please try again.', 'wc-ai-shopping-assistant' ),
					'thinking'    => __( 'Searching the catalog…', 'wc-ai-shopping-assistant' ),
					'openLabel'   => __( 'Open shopping assistant', 'wc-ai-shopping-assistant' ),
					'closeLabel'  => __( 'Close shopping assistant', 'wc-ai-shopping-assistant' ),
					'poweredBy'   => __( 'Powered by AI Assistant', 'wc-ai-shopping-assistant' ),
					'voice'       => __( 'Voice input', 'wc-ai-shopping-assistant' ),
					'listening'   => __( 'Listening…', 'wc-ai-shopping-assistant' ),
				),
			)
		);
	}

	/**
	 * Floating mount in footer.
	 */
	public static function render_floating_root(): void {
		if ( ! self::show_floating() ) {
			return;
		}
		self::ensure_assets();
		echo '<div id="wcai-assistant-root" data-wcai-mode="floating"></div>';
	}

	/**
	 * Shortcode output.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ): string {
		if ( ! self::allow_embedded() && 'both' !== self::placement() && 'embedded' !== self::placement() ) {
			// If placement is floating-only, still allow shortcode when widget enabled.
			if ( '1' !== (string) WCAI_Settings::get( 'widget_enabled', '1' ) ) {
				return '';
			}
		}
		if ( '1' !== (string) WCAI_Settings::get( 'widget_enabled', '1' ) ) {
			return '';
		}
		if ( 'floating' === self::placement() ) {
			// Still render embedded panel when shortcode is used explicitly.
		}
		self::ensure_assets();
		$id = 'wcai-embed-' . wp_unique_id();
		return '<div class="wcai-embed-root" id="' . esc_attr( $id ) . '" data-wcai-mode="embedded"></div>';
	}

	/**
	 * Dynamic block render.
	 *
	 * @param array $attributes Block attrs.
	 * @return string
	 */
	public static function render_block( $attributes = array() ): string {
		return self::shortcode();
	}
}
