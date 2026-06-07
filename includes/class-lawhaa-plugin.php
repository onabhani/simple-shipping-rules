<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lawhaa_Shipping_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_shipping_init', array( $this, 'shipping_init' ) );
        add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );

        // Keep the request-level config/alias cache fresh after an admin save.
        add_action( 'add_option_lawhaa_shipping_rules_settings', array( 'Lawhaa_Shipping_Rules', 'reset_cache' ) );
        add_action( 'update_option_lawhaa_shipping_rules_settings', array( 'Lawhaa_Shipping_Rules', 'reset_cache' ) );

        new Lawhaa_Settings();
        new Lawhaa_Checkout();

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( LAWHAASHIP_FILE ), array( $this, 'plugin_action_links' ) );
    }

    public function shipping_init() {
        // Class is already loaded by bootstrap; this hook only ensures WooCommerce is ready.
    }

    public function register_shipping_method( $methods ) {
        $methods['lawhaa_rules'] = 'Lawhaa_Shipping_Method';
        return $methods;
    }

    public function enqueue_checkout_assets() {
        if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ( ! function_exists( 'is_cart' ) || ! is_cart() ) ) ) {
            return;
        }

        wp_enqueue_style(
            'lawhaa-shipping-checkout',
            LAWHAASHIP_URL . 'assets/css/checkout.css',
            array(),
            LAWHAASHIP_VERSION
        );

        if ( is_checkout() ) {
            wp_enqueue_script(
                'lawhaa-shipping-checkout',
                LAWHAASHIP_URL . 'assets/js/checkout.js',
                array( 'jquery' ),
                LAWHAASHIP_VERSION,
                true
            );

            $settings = Lawhaa_Shipping_Rules::config();
            wp_localize_script(
                'lawhaa-shipping-checkout',
                'lawhaaShipping',
                array(
                    'debug'         => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( ! empty( $settings['debug_enabled'] ) && 'yes' === $settings['debug_enabled'] ),
                    'debounceDelay' => 400,
                    'i18n'          => array(
                        'updating' => __( 'Updating shipping options...', 'lawhaa-shipping-rules' ),
                    ),
                )
            );
        }
    }

    public function plugin_action_links( $links ) {
        $settings_url = admin_url( 'admin.php?page=lawhaa-shipping-rules' );
        array_unshift(
            $links,
            '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Shipping settings', 'lawhaa-shipping-rules' ) . '</a>'
        );
        return $links;
    }
}
