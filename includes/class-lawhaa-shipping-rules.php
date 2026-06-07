<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lawhaa_Shipping_Rules {
    const GROUP_LOCAL_15 = 'local_15';
    const GROUP_LOCAL_25 = 'local_25';
    const GROUP_CARRIER  = 'carrier';
    const GROUP_UNKNOWN  = 'unknown';

    /**
     * Default rule values. All values can be changed by the lawhaa_shipping_rule_config filter.
     */
    public static function config() {
        $config = array(
            'currency' => 'SAR',

            'center_free_min'        => 300.0,
            'carrier_free_min'       => 350.0,
            'carrier_free_max_kg'    => 50.0,
            'fast_max_kg'            => 50.0,
            'heavy_threshold_kg'     => 150.0,
            'cod_carrier_fee'        => 15.0,

            'center_local_15_cost'   => 15.0,
            'center_local_25_cost'   => 25.0,

            'mrsool_first_kg'        => 30.0,
            'mrsool_extra_per_kg'    => 2.0,

            'c4d_first_kg'           => 25.0,
            'c4d_extra_per_kg'       => 1.0,

            'carrier_first_10kg'     => 18.0,
            'carrier_extra_step_kg'  => 0.9,
            'carrier_extra_step_fee' => 1.0,

            'heavy_first_10kg'       => 25.0,
            'heavy_extra_per_kg'     => 2.0,

            // Nearby groups where pickup can be offered.
            'pickup_groups'          => array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ),

            // Local groups where fast delivery is offered.
            'fast_groups'            => array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ),
        );

        $config = apply_filters( 'lawhaa_shipping_rule_config', $config );

        return self::sanitize_config( is_array( $config ) ? $config : array() );
    }

    public static function sanitize_config( $config ) {
        $defaults = array(
            'currency'               => 'SAR',
            'center_free_min'        => 300.0,
            'carrier_free_min'       => 350.0,
            'carrier_free_max_kg'    => 50.0,
            'fast_max_kg'            => 50.0,
            'heavy_threshold_kg'     => 150.0,
            'cod_carrier_fee'        => 15.0,
            'center_local_15_cost'   => 15.0,
            'center_local_25_cost'   => 25.0,
            'mrsool_first_kg'        => 30.0,
            'mrsool_extra_per_kg'    => 2.0,
            'c4d_first_kg'           => 25.0,
            'c4d_extra_per_kg'       => 1.0,
            'carrier_first_10kg'     => 18.0,
            'carrier_extra_step_kg'  => 0.9,
            'carrier_extra_step_fee' => 1.0,
            'heavy_first_10kg'       => 25.0,
            'heavy_extra_per_kg'     => 2.0,
            'pickup_groups'          => array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ),
            'fast_groups'            => array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ),
        );

        $config = array_merge( $defaults, $config );

        foreach ( $defaults as $key => $default ) {
            if ( is_array( $default ) ) {
                $groups = array();
                foreach ( (array) $config[ $key ] as $group ) {
                    if ( is_scalar( $group ) ) {
                        $groups[] = (string) $group;
                    }
                }
                $config[ $key ] = array_values( array_intersect( $groups, array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ) ) );
                continue;
            }

            if ( 'currency' === $key ) {
                $config[ $key ] = sanitize_text_field( (string) $config[ $key ] );
                continue;
            }

            $config[ $key ] = max( 0.0, (float) $config[ $key ] );
        }

        if ( $config['carrier_extra_step_kg'] <= 0.0 ) {
            $config['carrier_extra_step_kg'] = $defaults['carrier_extra_step_kg'];
        }

        return $config;
    }

    public static function package_context( $package ) {
        $city     = self::get_destination_value( $package, 'city' );
        $district = self::get_destination_value( $package, 'state' );
        $country  = self::get_destination_value( $package, 'country' );
        $postcode = self::get_destination_value( $package, 'postcode' );

        // OTO can map cityName to city and districtName to state. Some checkouts use billing only.
        if ( empty( $city ) && WC()->customer ) {
            $city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
        }
        if ( empty( $district ) && WC()->customer ) {
            $district = WC()->customer->get_shipping_state() ?: WC()->customer->get_billing_state();
        }
        if ( empty( $country ) && WC()->customer ) {
            $country = WC()->customer->get_shipping_country() ?: WC()->customer->get_billing_country();
        }
        if ( empty( $postcode ) && WC()->customer ) {
            $postcode = WC()->customer->get_shipping_postcode() ?: WC()->customer->get_billing_postcode();
        }

        $weight_kg = self::package_weight_kg( $package );
        $amount    = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : self::cart_amount();
        $group     = self::city_group( $city, $district );

        $context = array(
            'country'   => strtoupper( (string) $country ),
            'city'      => (string) $city,
            'district'  => (string) $district,
            'postcode'  => (string) $postcode,
            'city_key'  => self::normalize_location( $city ),
            'district_key' => self::normalize_location( $district ),
            'group'     => $group,
            'weight_kg' => $weight_kg,
            'amount'    => $amount,
        );

        return apply_filters( 'lawhaa_shipping_package_context', $context, $package );
    }

    public static function get_destination_value( $package, $key ) {
        $allowed_keys = array( 'city', 'state', 'country', 'postcode' );
        if ( ! in_array( $key, $allowed_keys, true ) || ! isset( $package['destination'][ $key ] ) ) {
            return '';
        }

        $value = $package['destination'][ $key ];
        if ( is_array( $value ) ) {
            return '';
        }

        return wc_clean( wp_unslash( (string) $value ) );
    }

    public static function cart_amount() {
        if ( WC()->cart ) {
            return max( 0, (float) WC()->cart->get_subtotal() - (float) WC()->cart->get_discount_total() );
        }
        return 0.0;
    }

    public static function package_weight_kg( $package ) {
        $weight = 0.0;

        if ( isset( $package['contents'] ) && is_array( $package['contents'] ) ) {
            foreach ( $package['contents'] as $item ) {
                if ( empty( $item['data'] ) || ! is_a( $item['data'], 'WC_Product' ) ) {
                    continue;
                }
                $product = $item['data'];
                $qty     = isset( $item['quantity'] ) ? (float) $item['quantity'] : 1.0;
                $item_weight = (float) $product->get_weight();
                $weight += $item_weight * $qty;
            }
        } elseif ( WC()->cart ) {
            $weight = (float) WC()->cart->get_cart_contents_weight();
        }

        $unit = get_option( 'woocommerce_weight_unit', 'kg' );
        $kg   = (float) wc_get_weight( $weight, 'kg', $unit );

        return (float) apply_filters( 'lawhaa_shipping_weight_kg', max( 0, $kg ), $package );
    }

    public static function normalize_location( $value ) {
        static $cache = array();

        $raw_value = (string) $value;
        if ( isset( $cache[ $raw_value ] ) ) {
            return $cache[ $raw_value ];
        }

        $value = trim( $raw_value );
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

        $replace = array(
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            'ـ' => '',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        );
        $value = strtr( $value, $replace );

        // Remove Arabic diacritics.
        $value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $value );

        // Remove common definite articles/prefixes in English and Arabic.
        $value = preg_replace( '/\bal[\s\-]+/u', '', $value );
        $value = preg_replace( '/^ال/u', '', $value );

        // Keep only letters/numbers, then collapse.
        $value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value );

        $normalized = $value ?: '';

        if ( count( $cache ) > 500 ) {
            array_shift( $cache );
        }
        $cache[ $raw_value ] = $normalized;

        return $normalized;
    }

    public static function aliases() {
        static $normalized = null;

        if ( null === $normalized ) {
            $aliases = array(
                self::GROUP_LOCAL_15 => array(
                    'dammam', 'aldammam', 'adammam', 'dammamcity', 'الدمام', 'دمام',
                    'saihat', 'sayhat', 'sihat', 'سيهات',
                ),
                self::GROUP_LOCAL_25 => array(
                    'qatif', 'qateef', 'alqatif', 'القطيف', 'قطيف',
                    'khobar', 'alkhobar', 'alKhobar', 'الخبر', 'خبر',
                    'tarout', 'tarut', 'taroot', 'تاروت',
                    'aziziyah', 'azizia', 'aziziah', 'alaziziyah', 'العزيزية', 'عزيزيه', 'العزيزيه', 'عزيزية',
                ),
            );

            $normalized = array();
            foreach ( $aliases as $group => $items ) {
                $normalized[ $group ] = self::normalize_alias_list( $items );
            }
        }

        $filtered = apply_filters( 'lawhaa_shipping_city_aliases', $normalized );
        if ( ! is_array( $filtered ) ) {
            return $normalized;
        }

        foreach ( array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ) as $group ) {
            $items = isset( $filtered[ $group ] ) ? (array) $filtered[ $group ] : array();
            $filtered[ $group ] = self::normalize_alias_list( $items );
        }

        return $filtered;
    }

    private static function normalize_alias_list( $items ) {
        $aliases = array();
        foreach ( (array) $items as $item ) {
            if ( is_scalar( $item ) ) {
                $aliases[] = self::normalize_location( (string) $item );
            }
        }

        return array_values( array_unique( array_filter( $aliases ) ) );
    }

    public static function city_group( $city, $district = '' ) {
        $city_key     = self::normalize_location( $city );
        $district_key = self::normalize_location( $district );
        $aliases      = self::aliases();

        foreach ( $aliases as $group => $keys ) {
            if ( ! is_array( $keys ) ) {
                continue;
            }

            if ( in_array( $city_key, $keys, true ) || in_array( $district_key, $keys, true ) ) {
                return apply_filters( 'lawhaa_shipping_city_group', $group, $city, $district, $city_key, $district_key );
            }
        }

        return apply_filters( 'lawhaa_shipping_city_group', self::GROUP_CARRIER, $city, $district, $city_key, $district_key );
    }

    public static function is_local_group( $group ) {
        return in_array( $group, array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ), true );
    }

    public static function build_rates( $context ) {
        $config = self::config();
        $rates  = array();

        $country   = isset( $context['country'] ) ? strtoupper( $context['country'] ) : '';
        $group     = isset( $context['group'] ) ? $context['group'] : self::GROUP_CARRIER;
        $weight_kg = isset( $context['weight_kg'] ) ? (float) $context['weight_kg'] : 0.0;
        $amount    = isset( $context['amount'] ) ? (float) $context['amount'] : 0.0;

        if ( $country && 'SA' !== $country ) {
            return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
        }

        if ( $weight_kg > (float) $config['heavy_threshold_kg'] ) {
            $rates[] = array(
                'code'        => 'heavy_sink',
                'label'       => __( 'Heavy sink shipping (1-5 business days)', 'lawhaa-shipping-rules' ),
                'cost'        => self::heavy_cost( $weight_kg, $config ),
                'family'      => 'heavy',
                'cod_allowed' => false,
                'meta'        => array(
                    __( 'Delivery time', 'lawhaa-shipping-rules' ) => __( '1-5 business days', 'lawhaa-shipping-rules' ),
                    __( 'Rule', 'lawhaa-shipping-rules' ) => __( 'Orders above 150 kg', 'lawhaa-shipping-rules' ),
                ),
            );
            return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
        }

        if ( self::is_local_group( $group ) ) {
            $center_cost = ( self::GROUP_LOCAL_15 === $group ) ? (float) $config['center_local_15_cost'] : (float) $config['center_local_25_cost'];
            $center_free = $amount >= (float) $config['center_free_min'];

            $rates[] = array(
                'code'        => 'center_delivery',
                'label'       => $center_free ? __( 'Center delivery - free (24-48 hours)', 'lawhaa-shipping-rules' ) : __( 'Center delivery (24-48 hours)', 'lawhaa-shipping-rules' ),
                'cost'        => $center_free ? 0.0 : $center_cost,
                'family'      => 'center',
                'cod_allowed' => true,
                'meta'        => array(
                    __( 'Delivery time', 'lawhaa-shipping-rules' ) => __( '24-48 hours', 'lawhaa-shipping-rules' ),
                    __( 'COD fee', 'lawhaa-shipping-rules' ) => __( 'No COD fee', 'lawhaa-shipping-rules' ),
                ),
            );

            if ( in_array( $group, (array) $config['fast_groups'], true ) && $weight_kg <= (float) $config['fast_max_kg'] ) {
                $rates[] = array(
                    'code'        => 'mrsool',
                    'label'       => __( 'Fast shipping - Mrsool (2-4 hours)', 'lawhaa-shipping-rules' ),
                    'cost'        => self::mrsool_cost( $weight_kg, $config ),
                    'family'      => 'fast',
                    'cod_allowed' => false,
                    'meta'        => array(
                        __( 'Delivery time', 'lawhaa-shipping-rules' ) => __( '2-4 hours', 'lawhaa-shipping-rules' ),
                        __( 'Maximum weight', 'lawhaa-shipping-rules' ) => __( '50 kg', 'lawhaa-shipping-rules' ),
                    ),
                );

                $rates[] = array(
                    'code'        => 'c4d',
                    'label'       => __( 'Fast shipping - C4D (2-4 hours)', 'lawhaa-shipping-rules' ),
                    'cost'        => self::c4d_cost( $weight_kg, $config ),
                    'family'      => 'fast',
                    'cod_allowed' => false,
                    'meta'        => array(
                        __( 'Delivery time', 'lawhaa-shipping-rules' ) => __( '2-4 hours', 'lawhaa-shipping-rules' ),
                        __( 'Maximum weight', 'lawhaa-shipping-rules' ) => __( '50 kg', 'lawhaa-shipping-rules' ),
                    ),
                );
            }

            if ( in_array( $group, (array) $config['pickup_groups'], true ) ) {
                $rates[] = array(
                    'code'        => 'local_pickup',
                    'label'       => __( 'Pickup from center - free', 'lawhaa-shipping-rules' ),
                    'cost'        => 0.0,
                    'family'      => 'pickup',
                    'cod_allowed' => true,
                    'meta'        => array(
                        __( 'Pickup', 'lawhaa-shipping-rules' ) => __( 'Available for nearby areas', 'lawhaa-shipping-rules' ),
                    ),
                );
            }

            return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
        }

        $carrier_free = ( $amount >= (float) $config['carrier_free_min'] && $weight_kg <= (float) $config['carrier_free_max_kg'] );

        $rates[] = array(
            'code'        => $carrier_free ? 'carrier_free' : 'carrier',
            'label'       => $carrier_free ? __( 'Carrier shipping - free (1-5 business days)', 'lawhaa-shipping-rules' ) : __( 'Carrier shipping (1-5 business days)', 'lawhaa-shipping-rules' ),
            'cost'        => $carrier_free ? 0.0 : self::carrier_cost( $weight_kg, $config ),
            'family'      => 'carrier',
            'cod_allowed' => true,
            'meta'        => array(
                __( 'Delivery time', 'lawhaa-shipping-rules' ) => __( '1-5 business days', 'lawhaa-shipping-rules' ),
                __( 'COD fee', 'lawhaa-shipping-rules' ) => __( '15 SAR if COD is selected', 'lawhaa-shipping-rules' ),
            ),
        );

        return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
    }

    public static function mrsool_cost( $weight_kg, $config = null ) {
        $config = $config ?: self::config();
        $billable = max( 1, (int) ceil( max( 0, (float) $weight_kg ) ) );
        return (float) $config['mrsool_first_kg'] + max( 0, $billable - 1 ) * (float) $config['mrsool_extra_per_kg'];
    }

    public static function c4d_cost( $weight_kg, $config = null ) {
        $config = $config ?: self::config();
        $billable = max( 1, (int) ceil( max( 0, (float) $weight_kg ) ) );
        return (float) $config['c4d_first_kg'] + max( 0, $billable - 1 ) * (float) $config['c4d_extra_per_kg'];
    }

    public static function carrier_cost( $weight_kg, $config = null ) {
        $config = $config ?: self::config();
        $weight_kg = max( 0, (float) $weight_kg );
        if ( $weight_kg <= 10.0 ) {
            return (float) $config['carrier_first_10kg'];
        }
        $extra_steps = (int) ceil( ( $weight_kg - 10.0 ) / (float) $config['carrier_extra_step_kg'] );
        return (float) $config['carrier_first_10kg'] + $extra_steps * (float) $config['carrier_extra_step_fee'];
    }

    public static function heavy_cost( $weight_kg, $config = null ) {
        $config = $config ?: self::config();
        $weight_kg = max( 0, (float) $weight_kg );
        if ( $weight_kg <= 10.0 ) {
            return (float) $config['heavy_first_10kg'];
        }
        return (float) $config['heavy_first_10kg'] + (int) ceil( $weight_kg - 10.0 ) * (float) $config['heavy_extra_per_kg'];
    }

    public static function parse_rate_code( $method_id ) {
        $method_id = (string) $method_id;
        if ( false === strpos( $method_id, 'lawhaa_rules:' ) ) {
            return '';
        }
        $parts = explode( ':', $method_id );
        return isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
    }

    public static function rate_family( $code ) {
        $map = array(
            'center_delivery' => 'center',
            'mrsool'          => 'fast',
            'c4d'             => 'fast',
            'local_pickup'    => 'pickup',
            'carrier'         => 'carrier',
            'carrier_free'    => 'carrier',
            'heavy_sink'      => 'heavy',
        );
        return isset( $map[ $code ] ) ? $map[ $code ] : '';
    }

    public static function cod_allowed_for_rate( $code ) {
        return in_array( $code, array( 'center_delivery', 'local_pickup', 'carrier', 'carrier_free' ), true );
    }

    public static function carrier_codes() {
        return array( 'carrier', 'carrier_free' );
    }
}
