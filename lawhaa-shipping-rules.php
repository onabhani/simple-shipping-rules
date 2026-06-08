<?php
/**
 * Plugin Name:       Simple Shipping Rule
 * Plugin URI:        https://hdqah.com/
 * Description:       Smart WooCommerce shipping rules for KSA: local delivery, Mrsool, C4D, carriers, heavy sink shipping, COD restrictions, and KSA National Address compatibility.
 * Version:           0.1.0
 * Author:            hdqah.com
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

register_activation_hook( __FILE__, static function() {
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-shipping-rules.php';
    // The settings array is sizeable and only needed on cart/checkout/admin, so it
    // is not autoloaded. config() memoizes the single get_option() per request.
    $existing = get_option( 'lawhaa_shipping_rules_settings', false );
    if ( false === $existing ) {
        add_option( 'lawhaa_shipping_rules_settings', Lawhaa_Shipping_Rules::default_config(), '', 'no' );
    } elseif ( function_exists( 'wp_set_option_autoload' ) ) {
        // WP 6.4+: flip the autoload column directly (update_option() short-circuits when the value is unchanged).
        wp_set_option_autoload( 'lawhaa_shipping_rules_settings', false );
    } else {
        // Older WP: delete + re-add is the only way to change the autoload flag without a value change.
        delete_option( 'lawhaa_shipping_rules_settings' );
        add_option( 'lawhaa_shipping_rules_settings', $existing, '', 'no' );
    }
} );

add_action( 'before_woocommerce_init', static function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        // Shipping rates come from a WC_Shipping_Method and COD logic uses Store-API-compatible
        // hooks (woocommerce_available_payment_gateways, woocommerce_cart_calculate_fees), so the
        // plugin works with the Cart/Checkout Blocks.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', static function() {
    load_plugin_textdomain( 'lawhaa-shipping-rules', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function() {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Simple Shipping Rule requires WooCommerce to be active.', 'lawhaa-shipping-rules' ) . '</p></div>';
        } );
        return;
    }

    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-shipping-rules.php';
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-settings.php';
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-shipping-method.php';
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-checkout.php';
    require_once LAWHAASHIP_PATH . 'includes/class-lawhaa-plugin.php';

    Lawhaa_Shipping_Plugin::instance();
} );
