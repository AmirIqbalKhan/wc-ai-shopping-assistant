<?php
/**
 * Shared admin hub chrome (tabs, assets).
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce â†’ AI Assistant admin shell.
 */
class WCAI_Admin {

	/**
	 * Hook assets.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether current screen is the AI Assistant hub.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	public static function is_hub_screen( string $hook ): bool {
		return false !== strpos( $hook, 'wcai-settings' );
	}

	/**
	 * Current hub tab.
	 *
	 * @return string settings|analytics|insights
	 */
	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'settings', 'analytics', 'insights' ), true ) ) {
			return 'settings';
		}
		return $tab;
	}

	/**
	 * Hub URL for a tab.
	 *
	 * @param string $tab Tab.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function url( string $tab = 'settings', array $extra = array() ): string {
		$args = array_merge(
			array(
				'page' => 'wcai-settings',
				'tab'  => $tab,
			),
			$extra
		);
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Enqueue admin CSS/JS on hub.
	 *
	 * @param string $hook Hook.
	 */
	public static function enqueue( $hook ): void {
		$hook = is_string( $hook ) ? $hook : '';
		if ( ! self::is_hub_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'wcai-admin',
			WCAI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WCAI_VERSION
		);

		wp_enqueue_script(
			'wcai-admin',
			WCAI_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			WCAI_VERSION,
			true
		);

		$providers = class_exists( 'WCAI_Providers' ) ? WCAI_Providers::all() : array();
		$state     = get_option( 'wcai_reindex_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		wp_localize_script(
			'wcai-admin',
			'wcaiAdmin',
			array(
				'statusUrl'      => esc_url_raw( rest_url( 'wcai/v1/reindex-status' ) ),
				'testUrl'        => esc_url_raw( rest_url( 'wcai/v1/test-connection' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'providers'      => $providers,
				'tab'            => self::current_tab(),
				'indexed'        => WCAI_Indexer::indexed_count(),
				'reindexState'   => $state,
				'hasKey'         => (bool) WCAI_Settings::get( 'api_key' ),
				'embeddingMode'  => (string) WCAI_Settings::get( 'embedding_mode', 'auto' ),
				'provider'       => (string) WCAI_Settings::get( 'provider', 'openai' ),
				'i18n'           => array(
					'testing'  => __( 'Testing...', 'shopask-ai-shopping-assistant' ),
					'testOk'   => __( 'Connection OK', 'shopask-ai-shopping-assistant' ),
					'testFail' => __( 'Connection failed', 'shopask-ai-shopping-assistant' ),
					/* translators: 1: done count, 2: total count, 3: status */
					'progress' => __( 'Progress: %1$d / %2$d (%3$s)', 'shopask-ai-shopping-assistant' ),
					'custom'   => __( '- Custom / other -', 'shopask-ai-shopping-assistant' ),
				),
			)
		);
	}

	/**
	 * Render tab navigation.
	 *
	 * @param string $active Active tab.
	 */
	public static function render_tabs( string $active ): void {
		$tabs = array(
			'settings'  => __( 'Settings', 'shopask-ai-shopping-assistant' ),
			'analytics' => __( 'Analytics', 'shopask-ai-shopping-assistant' ),
			'insights'  => __( 'Insights', 'shopask-ai-shopping-assistant' ),
		);
		echo '<nav class="wcai-admin-tabs" aria-label="' . esc_attr__( 'AI Assistant sections', 'shopask-ai-shopping-assistant' ) . '">';
		foreach ( $tabs as $id => $label ) {
			printf(
				'<a class="wcai-admin-tabs__item%s" href="%s">%s</a>',
				$id === $active ? ' is-active' : '',
				esc_url( self::url( $id ) ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Status strip for settings.
	 */
	public static function render_status_strip(): void {
		$indexed = WCAI_Indexer::indexed_count();
		$state   = get_option( 'wcai_reindex_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$status = (string) ( $state['status'] ?? 'idle' );
		$done   = (int) ( $state['done'] ?? 0 );
		$total  = (int) ( $state['total'] ?? 0 );
		$mode   = (string) WCAI_Settings::get( 'embedding_mode', 'auto' );
		$prov   = (string) WCAI_Settings::get( 'provider', 'openai' );
		$has    = (bool) WCAI_Settings::get( 'api_key' );
		$label  = class_exists( 'WCAI_Providers' ) ? ( WCAI_Providers::get( $prov )['label'] ?? $prov ) : $prov;

		$reindex_label = 'idle' === $status
			? __( 'Idle', 'shopask-ai-shopping-assistant' )
			: sprintf(
				/* translators: 1: done 2: total */
				__( '%1$d / %2$d', 'shopask-ai-shopping-assistant' ),
				$done,
				$total
			);
		?>
		<div class="wcai-status" role="region" aria-label="<?php esc_attr_e( 'Assistant status', 'shopask-ai-shopping-assistant' ); ?>">
			<div class="wcai-status__card">
				<span class="wcai-status__label"><?php esc_html_e( 'Provider', 'shopask-ai-shopping-assistant' ); ?></span>
				<strong class="wcai-status__value"><?php echo esc_html( $label ); ?></strong>
				<span class="wcai-status__meta"><?php echo $has ? esc_html__( 'API key saved', 'shopask-ai-shopping-assistant' ) : esc_html__( 'No API key', 'shopask-ai-shopping-assistant' ); ?></span>
			</div>
			<div class="wcai-status__card">
				<span class="wcai-status__label"><?php esc_html_e( 'Indexed', 'shopask-ai-shopping-assistant' ); ?></span>
				<strong class="wcai-status__value"><?php echo esc_html( (string) $indexed ); ?></strong>
				<span class="wcai-status__meta"><?php esc_html_e( 'Catalog rows', 'shopask-ai-shopping-assistant' ); ?></span>
			</div>
			<div class="wcai-status__card">
				<span class="wcai-status__label"><?php esc_html_e( 'Reindex', 'shopask-ai-shopping-assistant' ); ?></span>
				<strong class="wcai-status__value" id="wcai-status-reindex"><?php echo esc_html( ucfirst( $status ) ); ?></strong>
				<span class="wcai-status__meta" id="wcai-status-reindex-meta"><?php echo esc_html( $reindex_label ); ?></span>
			</div>
			<div class="wcai-status__card">
				<span class="wcai-status__label"><?php esc_html_e( 'Embeddings', 'shopask-ai-shopping-assistant' ); ?></span>
				<strong class="wcai-status__value"><?php echo esc_html( ucfirst( $mode ) ); ?></strong>
				<span class="wcai-status__meta"><?php esc_html_e( 'Mode', 'shopask-ai-shopping-assistant' ); ?></span>
			</div>
		</div>
		<?php
	}
}
