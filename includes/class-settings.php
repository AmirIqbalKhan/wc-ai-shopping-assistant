<?php
/**
 * Admin settings page.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → AI Assistant settings.
 */
class WCAI_Settings {

	/**
	 * Hook admin UI.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_wcai_reindex', array( __CLASS__, 'handle_reindex' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_reindex_notice' ) );
	}

	/**
	 * Add submenu under WooCommerce (single hub for Settings / Analytics / Insights).
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'AI Assistant', 'wc-ai-shopping-assistant' ),
			__( 'AI Assistant', 'wc-ai-shopping-assistant' ),
			'manage_woocommerce',
			'wcai-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Redirect after reindex action.
	 *
	 * @param string $status Status slug.
	 */
	private static function redirect_reindex( string $status ): void {
		wp_safe_redirect(
			WCAI_Admin::url(
				'settings',
				array(
					'wcai_reindex' => $status,
				)
			)
		);
		exit;
	}

	/**
	 * Register settings fields.
	 */
	public static function register_settings(): void {
		register_setting(
			'wcai_settings_group',
			'wcai_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
				'capability'        => 'manage_woocommerce',
			)
		);
	}

	/**
	 * Sanitize settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$current = self::get_all();
		$input   = is_array( $input ) ? $input : array();
		$defaults = WCAI_Installer::default_settings();

		$placement = isset( $input['placement_mode'] ) ? sanitize_key( $input['placement_mode'] ) : ( $current['placement_mode'] ?? 'both' );
		if ( ! in_array( $placement, array( 'floating', 'embedded', 'both' ), true ) ) {
			$placement = 'both';
		}

		$auto_search = isset( $input['auto_search_location'] ) ? sanitize_key( $input['auto_search_location'] ) : ( $current['auto_search_location'] ?? 'none' );
		if ( ! in_array( $auto_search, array( 'none', 'body_open' ), true ) ) {
			$auto_search = 'none';
		}

		$index_mode = isset( $input['index_mode'] ) ? sanitize_key( $input['index_mode'] ) : ( $current['index_mode'] ?? 'parent' );
		if ( ! in_array( $index_mode, array( 'parent', 'variation' ), true ) ) {
			$index_mode = 'parent';
		}

		$provider = isset( $input['provider'] ) ? sanitize_key( $input['provider'] ) : ( $current['provider'] ?? 'openai' );
		if ( ! in_array( $provider, WCAI_Providers::ids(), true ) ) {
			$provider = 'openai';
		}

		$embedding_mode = isset( $input['embedding_mode'] ) ? sanitize_key( $input['embedding_mode'] ) : ( $current['embedding_mode'] ?? 'auto' );
		if ( ! in_array( $embedding_mode, array( 'auto', 'local', 'api' ), true ) ) {
			$embedding_mode = 'auto';
		}

		$meta     = WCAI_Providers::get( $provider );
		$api_base = isset( $input['api_base'] ) ? esc_url_raw( trim( (string) $input['api_base'] ) ) : ( $current['api_base'] ?? '' );

		// When provider changes / base empty, apply preset base (except custom).
		$prev_provider = (string) ( $current['provider'] ?? '' );
		if ( 'custom' !== $provider ) {
			$preset = WCAI_Providers::default_base( $provider );
			if ( '' === $api_base || $provider !== $prev_provider ) {
				$api_base = $preset;
			}
		}

		$chat_model = isset( $input['chat_model'] ) ? sanitize_text_field( $input['chat_model'] ) : ( $current['chat_model'] ?? '' );
		if ( '' === $chat_model && ! empty( $meta['default_chat'] ) ) {
			$chat_model = $meta['default_chat'];
		}

		$embedding_model = isset( $input['embedding_model'] ) ? sanitize_text_field( $input['embedding_model'] ) : ( $current['embedding_model'] ?? '' );
		if ( '' === $embedding_model && ! empty( $meta['default_embedding'] ) ) {
			$embedding_model = $meta['default_embedding'];
		}

		$plan = (string) ( $current['plan'] ?? 'agency' );

		$submitted_key = isset( $input['api_key'] ) ? sanitize_text_field( (string) $input['api_key'] ) : '';
		$api_key       = '' !== $submitted_key ? $submitted_key : (string) ( $current['api_key'] ?? '' );

		// Integrations / plan fields are hidden for now — preserve existing values.
		$webhook = (string) ( $current['webhook_url'] ?? '' );
		if ( $webhook && WCAI_Installer::is_blocked_url( $webhook ) ) {
			$webhook = '';
		}
		if ( $api_base && WCAI_Installer::is_blocked_url( $api_base ) ) {
			$api_base = '';
		}

		$daily_cap = isset( $input['daily_query_cap'] )
			? max( 0, absint( $input['daily_query_cap'] ) )
			: (int) ( $current['daily_query_cap'] ?? WCAI_Usage::DEFAULT_DAILY_CAP );

		$out = array(
			'provider'             => $provider,
			'api_base'             => $api_base,
			'embedding_mode'       => $embedding_mode,
			'embedding_model'      => $embedding_model,
			'chat_model'           => $chat_model,
			'widget_enabled'       => ! empty( $input['widget_enabled'] ) ? '1' : '0',
			'show_floating'        => ! empty( $input['show_floating'] ) ? '1' : '0',
			'auto_search_location' => $auto_search,
			'placement_mode'       => $placement,
			'top_n'                => isset( $input['top_n'] ) ? max( 5, min( 50, absint( $input['top_n'] ) ) ) : (int) ( $current['top_n'] ?? 20 ),
			'similarity_threshold' => isset( $input['similarity_threshold'] ) ? max( 0, min( 1, (float) $input['similarity_threshold'] ) ) : (float) ( $current['similarity_threshold'] ?? 0.25 ),
			'prefilter_limit'      => isset( $input['prefilter_limit'] ) ? max( 50, min( 1000, absint( $input['prefilter_limit'] ) ) ) : (int) ( $current['prefilter_limit'] ?? 300 ),
			'index_mode'           => $index_mode,
			'rate_limit_anon'      => (int) ( $current['rate_limit_anon'] ?? 20 ),
			'rate_limit_user'      => (int) ( $current['rate_limit_user'] ?? 60 ),
			'rate_limit_per_min'   => (int) ( $current['rate_limit_per_min'] ?? 20 ),
			'plan'                 => $plan,
			'monthly_query_cap'    => max( 0, (int) ( $current['monthly_query_cap'] ?? 0 ) ),
			'daily_query_cap'      => $daily_cap,
			'hide_branding'        => ! empty( $input['hide_branding'] ) ? '1' : '0',
			'widget_title'         => isset( $input['widget_title'] ) ? sanitize_text_field( $input['widget_title'] ) : ( $current['widget_title'] ?? '' ),
			'accent_color'         => isset( $input['accent_color'] ) ? sanitize_hex_color( $input['accent_color'] ) ?: '#0d9488' : ( $current['accent_color'] ?? '#0d9488' ),
			'webhook_url'          => $webhook,
		);

		$merged = array_merge( $defaults, $out );
		foreach ( WCAI_Installer::SECRET_KEYS as $sk ) {
			unset( $merged[ $sk ] );
		}

		$secrets = array(
			'api_key'            => $api_key,
			'public_api_key'     => (string) ( $current['public_api_key'] ?? '' ),
			'agency_license_key' => (string) ( $current['agency_license_key'] ?? '' ),
		);
		update_option( 'wcai_secrets', $secrets, false );
		WCAI_Installer::force_options_autoload_no();

		return $merged;
	}

	/**
	 * Get all settings with defaults (secrets merged for reads).
	 *
	 * @return array
	 */
	public static function get_all(): array {
		$defaults = WCAI_Installer::default_settings();
		$saved    = get_option( 'wcai_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$secrets = get_option( 'wcai_secrets', array() );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}
		return array_merge( $defaults, $saved, $secrets );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Queue a full catalog reindex.
	 */
	public static function handle_reindex(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wc-ai-shopping-assistant' ) );
		}

		check_admin_referer( 'wcai_reindex' );

		if ( ! self::get( 'api_key' ) && ! WCAI_OpenAI_Client::use_local_embeddings() ) {
			self::redirect_reindex( 'no_key' );
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::redirect_reindex( 'as_missing' );
		}

		$queued = WCAI_Indexer::queue_full_reindex();
		if ( ! $queued ) {
			self::redirect_reindex( 'as_missing' );
		}

		self::redirect_reindex( 'queued' );
	}

	/**
	 * Flash notice after reindex action.
	 */
	public static function maybe_reindex_notice(): void {
		if ( ! isset( $_GET['page'], $_GET['wcai_reindex'] ) || 'wcai-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$status = sanitize_text_field( wp_unslash( $_GET['wcai_reindex'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'queued' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Catalog reindex has been queued. Progress runs in the background via Action Scheduler.', 'wc-ai-shopping-assistant' );
			echo '</p></div>';
		} elseif ( 'no_key' === $status ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Add an API key before reindexing (or use LongCat/Claude with local embeddings).', 'wc-ai-shopping-assistant' );
			echo '</p></div>';
		} elseif ( 'as_missing' === $status ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Action Scheduler is not available. Ensure WooCommerce is active and up to date, then try again.', 'wc-ai-shopping-assistant' );
			echo '</p></div>';
		}
	}

	/**
	 * Render AI Assistant hub (settings / analytics / insights tabs).
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab = WCAI_Admin::current_tab();

		echo '<div class="wrap wcai-admin">';
		echo '<h1>' . esc_html__( 'AI Shopping Assistant', 'wc-ai-shopping-assistant' ) . '</h1>';
		WCAI_Admin::render_tabs( $tab );

		if ( 'analytics' === $tab ) {
			WCAI_Analytics::render_page();
			echo '</div>';
			return;
		}
		if ( 'insights' === $tab ) {
			WCAI_Insights::render_page();
			echo '</div>';
			return;
		}

		self::render_settings_tab();
		echo '</div>';
	}

	/**
	 * Settings tab content.
	 */
	private static function render_settings_tab(): void {
		if ( ! class_exists( 'WCAI_Providers' ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Plugin files are incomplete (providers missing). Reinstall the plugin.', 'wc-ai-shopping-assistant' );
			echo '</p></div>';
			return;
		}

		$settings = self::get_all();
		$count    = WCAI_Indexer::indexed_count();
		$state    = get_option( 'wcai_reindex_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$total = (int) ( $state['total'] ?? 0 );
		$done  = (int) ( $state['done'] ?? 0 );
		$pct   = $total > 0 ? min( 100, (int) round( ( $done / $total ) * 100 ) ) : 0;

		WCAI_Admin::render_status_strip();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wcai_settings_group' ); ?>

			<section class="wcai-card">
				<h2><?php esc_html_e( 'API & models', 'wc-ai-shopping-assistant' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Connect a provider, then test before indexing.', 'wc-ai-shopping-assistant' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcai_provider"><?php esc_html_e( 'AI provider', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<select id="wcai_provider" name="wcai_settings[provider]">
								<?php foreach ( WCAI_Providers::all() as $pid => $pinfo ) : ?>
									<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $settings['provider'] ?? 'openai', $pid ); ?>><?php echo esc_html( $pinfo['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="wcai_provider_hint"><?php esc_html_e( 'Claude and LongCat use local embeddings (no embedding API). OpenAI, Gemini, and OpenRouter can use API embeddings.', 'wc-ai-shopping-assistant' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_api_key"><?php esc_html_e( 'API Key', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="wcai_api_key" name="wcai_settings[api_key]" value="" placeholder="<?php echo $settings['api_key'] ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'wc-ai-shopping-assistant' ) : ''; ?>" autocomplete="off" />
							<p class="description" id="wcai_key_hint"></p>
							<p>
								<button type="button" class="button button-secondary" id="wcai-test-connection"><?php esc_html_e( 'Test connection', 'wc-ai-shopping-assistant' ); ?></button>
								<span id="wcai-test-result" class="wcai-callout" aria-live="polite"></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_api_base"><?php esc_html_e( 'API base URL', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="wcai_api_base" name="wcai_settings[api_base]" value="<?php echo esc_attr( $settings['api_base'] ?? '' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_chat_model"><?php esc_html_e( 'Chat model', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<select id="wcai_chat_model_select"></select>
							<input type="text" class="regular-text" id="wcai_chat_model" name="wcai_settings[chat_model]" value="<?php echo esc_attr( $settings['chat_model'] ); ?>" style="margin-top:6px;display:block" />
							<p class="description"><?php esc_html_e( 'Pick from the list or type a custom model ID in the text field.', 'wc-ai-shopping-assistant' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_embedding_mode"><?php esc_html_e( 'Embeddings', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<select id="wcai_embedding_mode" name="wcai_settings[embedding_mode]">
								<option value="auto" <?php selected( $settings['embedding_mode'] ?? 'auto', 'auto' ); ?>><?php esc_html_e( 'Auto (recommended)', 'wc-ai-shopping-assistant' ); ?></option>
								<option value="local" <?php selected( $settings['embedding_mode'] ?? '', 'local' ); ?>><?php esc_html_e( 'Local only', 'wc-ai-shopping-assistant' ); ?></option>
								<option value="api" <?php selected( $settings['embedding_mode'] ?? '', 'api' ); ?>><?php esc_html_e( 'API embeddings', 'wc-ai-shopping-assistant' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_embedding_model"><?php esc_html_e( 'Embedding model', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<select id="wcai_embedding_model_select"></select>
							<input type="text" class="regular-text" id="wcai_embedding_model" name="wcai_settings[embedding_model]" value="<?php echo esc_attr( $settings['embedding_model'] ); ?>" style="margin-top:6px;display:block" />
						</td>
					</tr>
				</table>
			</section>

			<section class="wcai-card">
				<h2><?php esc_html_e( 'Widget & placement', 'wc-ai-shopping-assistant' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Floating bubble, search bar, shortcodes, and accent.', 'wc-ai-shopping-assistant' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable assets', 'wc-ai-shopping-assistant' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wcai_settings[widget_enabled]" value="1" <?php checked( $settings['widget_enabled'], '1' ); ?> />
								<?php esc_html_e( 'Load assistant scripts on the storefront', 'wc-ai-shopping-assistant' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Floating button', 'wc-ai-shopping-assistant' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wcai_settings[show_floating]" value="1" <?php checked( $settings['show_floating'] ?? '1', '1' ); ?> />
								<?php esc_html_e( 'Show site-wide floating AI bubble', 'wc-ai-shopping-assistant' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_auto_search"><?php esc_html_e( 'Auto-insert search bar', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<select id="wcai_auto_search" name="wcai_settings[auto_search_location]">
								<option value="none" <?php selected( $settings['auto_search_location'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'Off — place manually', 'wc-ai-shopping-assistant' ); ?></option>
								<option value="body_open" <?php selected( $settings['auto_search_location'] ?? '', 'body_open' ); ?>><?php esc_html_e( 'Top of page (after body opens / near header)', 'wc-ai-shopping-assistant' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Or place widgets yourself with shortcodes / the Gutenberg block (hero, nav area, product pages, etc.).', 'wc-ai-shopping-assistant' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shortcodes', 'wc-ai-shopping-assistant' ); ?></th>
						<td>
							<p><code>[wc_ai_assistant type="search"]</code> — <?php esc_html_e( 'AI search bar (hero / header / any section)', 'wc-ai-shopping-assistant' ); ?></p>
							<p><code>[wc_ai_assistant type="button" label="Ask AI"]</code> — <?php esc_html_e( 'Button that opens the assistant', 'wc-ai-shopping-assistant' ); ?></p>
							<p><code>[wc_ai_assistant type="panel"]</code> — <?php esc_html_e( 'Full embedded chat panel', 'wc-ai-shopping-assistant' ); ?></p>
							<p><code>[wc_ai_assistant type="floating"]</code> — <?php esc_html_e( 'Local floating bubble on that page', 'wc-ai-shopping-assistant' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_widget_title"><?php esc_html_e( 'Widget title', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td><input type="text" class="regular-text" id="wcai_widget_title" name="wcai_settings[widget_title]" value="<?php echo esc_attr( $settings['widget_title'] ); ?>" placeholder="<?php esc_attr_e( 'Shopping Assistant', 'wc-ai-shopping-assistant' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_accent"><?php esc_html_e( 'Accent color', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td><input type="color" id="wcai_accent" name="wcai_settings[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ?: '#0d9488' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'White-label', 'wc-ai-shopping-assistant' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wcai_settings[hide_branding]" value="1" <?php checked( $settings['hide_branding'], '1' ); ?> />
								<?php esc_html_e( 'Hide “Powered by” branding', 'wc-ai-shopping-assistant' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</section>

			<section class="wcai-card">
				<h2><?php esc_html_e( 'Retrieval & indexing', 'wc-ai-shopping-assistant' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Tune how products are ranked and how many candidates the assistant considers.', 'wc-ai-shopping-assistant' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcai_index_mode"><?php esc_html_e( 'Index mode', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<select id="wcai_index_mode" name="wcai_settings[index_mode]">
								<option value="parent" <?php selected( $settings['index_mode'], 'parent' ); ?>><?php esc_html_e( 'Parent products', 'wc-ai-shopping-assistant' ); ?></option>
								<option value="variation" <?php selected( $settings['index_mode'], 'variation' ); ?>><?php esc_html_e( 'Variations (size/color rows)', 'wc-ai-shopping-assistant' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_top_n"><?php esc_html_e( 'Candidates (top N)', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td><input type="number" min="5" max="50" id="wcai_top_n" name="wcai_settings[top_n]" value="<?php echo esc_attr( (string) $settings['top_n'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_threshold"><?php esc_html_e( 'Similarity threshold', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" max="1" id="wcai_threshold" name="wcai_settings[similarity_threshold]" value="<?php echo esc_attr( (string) $settings['similarity_threshold'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_prefilter"><?php esc_html_e( 'Prefilter limit', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td><input type="number" min="50" max="1000" id="wcai_prefilter" name="wcai_settings[prefilter_limit]" value="<?php echo esc_attr( (string) $settings['prefilter_limit'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Max rows to cosine-rank after SQL/FULLTEXT narrowing (in-DB scale).', 'wc-ai-shopping-assistant' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcai_daily_cap"><?php esc_html_e( 'Daily query limit', 'wc-ai-shopping-assistant' ); ?></label></th>
						<td>
							<input type="number" min="0" max="100000" id="wcai_daily_cap" name="wcai_settings[daily_query_cap]" value="<?php echo esc_attr( (string) ( $settings['daily_query_cap'] ?? 500 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Soft cap on storefront AI queries per UTC day (default 500). Uses your provider API key. Set 0 for unlimited (not recommended).', 'wc-ai-shopping-assistant' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'wc-ai-shopping-assistant' ) ); ?>
			</section>
		</form>

		<section class="wcai-card">
			<h2><?php esc_html_e( 'Catalog index', 'wc-ai-shopping-assistant' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of indexed products */
					esc_html__( 'Currently indexed rows: %d', 'wc-ai-shopping-assistant' ),
					(int) $count
				);
				?>
			</p>
			<div id="wcai-reindex-progress" class="wcai-progress">
				<div class="wcai-progress__track">
					<div id="wcai-reindex-bar" class="wcai-progress__bar" style="width:<?php echo (int) $pct; ?>%"></div>
				</div>
				<p id="wcai-reindex-label" class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: done 2: total 3: status */
							__( 'Progress: %1$d / %2$d (%3$s)', 'wc-ai-shopping-assistant' ),
							$done,
							$total,
							(string) ( $state['status'] ?? 'idle' )
						)
					);
					?>
				</p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wcai_reindex" />
				<?php wp_nonce_field( 'wcai_reindex' ); ?>
				<?php submit_button( __( 'Reindex Catalog', 'wc-ai-shopping-assistant' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}
}
