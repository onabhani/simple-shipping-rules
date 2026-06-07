<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lawhaa_Checkout {
    public function __construct() {
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_payment_gateways' ), 20 );
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'maybe_add_cod_fee' ), 30 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_rule_meta' ), 20, 2 );
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_destination' ) );
        add_filter( 'woocommerce_package_rates', array( $this, 'filter_conflicting_rates' ), 50, 2 );
    }

    public function selected_lawhaa_rate_code() {
        if ( ! WC()->session ) {
            return '';
        }

        $chosen = WC()->session->get( 'chosen_shipping_methods' );
        if ( empty( $chosen ) || ! is_array( $chosen ) ) {
            return '';
        }

        foreach ( $chosen as $method_id ) {
            $code = Lawhaa_Shipping_Rules::parse_rate_code( $method_id );
            if ( $code ) {
                return $code;
            }
        }

        return '';
    }

    public function filter_payment_gateways( $gateways ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $gateways;
        }

        if ( empty( $gateways['cod'] ) ) {
            return $gateways;
        }

        $code = $this->selected_lawhaa_rate_code();
        if ( $code && ! Lawhaa_Shipping_Rules::cod_allowed_for_rate( $code ) ) {
            unset( $gateways['cod'] );
        }

        return $gateways;
    }

    public function maybe_add_cod_fee( $cart ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        if ( ! $cart || ! WC()->session ) {
            return;
        }

        $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
        if ( 'cod' !== $chosen_payment_method ) {
            return;
        }

        $code = $this->selected_lawhaa_rate_code();
        if ( ! in_array( $code, Lawhaa_Shipping_Rules::carrier_codes(), true ) ) {
            return;
        }

        $config = Lawhaa_Shipping_Rules::config();
        $fee    = isset( $config['cod_carrier_fee'] ) ? (float) $config['cod_carrier_fee'] : 15.0;
        if ( $fee <= 0 ) {
            return;
        }

        $cart->add_fee( __( 'Cash on delivery fee', 'lawhaa-shipping-rules' ), $fee, false );
    }

    public function validate_checkout_destination() {
        if ( ! WC()->cart || WC()->cart->needs_shipping() === false ) {
            return;
        }

        $country = isset( $_POST['ship_to_different_address'] ) && ! empty( $_POST['ship_to_different_address'] )
            ? ( isset( $_POST['shipping_country'] ) ? wc_clean( wp_unslash( $_POST['shipping_country'] ) ) : '' )
            : ( isset( $_POST['billing_country'] ) ? wc_clean( wp_unslash( $_POST['billing_country'] ) ) : '' );

        if ( strtoupper( $country ) !== 'SA' ) {
            return;
        }

        $city = isset( $_POST['ship_to_different_address'] ) && ! empty( $_POST['ship_to_different_address'] )
            ? ( isset( $_POST['shipping_city'] ) ? wc_clean( wp_unslash( $_POST['shipping_city'] ) ) : '' )
            : ( isset( $_POST['billing_city'] ) ? wc_clean( wp_unslash( $_POST['billing_city'] ) ) : '' );

        if ( '' === trim( (string) $city ) ) {
            wc_add_notice( __( 'Please enter or validate the National Address so the city can be used to calculate shipping.', 'lawhaa-shipping-rules' ), 'error' );
        }
    }

    public function save_order_rule_meta( $order, $data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $code = $this->selected_lawhaa_rate_code();
        if ( $code ) {
            $order->update_meta_data( '_lawhaa_shipping_rate_code', $code );
            $order->update_meta_data( '_lawhaa_shipping_rate_family', Lawhaa_Shipping_Rules::rate_family( $code ) );
        }

        $city     = $order->get_shipping_city() ?: $order->get_billing_city();
        $district = $order->get_shipping_state() ?: $order->get_billing_state();
        $group    = Lawhaa_Shipping_Rules::city_group( $city, $district );

        $order->update_meta_data( '_lawhaa_destination_city', $city );
        $order->update_meta_data( '_lawhaa_destination_district', $district );
        $order->update_meta_data( '_lawhaa_shipping_group', $group );
        $order->update_meta_data( '_lawhaa_package_weight_kg', Lawhaa_Shipping_Rules::package_weight_kg( array( 'contents' => WC()->cart ? WC()->cart->get_cart() : array() ) ) );
    }

    /**
     * Defensive cleanup: if another shipping method exposes stale Lawhaa rates, heavy sink must win.
     */
    public function filter_conflicting_rates( $rates, $package ) {
        $has_heavy = false;
        foreach ( $rates as $rate_id => $rate ) {
            if ( false !== strpos( (string) $rate_id, 'lawhaa_rules:heavy_sink' ) ) {
                $has_heavy = true;
                break;
            }
        }

        if ( ! $has_heavy ) {
            return $rates;
        }

        foreach ( $rates as $rate_id => $rate ) {
            if ( false === strpos( (string) $rate_id, 'lawhaa_rules:heavy_sink' ) ) {
                unset( $rates[ $rate_id ] );
            }
        }

        return $rates;
    }
}
