<?php
/**
 * Dynamic block render (fallback if register_block_type render_callback not used).
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WCAI_Widget' ) ) {
	return '';
}

echo WCAI_Widget::shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
