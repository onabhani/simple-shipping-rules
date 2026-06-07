<?php
/**
 * Plugin Name:       Lawhaa Shipping Rules
 * Plugin URI:        https://lawhaa.com/
 * Description:       Smart WooCommerce shipping rules for Lawhaa: local delivery, Mrsool, C4D, carriers, heavy sink shipping, COD restrictions, and KSA National Address compatibility.
 * Version:           0.1.0
 * Author:            Lawhaa / HDQAH
 * Author URI:        https://hdqah.com/
 * Text Domain:       lawhaa-shipping-rules
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:   9.8
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LAWHAASHIP_VERSION', '0.1.0' );
define( 'LAWHAASHIP_FILE', __FILE__ );
define( 'LAWHAASHIP_PATH', plugin_dir_path( __FILE__ ) );
define( 'LAWHAASHIP_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', static function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', static function() {
    load_plugin_textdomain( 'lawhaa-shipping-rules', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function() {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Lawhaa Shipping Rules requires WooCommerce to be active.', 'lawhaa-shipping-rules' ) . '</p></div>';
        } );
        return;
    }

    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-shipping-rules.php';
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-shipping-method.php';
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-checkout.php';
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-plugin.php';

    Lawhaa_Shipping_Plugin::instance();
} );
