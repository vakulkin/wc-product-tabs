<?php
/**
 * Plugin Name: WC Product Tabs
 * Description: Custom order tabs (Flakony, Zalyszky, Rozpyv) for simple products via ACF fields.
 * Version:     1.1.0
 * Text Domain: wc-product-tabs
 *
 * @package WC_Product_Tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_PT_VERSION', '1.1.0' );
define( 'WC_PT_PLUGIN_FILE', __FILE__ );
define( 'WC_PT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_PT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WC_PT_PLUGIN_DIR . 'includes/class-wc-pt-settings.php';
require_once WC_PT_PLUGIN_DIR . 'includes/class-wc-pt-data.php';
require_once WC_PT_PLUGIN_DIR . 'includes/class-wc-pt-acf.php';
require_once WC_PT_PLUGIN_DIR . 'includes/class-wc-pt-api.php';

require_once WC_PT_PLUGIN_DIR . 'includes/class-wc-pt-api-update.php';
require_once WC_PT_PLUGIN_DIR . 'includes/class-wc-pt-plugin.php';

WC_PT_ACF::register_hooks();
WC_PT_API::register_hooks();
WC_PT_API_Update::register_hooks();

/**
 * Bootstrap plugin after dependencies are loaded.
 *
 * @return void
 */
function wc_pt_bootstrap_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	WC_PT_Plugin::bootstrap();
}
add_action( 'plugins_loaded', 'wc_pt_bootstrap_plugin' );
