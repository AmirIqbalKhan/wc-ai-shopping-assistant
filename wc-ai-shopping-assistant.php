<?php
/**
 * Plugin Name:       WooCommerce AI Shopping Assistant
 * Plugin URI:        https://github.com/AmirIqbalKhan/wc-ai-shopping-assistant
 * Description:       Conversational, natural-language product finder for WooCommerce stores.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Aamir Iqbal Khan
 * Author URI:        https://github.com/AmirIqbalKhan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-ai-shopping-assistant
 * Requires Plugins:  woocommerce
 *
 * @package WCAI
 * @author  Aamir Iqbal Khan
 */

defined( 'ABSPATH' ) || exit;

define( 'WCAI_VERSION', '0.3.0' );
define( 'WCAI_PLUGIN_FILE', __FILE__ );
define( 'WCAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload plugin classes from includes/.
 *
 * @param string $class Class name.
 */
function wcai_autoload( string $class ): void {
	if ( strpos( $class, 'WCAI_' ) !== 0 ) {
		return;
	}

	$slug = strtolower( str_replace( '_', '-', $class ) );
	$file = WCAI_PLUGIN_DIR . 'includes/class-' . substr( $slug, 5 ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'wcai_autoload' );

/**
 * Check environment requirements.
 *
 * @return bool
 */
function wcai_requirements_met(): bool {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		return false;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		return false;
	}

	return true;
}

/**
 * Admin notice when requirements are not met.
 */
function wcai_requirements_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'WooCommerce AI Shopping Assistant requires PHP 8.1+ and WooCommerce 8.0+.', 'wc-ai-shopping-assistant' );
	echo '</p></div>';
}

register_activation_hook( __FILE__, array( 'WCAI_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCAI_Installer', 'deactivate' ) );

/**
 * Boot the plugin after plugins are loaded.
 */
function wcai_init(): void {
	if ( ! wcai_requirements_met() ) {
		add_action( 'admin_notices', 'wcai_requirements_notice' );
		return;
	}

	WCAI_Installer::maybe_upgrade();

	WCAI_Hooks::init();
	WCAI_Settings::init();
	WCAI_REST::init();
	WCAI_Widget::init();
	WCAI_Analytics::init();
	WCAI_Insights::init();
}
add_action( 'plugins_loaded', 'wcai_init' );
