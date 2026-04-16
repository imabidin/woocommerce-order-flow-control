<?php
/**
 * Plugin Name: WooCommerce Order Flow Control
 * Plugin URI:  https://github.com/imabidin/woocommerce-order-flow-control
 * Description: Enforces one-way order status transitions for accounting integrity. Once an order reaches a locked status (e.g. "processing"), it can only move forward. Refunds remain possible at any stage.
 * Version:     2.0.0
 * Author:      Abidin Alkilinc
 * Author URI:  https://www.alkilinc.de
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wofc
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 */

defined( 'ABSPATH' ) || exit;

define( 'WOFC_VERSION',  '2.0.0' );
define( 'WOFC_FILE',     __FILE__ );
define( 'WOFC_PATH',     plugin_dir_path( __FILE__ ) );
define( 'WOFC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Set default options on activation.
 */
register_activation_hook( __FILE__, function () {
	require_once WOFC_PATH . 'includes/class-wofc-transition-manager.php';
	WOFC_Transition_Manager::set_defaults();
} );

/**
 * Load plugin after WooCommerce is ready.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p><strong>WooCommerce Order Flow Control</strong> '
				. esc_html__( 'requires WooCommerce to be installed and active.', 'wofc' )
				. '</p></div>';
		} );
		return;
	}

	require_once WOFC_PATH . 'includes/class-wofc-transition-manager.php';
	require_once WOFC_PATH . 'includes/class-wofc-admin.php';

	WOFC_Transition_Manager::init();

	if ( is_admin() ) {
		WOFC_Admin::init();
	}
}, 20 );

/**
 * Register the WooCommerce settings tab.
 */
add_filter( 'woocommerce_get_settings_pages', function ( $pages ) {
	require_once WOFC_PATH . 'includes/class-wofc-settings.php';
	$pages[] = new WOFC_Settings();
	return $pages;
} );

/**
 * Load text domain for translations.
 */
add_action( 'init', function () {
	load_plugin_textdomain( 'wofc', false, dirname( WOFC_BASENAME ) . '/languages' );
} );
