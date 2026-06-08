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
        // Block (Store API) checkout equivalent of the classic woocommerce_checkout_process guard.
        add_action( 'woocommerce_store_api_cart_errors', array( $this, 'validate_store_api_destination' ), 10, 2 );
        add_filter( 'woocommerce_package_rates', array( $this, 'filter_conflicting_rates' ), 50, 2 );
        add_filter( 'woocommerce_shipping_calculator_enable_postcode', '__return_false' );
        add_filter( 'woocommerce_default_address_fields', array( $this, 'make_postcode_optional' ) );
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
        if ( ! $code || ! Lawhaa_Shipping_Rules::cod_allowed_for_rate( $code ) ) {
            Lawhaa_Shipping_Rules::log_debug( 'COD fee decision', array( 'rate_code' => $code, 'cod_fee' => 0 ) );
            return;
        }

        $fee = Lawhaa_Shipping_Rules::cod_fee_for_rate( $code );
        if ( $fee <= 0 ) {
            Lawhaa_Shipping_Rules::log_debug( 'COD fee decision', array( 'rate_code' => $code, 'cod_fee' => 0 ) );
            return;
        }

        $cart->add_fee( __( 'Cash on delivery fee', 'lawhaa-shipping-rules' ), $fee, false );
        Lawhaa_Shipping_Rules::log_debug( 'COD fee decision', array( 'rate_code' => $code, 'cod_fee' => $fee ) );
    }

    public function validate_checkout_destination() {
        if ( ! WC()->cart || WC()->cart->needs_shipping() === false ) {
            return;
        }

        $ship_to_different_address = '' !== self::posted_value( 'ship_to_different_address' );
        $country                   = $ship_to_different_address ? self::posted_value( 'shipping_country' ) : self::posted_value( 'billing_country' );

        if ( strtoupper( $country ) !== 'SA' ) {
            return;
        }

        $city = $ship_to_different_address ? self::posted_value( 'shipping_city' ) : self::posted_value( 'billing_city' );

        if ( '' === trim( (string) $city ) ) {
            wc_add_notice( __( 'Please enter or validate the National Address so the city can be used to calculate shipping.', 'lawhaa-shipping-rules' ), 'error' );
        }
    }


    /**
     * Block checkout (Store API) destination guard. Mirrors validate_checkout_destination():
     * a Saudi order must carry a city so shipping can be calculated. Adds to the cart error
     * bag, which the Store API surfaces and which blocks order placement.
     *
     * @param WP_Error $errors
     * @param WC_Cart  $cart
     */
    public function validate_store_api_destination( $errors, $cart ) {
        if ( ! is_wp_error( $errors ) || ! $cart || ! $cart->needs_shipping() || ! WC()->customer ) {
            return;
        }

        $customer = WC()->customer;
        // Validate the shipping destination that rates are actually based on. Read country and
        // city from the SAME address: shipping when a shipping country is set, otherwise billing.
        // Never mix a shipping country with a billing city — a separate Saudi shipping address
        // with an empty city must block even if a billing city is present.
        if ( '' !== (string) $customer->get_shipping_country() ) {
            $country = $customer->get_shipping_country();
            $city    = $customer->get_shipping_city();
        } else {
            $country = $customer->get_billing_country();
            $city    = $customer->get_billing_city();
        }

        if ( 'SA' !== strtoupper( (string) $country ) ) {
            return;
        }

        if ( '' === trim( (string) $city ) ) {
            $errors->add(
                'lawhaa_missing_city',
                __( 'Please enter or validate the National Address so the city can be used to calculate shipping.', 'lawhaa-shipping-rules' )
            );
        }
    }

    private static function posted_value( $key ) {
        // WooCommerce validates the checkout nonce before running woocommerce_checkout_process.
        if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return '';
        }

        $value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( is_array( $value ) ) {
            return '';
        }

        return wc_clean( (string) $value );
    }

    public function make_postcode_optional( $fields ) {
        if ( isset( $fields['postcode'] ) ) {
            $fields['postcode']['required'] = false;
        }

        return $fields;
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

        $city     = wc_clean( (string) ( $order->get_shipping_city() ?: $order->get_billing_city() ) );
        $district = wc_clean( (string) ( $order->get_shipping_state() ?: $order->get_shipping_address_2() ?: $order->get_billing_state() ?: $order->get_billing_address_2() ) );
        $group    = Lawhaa_Shipping_Rules::city_group( $city, $district );

        $order->update_meta_data( '_lawhaa_destination_city', $city );
        $order->update_meta_data( '_lawhaa_destination_district', $district );
        $order->update_meta_data( '_lawhaa_shipping_group', $group );
        $order->update_meta_data( '_lawhaa_package_weight_kg', Lawhaa_Shipping_Rules::package_weight_kg( array( 'contents' => WC()->cart ? WC()->cart->get_cart() : array() ) ) );
    }

    /**
     * Defensive cleanup: if stale Lawhaa rates exist, heavy sink must win within this plugin's rates.
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
            if ( false !== strpos( (string) $rate_id, 'lawhaa_rules:' ) && false === strpos( (string) $rate_id, 'lawhaa_rules:heavy_sink' ) ) {
                unset( $rates[ $rate_id ] );
            }
        }

        return $rates;
    }
}
