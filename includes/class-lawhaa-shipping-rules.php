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
     * Request-level memoization. config() and aliases() are called many times per
     * shipping calculation; resolving them once keeps the rate hot path cheap and
     * guarantees a single config snapshot per request. Reset via reset_cache() when
     * the settings option changes (see reset hooks in Lawhaa_Shipping_Plugin).
     */
    private static $config_cache  = null;
    private static $aliases_cache = null;

    /**
     * Clear the request-level config/alias caches. Hooked to add/update of the
     * settings option so an admin save is reflected within the same request.
     */
    public static function reset_cache() {
        self::$config_cache  = null;
        self::$aliases_cache = null;
    }

    /**
     * Default rule values. All values can be changed by the lawhaa_shipping_rule_config filter.
     */
    public static function config() {
        if ( null !== self::$config_cache ) {
            return self::$config_cache;
        }

        $saved  = get_option( 'lawhaa_shipping_rules_settings', array() );
        $config = self::sanitize_config( is_array( $saved ) ? $saved : array() );
        $config = apply_filters( 'lawhaa_shipping_rule_config', $config );

        self::$config_cache = self::sanitize_config( $config );

        return self::$config_cache;
    }

    public static function sanitize_config( $config ) {
        $defaults = self::default_config();
        $config   = is_array( $config ) ? array_intersect_key( $config, $defaults ) : array();
        $config   = array_merge( $defaults, $config );

        foreach ( $defaults as $key => $default ) {
            if ( 'oto_excluded_families' === $key ) {
                $families = array();
                foreach ( (array) $config[ $key ] as $family ) {
                    $family = is_scalar( $family ) ? sanitize_key( (string) $family ) : '';
                    if ( in_array( $family, self::rate_families(), true ) ) {
                        $families[] = $family;
                    }
                }
                $config[ $key ] = array_values( array_unique( $families ) );
                continue;
            }

            if ( is_array( $default ) ) {
                $groups = array();
                foreach ( (array) $config[ $key ] as $group ) {
                    $group = self::sanitize_group( $group, '' );
                    if ( self::is_local_group( $group ) ) {
                        $groups[] = $group;
                    }
                }
                $config[ $key ] = array_values( array_unique( $groups ) );
                continue;
            }

            if ( self::is_checkbox_key( $key ) ) {
                $config[ $key ] = self::sanitize_bool( $config[ $key ] ) ? 'yes' : 'no';
                continue;
            }

            if ( self::is_alias_key( $key ) ) {
                $config[ $key ] = self::sanitize_alias_textarea( $config[ $key ] );
                continue;
            }

            if ( is_string( $default ) && ! self::is_numeric_config_key( $key ) ) {
                $config[ $key ] = is_scalar( $config[ $key ] ) ? sanitize_text_field( (string) $config[ $key ] ) : $default;
                continue;
            }

            $config[ $key ] = self::sanitize_non_negative_float( $config[ $key ], (float) $default );
        }

        if ( $config['carrier_extra_step_kg'] <= 0.0 ) {
            $config['carrier_extra_step_kg'] = $defaults['carrier_extra_step_kg'];
        }
        if ( $config['carrier_first_block_kg'] <= 0.0 ) {
            $config['carrier_first_block_kg'] = $defaults['carrier_first_block_kg'];
        }
        if ( $config['heavy_first_block_kg'] <= 0.0 ) {
            $config['heavy_first_block_kg'] = $defaults['heavy_first_block_kg'];
        }
        if ( $config['distance_road_factor'] <= 0.0 ) {
            $config['distance_road_factor'] = $defaults['distance_road_factor'];
        }
        $config['store_short_code'] = self::sanitize_short_code( $config['store_short_code'] );

        return $config;
    }

    public static function default_config() {
        return array(
            'currency'                  => 'SAR',
            'debug_enabled'             => 'no',

            'center_enabled'            => 'yes',
            'center_free_min'           => 300.0,
            'center_local_15_cost'      => 15.0,
            'center_local_25_cost'      => 25.0,
            'center_label_en'           => 'Center delivery',
            'center_label_ar'           => 'توصيل المركز',
            'center_delivery_estimate'  => '24-48 hours',
            'center_delivery_estimate_ar' => 'خلال 24-48 ساعة',
            'center_cod_allowed'        => 'yes',
            'center_cod_fee'            => 0.0,
            'local_15_aliases'          => "Dammam\nالدمام\nدمام\nSaihat\nSayhat\nSihat\nسيهات",
            'local_25_aliases'          => "Qatif\nQateef\nالقطيف\nقطيف\nKhobar\nAl Khobar\nالخبر\nخبر\nTarout\nTarut\nTaroot\nتاروت\nAziziyah\nAzizia\nAziziah\nالعزيزية\nالعزيزيه\nعزيزية",

            'mrsool_enabled'            => 'yes',
            'mrsool_max_km'             => 50.0,
            'mrsool_base_km'            => 10.0,
            'mrsool_base_cost'          => 30.0,
            'mrsool_per_km'             => 2.0,
            'mrsool_delivery_estimate'  => '2-4 hours',
            'mrsool_delivery_estimate_ar' => 'خلال 2-4 ساعات',
            'mrsool_cod_allowed'        => 'no',
            'mrsool_aliases'            => "Dammam\nالدمام\nدمام\nSaihat\nSayhat\nSihat\nسيهات\nQatif\nQateef\nالقطيف\nقطيف\nKhobar\nAl Khobar\nالخبر\nخبر\nTarout\nTarut\nTaroot\nتاروت\nAziziyah\nAzizia\nAziziah\nالعزيزية\nالعزيزيه\nعزيزية\nAl Iskan Dist\nحي الإسكان\nالاسكان\nالإسكان",

            'c4d_enabled'               => 'yes',
            'c4d_max_km'                => 50.0,
            'c4d_base_km'               => 10.0,
            'c4d_base_cost'             => 25.0,
            'c4d_per_km'                => 2.0,
            'c4d_delivery_estimate'     => '2-4 hours',
            'c4d_delivery_estimate_ar'  => 'خلال 2-4 ساعات',
            'c4d_cod_allowed'           => 'no',
            'c4d_aliases'               => "Dammam\nالدمام\nدمام\nSaihat\nSayhat\nSihat\nسيهات\nQatif\nQateef\nالقطيف\nقطيف\nKhobar\nAl Khobar\nالخبر\nخبر\nTarout\nTarut\nTaroot\nتاروت\nAziziyah\nAzizia\nAziziah\nالعزيزية\nالعزيزيه\nعزيزية\nAl Iskan Dist\nحي الإسكان\nالاسكان\nالإسكان",

            'pickup_enabled'            => 'yes',
            'pickup_cost'               => 0.0,
            'pickup_cod_allowed'        => 'yes',
            'pickup_cod_fee'            => 0.0,
            'pickup_label_en'           => 'Pickup from center',
            'pickup_label_ar'           => 'استلام من المركز',
            'pickup_aliases'            => "Dammam\nالدمام\nدمام\nSaihat\nSayhat\nSihat\nسيهات\nQatif\nQateef\nالقطيف\nقطيف\nKhobar\nAl Khobar\nالخبر\nخبر\nTarout\nTarut\nTaroot\nتاروت\nAziziyah\nAzizia\nAziziah\nالعزيزية\nالعزيزيه\nعزيزية",

            'carrier_enabled'           => 'yes',
            'carrier_first_block_kg'    => 10.0,
            'carrier_first_10kg'        => 18.0,
            'carrier_extra_step_kg'     => 0.9,
            'carrier_extra_step_fee'    => 1.0,
            'carrier_free_min'          => 350.0,
            'carrier_free_max_kg'       => 50.0,
            'carrier_cod_allowed'       => 'yes',
            'cod_carrier_fee'           => 15.0,
            'carrier_delivery_estimate' => '1-5 business days',
            'carrier_delivery_estimate_ar' => 'خلال 1-5 أيام عمل',

            'heavy_enabled'             => 'yes',
            'heavy_threshold_kg'        => 150.0,
            'heavy_first_block_kg'      => 10.0,
            'heavy_first_10kg'          => 25.0,
            'heavy_extra_per_kg'        => 2.0,
            'heavy_cod_allowed'         => 'no',
            'heavy_delivery_estimate'   => '1-5 business days',
            'heavy_delivery_estimate_ar' => 'خلال 1-5 أيام عمل',

            // Distance pricing (Mrsool, C4D): straight-line distance from the store to the
            // customer's national address, times the road factor. The store point is looked up
            // from store_short_code on save. Without customer coordinates, the city point is used.
            'store_short_code'          => '',
            'store_lat'                 => 0.0,
            'store_lng'                 => 0.0,
            'distance_road_factor'      => 1.3,
            'distance_city_points'      => "Dammam, الدمام, دمام | 26.4207, 50.0888\nSaihat, Sayhat, Sihat, سيهات | 26.4834, 50.0412\nQatif, Qateef, Al Qatif, القطيف, قطيف | 26.5590, 50.0060\nKhobar, Al Khobar, Al Khubar, الخبر, خبر | 26.2794, 50.2083\nTarout, Tarut, تاروت | 26.5750, 50.0640\nDhahran, الظهران | 26.2886, 50.1140\nSafwa, صفوى | 26.6497, 49.9553\nAwamiyah, العوامية | 26.5880, 49.9870\nAziziyah, Al Aziziyah, العزيزية | 26.1900, 50.1900\nRas Tanura, رأس تنورة | 26.6436, 50.1588",

            // Coarse region gate for pickup/fast delivery. Filter-only (see docs/FILTER-EXAMPLES.php);
            // per-method alias lists provide the fine-grained per-carrier coverage.
            'pickup_groups'             => array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ),
            'fast_groups'               => array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ),

            // Orders whose rate family is listed here are not sent to webhooks whose
            // delivery URL contains oto_webhook_match (OTO registers its own WC webhooks).
            // Mrsool has its own family and is sent; C4D ("fast") is not.
            'oto_excluded_families'     => array( 'center', 'fast', 'pickup' ),
            'oto_webhook_match'         => 'oto-webhook-listener',
        );
    }

    private static function is_checkbox_key( $key ) {
        return in_array(
            $key,
            array(
                'debug_enabled',
                'center_enabled',
                'center_cod_allowed',
                'mrsool_enabled',
                'mrsool_cod_allowed',
                'c4d_enabled',
                'c4d_cod_allowed',
                'pickup_enabled',
                'pickup_cod_allowed',
                'carrier_enabled',
                'carrier_cod_allowed',
                'heavy_enabled',
                'heavy_cod_allowed',
            ),
            true
        );
    }

    private static function is_alias_key( $key ) {
        return in_array( $key, array( 'local_15_aliases', 'local_25_aliases', 'mrsool_aliases', 'c4d_aliases', 'pickup_aliases', 'distance_city_points' ), true );
    }

    private static function is_numeric_config_key( $key ) {
        return in_array(
            $key,
            array(
                'center_free_min',
                'center_local_15_cost',
                'center_local_25_cost',
                'center_cod_fee',
                'mrsool_max_km',
                'mrsool_base_km',
                'mrsool_base_cost',
                'mrsool_per_km',
                'c4d_max_km',
                'c4d_base_km',
                'c4d_base_cost',
                'c4d_per_km',
                'store_lat',
                'store_lng',
                'distance_road_factor',
                'pickup_cost',
                'pickup_cod_fee',
                'carrier_first_block_kg',
                'carrier_first_10kg',
                'carrier_extra_step_kg',
                'carrier_extra_step_fee',
                'carrier_free_min',
                'carrier_free_max_kg',
                'cod_carrier_fee',
                'heavy_threshold_kg',
                'heavy_first_block_kg',
                'heavy_first_10kg',
                'heavy_extra_per_kg',
            ),
            true
        );
    }

    private static function sanitize_bool( $value ) {
        return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value || 'true' === $value;
    }

    private static function sanitize_alias_textarea( $value ) {
        if ( is_array( $value ) ) {
            $value = implode( "\n", array_filter( array_map( 'strval', $value ) ) );
        }
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $lines = preg_split( '/\r\n|\r|\n/', (string) $value );
        $clean = array();
        foreach ( $lines as $line ) {
            $line = sanitize_text_field( $line );
            if ( '' !== $line ) {
                $clean[] = $line;
            }
        }

        return implode( "\n", array_unique( $clean ) );
    }

    private static function alias_lines( $value ) {
        $lines = preg_split( '/\r\n|\r|\n/', (string) $value );
        return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
    }

    private static function sanitize_non_negative_float( $value, $default = 0.0 ) {
        if ( ! is_scalar( $value ) || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
            $value = $default;
        }

        return max( 0.0, (float) $value );
    }

    private static function sanitize_group( $group, $fallback = self::GROUP_CARRIER ) {
        if ( ! is_scalar( $group ) ) {
            return $fallback;
        }

        $group = sanitize_key( (string) $group );
        if ( in_array( $group, array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25, self::GROUP_CARRIER, self::GROUP_UNKNOWN, 'mrsool', 'c4d', 'pickup' ), true ) ) {
            return $group;
        }

        return $fallback;
    }

    public static function package_context( $package ) {
        $city     = self::get_destination_value( $package, 'city' );
        $district = self::get_destination_value( $package, 'state' );
        if ( empty( $district ) ) {
            $district = self::get_destination_value( $package, 'address_2' );
        }
        $country  = self::get_destination_value( $package, 'country' );
        $postcode = self::get_destination_value( $package, 'postcode' );

        // OTO can map cityName to city and districtName to state. Some checkouts use billing only.
        if ( empty( $city ) && WC()->customer ) {
            $city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
        }
        if ( empty( $district ) && WC()->customer ) {
            $district = WC()->customer->get_shipping_state() ?: WC()->customer->get_shipping_address_2() ?: WC()->customer->get_billing_state() ?: WC()->customer->get_billing_address_2();
        }
        if ( empty( $country ) && WC()->customer ) {
            $country = WC()->customer->get_shipping_country() ?: WC()->customer->get_billing_country();
        }
        if ( empty( $postcode ) && WC()->customer ) {
            $postcode = WC()->customer->get_shipping_postcode() ?: WC()->customer->get_billing_postcode();
        }

        $weight_kg = self::package_weight_kg( $package );
        $amount    = isset( $package['contents_cost'] ) ? self::sanitize_non_negative_float( $package['contents_cost'], 0.0 ) : self::cart_amount();
        $group     = self::city_group( $city, $district );
        $distance  = array( 'km' => null, 'source' => '' );
        if ( self::is_local_group( $group ) ) {
            $distance = self::destination_distance( isset( $package['lawhaa_short_code'] ) ? $package['lawhaa_short_code'] : '', $city );
        }

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
            'distance_km'     => $distance['km'],
            'distance_source' => $distance['source'],
        );

        return apply_filters( 'lawhaa_shipping_package_context', $context, $package );
    }

    public static function get_destination_value( $package, $key ) {
        $allowed_keys = array( 'city', 'state', 'country', 'postcode', 'address_2' );
        if ( ! in_array( $key, $allowed_keys, true ) || ! is_array( $package ) || empty( $package['destination'] ) || ! is_array( $package['destination'] ) || ! isset( $package['destination'][ $key ] ) ) {
            return '';
        }

        $value = $package['destination'][ $key ];
        if ( ! is_scalar( $value ) ) {
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
                $product     = $item['data'];
                $qty         = isset( $item['quantity'] ) ? self::sanitize_non_negative_float( $item['quantity'], 1.0 ) : 1.0;
                $item_weight = self::sanitize_non_negative_float( $product->get_weight(), 0.0 );
                $weight     += $item_weight * $qty;
            }
        } elseif ( WC()->cart ) {
            $weight = (float) WC()->cart->get_cart_contents_weight();
        }

        $unit = get_option( 'woocommerce_weight_unit', 'kg' );
        $kg   = self::sanitize_non_negative_float( wc_get_weight( $weight, 'kg', $unit ), 0.0 );

        return self::sanitize_non_negative_float( apply_filters( 'lawhaa_shipping_weight_kg', $kg, $package ), $kg );
    }

    public static function normalize_location( $value ) {
        static $cache = array();

        if ( ! is_scalar( $value ) ) {
            return '';
        }

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
            unset( $cache[ array_key_first( $cache ) ] );
        }
        $cache[ $raw_value ] = $normalized;

        return $normalized;
    }

    public static function aliases() {
        if ( null !== self::$aliases_cache ) {
            return self::$aliases_cache;
        }

        $config  = self::config();
        $aliases = array(
            self::GROUP_LOCAL_15 => self::alias_lines( $config['local_15_aliases'] ),
            self::GROUP_LOCAL_25 => self::alias_lines( $config['local_25_aliases'] ),
            'mrsool'             => self::alias_lines( $config['mrsool_aliases'] ),
            'c4d'                => self::alias_lines( $config['c4d_aliases'] ),
            'pickup'             => self::alias_lines( $config['pickup_aliases'] ),
        );

        $normalized = array();
        foreach ( $aliases as $group => $items ) {
            $normalized[ $group ] = self::normalize_alias_list( $items );
        }

        $filtered = apply_filters( 'lawhaa_shipping_city_aliases', $normalized );
        if ( ! is_array( $filtered ) ) {
            self::$aliases_cache = $normalized;
            return self::$aliases_cache;
        }

        foreach ( array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25, 'mrsool', 'c4d', 'pickup' ) as $group ) {
            $items = isset( $filtered[ $group ] ) ? (array) $filtered[ $group ] : array();
            $filtered[ $group ] = self::normalize_alias_list( $items );
        }

        self::$aliases_cache = $filtered;

        return self::$aliases_cache;
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

    public static function matches_alias_group( $group, $city, $district = '' ) {
        $aliases = self::aliases();
        $group   = self::sanitize_group( $group, '' );
        if ( empty( $group ) || empty( $aliases[ $group ] ) || ! is_array( $aliases[ $group ] ) ) {
            return false;
        }

        $city_key     = self::normalize_location( $city );
        $district_key = self::normalize_location( $district );

        return in_array( $city_key, $aliases[ $group ], true ) || in_array( $district_key, $aliases[ $group ], true );
    }

    private static function is_arabic_locale() {
        // Arabic-only: selects the _ar label/estimate companions, so other RTL locales
        // (Hebrew, Persian, Urdu) must NOT match. Do not reintroduce an is_rtl() branch.
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        return 0 === strpos( (string) $locale, 'ar' );
    }

    private static function setting_label( $config, $base_key, $fallback ) {
        $key = $base_key . ( self::is_arabic_locale() ? '_ar' : '_en' );

        return ! empty( $config[ $key ] ) ? $config[ $key ] : $fallback;
    }

    /**
     * Delivery estimate localized to the storefront language. The base key holds the
     * default/English value; an optional "<base>_ar" companion overrides it for Arabic.
     */
    private static function localized_estimate( $config, $base_key ) {
        $ar_key = $base_key . '_ar';
        if ( self::is_arabic_locale() && ! empty( $config[ $ar_key ] ) ) {
            return $config[ $ar_key ];
        }

        return isset( $config[ $base_key ] ) ? $config[ $base_key ] : '';
    }

    private static function label_with_estimate( $label, $estimate, $free = false ) {
        if ( $free ) {
            $label = sprintf( __( '%s - free', 'lawhaa-shipping-rules' ), $label );
        }

        /* translators: 1: shipping label, 2: delivery time estimate. */
        return $estimate ? sprintf( __( '%1$s (%2$s)', 'lawhaa-shipping-rules' ), $label, $estimate ) : $label;
    }

    public static function city_group( $city, $district = '' ) {
        $city_key     = self::normalize_location( $city );
        $district_key = self::normalize_location( $district );
        $aliases      = self::aliases();

        foreach ( array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ) as $group ) {
            $keys = isset( $aliases[ $group ] ) ? $aliases[ $group ] : array();
            if ( ! is_array( $keys ) ) {
                continue;
            }

            if ( in_array( $city_key, $keys, true ) || in_array( $district_key, $keys, true ) ) {
                $filtered_group = apply_filters( 'lawhaa_shipping_city_group', $group, $city, $district, $city_key, $district_key );
                return self::sanitize_group( $filtered_group, $group );
            }
        }

        $filtered_group = apply_filters( 'lawhaa_shipping_city_group', self::GROUP_CARRIER, $city, $district, $city_key, $district_key );
        return self::sanitize_group( $filtered_group, self::GROUP_CARRIER );
    }

    public static function is_local_group( $group ) {
        return in_array( $group, array( self::GROUP_LOCAL_15, self::GROUP_LOCAL_25 ), true );
    }

    public static function build_rates( $context ) {
        $config = self::config();
        $rates  = array();

        $country   = isset( $context['country'] ) && is_scalar( $context['country'] ) ? strtoupper( (string) $context['country'] ) : '';
        $city      = isset( $context['city'] ) && is_scalar( $context['city'] ) ? (string) $context['city'] : '';
        $district  = isset( $context['district'] ) && is_scalar( $context['district'] ) ? (string) $context['district'] : '';
        $group     = isset( $context['group'] ) ? self::sanitize_group( $context['group'], self::GROUP_CARRIER ) : self::GROUP_CARRIER;
        $weight_kg = isset( $context['weight_kg'] ) ? self::sanitize_non_negative_float( $context['weight_kg'], 0.0 ) : 0.0;
        $amount    = isset( $context['amount'] ) ? self::sanitize_non_negative_float( $context['amount'], 0.0 ) : 0.0;
        $distance_km = isset( $context['distance_km'] ) && is_numeric( $context['distance_km'] ) ? max( 0.0, (float) $context['distance_km'] ) : null;

        if ( $country && 'SA' !== $country ) {
            return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
        }

        // Heavy/sink applies strictly ABOVE the threshold: a cart at exactly heavy_threshold_kg
        // ships as a normal carrier parcel (matches "orders above N kg" in the rules doc).
        if ( 'yes' === $config['heavy_enabled'] && $weight_kg > (float) $config['heavy_threshold_kg'] ) {
            $estimate = self::localized_estimate( $config, 'heavy_delivery_estimate' );
            $rates[]  = array(
                'code'        => 'heavy_sink',
                'label'       => self::label_with_estimate( __( 'Heavy sink shipping', 'lawhaa-shipping-rules' ), $estimate ),
                'cost'        => self::heavy_cost( $weight_kg, $config ),
                'family'      => 'heavy',
                'cod_allowed' => self::cod_allowed_for_rate( 'heavy_sink' ),
                'cod_fee'     => self::cod_fee_for_rate( 'heavy_sink' ),
                'meta'        => array(
                    __( 'Delivery time', 'lawhaa-shipping-rules' ) => $estimate,
                    __( 'Rule', 'lawhaa-shipping-rules' ) => sprintf( __( 'Orders above %s kg', 'lawhaa-shipping-rules' ), wc_format_decimal( $config['heavy_threshold_kg'], 2 ) ),
                ),
            );
            self::log_debug( 'Generated Lawhaa rates', compact( 'city', 'district', 'group', 'weight_kg', 'amount', 'distance_km', 'rates' ) );
            return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
        }

        if ( self::is_local_group( $group ) ) {
            if ( 'yes' === $config['center_enabled'] ) {
                $center_cost = ( self::GROUP_LOCAL_15 === $group ) ? (float) $config['center_local_15_cost'] : (float) $config['center_local_25_cost'];
                $center_free = $amount >= (float) $config['center_free_min'];
                $estimate    = self::localized_estimate( $config, 'center_delivery_estimate' );
                $label       = self::setting_label( $config, 'center_label', __( 'Center delivery', 'lawhaa-shipping-rules' ) );

                $rates[] = array(
                    'code'        => 'center_delivery',
                    'label'       => self::label_with_estimate( $label, $estimate, $center_free ),
                    'cost'        => $center_free ? 0.0 : $center_cost,
                    'family'      => 'center',
                    'cod_allowed' => self::cod_allowed_for_rate( 'center_delivery' ),
                    'cod_fee'     => self::cod_fee_for_rate( 'center_delivery' ),
                    'meta'        => array(
                        __( 'Delivery time', 'lawhaa-shipping-rules' ) => $estimate,
                        __( 'COD fee', 'lawhaa-shipping-rules' ) => $config['center_cod_fee'] > 0 ? wc_price( $config['center_cod_fee'] ) : __( 'No COD fee', 'lawhaa-shipping-rules' ),
                    ),
                );
            }

            $fast_groups    = isset( $config['fast_groups'] ) ? (array) $config['fast_groups'] : array();
            $fast_group_ok  = in_array( $group, $fast_groups, true );
            $mrsool_allowed = $fast_group_ok && self::matches_alias_group( 'mrsool', $city, $district );
            $c4d_allowed    = $fast_group_ok && self::matches_alias_group( 'c4d', $city, $district );
            foreach ( array( 'mrsool' => $mrsool_allowed, 'c4d' => $c4d_allowed ) as $code => $allowed ) {
                if ( 'yes' !== $config[ $code . '_enabled' ] || ! $allowed || null === $distance_km || $distance_km > (float) $config[ $code . '_max_km' ] ) {
                    continue;
                }
                $estimate = self::localized_estimate( $config, $code . '_delivery_estimate' );
                $label    = 'mrsool' === $code ? __( 'Fast shipping - Mrsool', 'lawhaa-shipping-rules' ) : __( 'Fast shipping - C4D', 'lawhaa-shipping-rules' );
                $rates[]  = array(
                    'code'        => $code,
                    'label'       => self::label_with_estimate( $label, $estimate ),
                    'cost'        => self::distance_cost( $distance_km, $code, $config ),
                    'family'      => self::rate_family( $code ),
                    'cod_allowed' => self::cod_allowed_for_rate( $code ),
                    'cod_fee'     => self::cod_fee_for_rate( $code ),
                    'meta'        => array(
                        __( 'Delivery time', 'lawhaa-shipping-rules' ) => $estimate,
                        __( 'Distance', 'lawhaa-shipping-rules' ) => sprintf( __( '%s km', 'lawhaa-shipping-rules' ), wc_format_decimal( $distance_km, 1 ) ),
                    ),
                );
            }

            $pickup_groups   = isset( $config['pickup_groups'] ) ? (array) $config['pickup_groups'] : array();
            $pickup_group_ok = in_array( $group, $pickup_groups, true );
            if ( 'yes' === $config['pickup_enabled'] && $pickup_group_ok && self::matches_alias_group( 'pickup', $city, $district ) ) {
                $label = self::setting_label( $config, 'pickup_label', __( 'Pickup from center', 'lawhaa-shipping-rules' ) );
                $rates[] = array(
                    'code'        => 'local_pickup',
                    'label'       => $label,
                    'cost'        => (float) $config['pickup_cost'],
                    'family'      => 'pickup',
                    'cod_allowed' => self::cod_allowed_for_rate( 'local_pickup' ),
                    'cod_fee'     => self::cod_fee_for_rate( 'local_pickup' ),
                    'meta'        => array(
                        __( 'Pickup', 'lawhaa-shipping-rules' ) => __( 'Available for nearby areas', 'lawhaa-shipping-rules' ),
                    ),
                );
            }

            self::log_debug( 'Generated Lawhaa rates', compact( 'city', 'district', 'group', 'weight_kg', 'amount', 'distance_km', 'rates' ) );
            return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
        }

        if ( 'yes' === $config['carrier_enabled'] ) {
            $carrier_free = ( $amount >= (float) $config['carrier_free_min'] && $weight_kg <= (float) $config['carrier_free_max_kg'] );
            $estimate     = self::localized_estimate( $config, 'carrier_delivery_estimate' );

            $rates[] = array(
                'code'        => $carrier_free ? 'carrier_free' : 'carrier',
                'label'       => self::label_with_estimate( __( 'Carrier shipping', 'lawhaa-shipping-rules' ), $estimate, $carrier_free ),
                'cost'        => $carrier_free ? 0.0 : self::carrier_cost( $weight_kg, $config ),
                'family'      => 'carrier',
                'cod_allowed' => self::cod_allowed_for_rate( $carrier_free ? 'carrier_free' : 'carrier' ),
                'cod_fee'     => self::cod_fee_for_rate( $carrier_free ? 'carrier_free' : 'carrier' ),
                'meta'        => array(
                    __( 'Delivery time', 'lawhaa-shipping-rules' ) => $estimate,
                    __( 'COD fee', 'lawhaa-shipping-rules' ) => sprintf( __( '%s SAR if COD is selected', 'lawhaa-shipping-rules' ), wc_format_decimal( $config['cod_carrier_fee'], 2 ) ),
                ),
            );
        }

        self::log_debug( 'Generated Lawhaa rates', compact( 'city', 'district', 'group', 'weight_kg', 'amount', 'distance_km', 'rates' ) );
        return apply_filters( 'lawhaa_shipping_rates', $rates, $context, $config );
    }

    /**
     * Distance price: the base cost covers the first base_km; every started km after that adds per_km.
     */
    public static function distance_cost( $distance_km, $code, $config = null ) {
        $config   = ( null === $config ? self::config() : self::sanitize_config( $config ) );
        $code     = 'c4d' === $code ? 'c4d' : 'mrsool';
        $km       = self::sanitize_non_negative_float( $distance_km, 0.0 );
        $extra_km = (int) ceil( round( max( 0.0, $km - (float) $config[ $code . '_base_km' ] ), 3 ) );

        return (float) $config[ $code . '_base_cost' ] + $extra_km * (float) $config[ $code . '_per_km' ];
    }

    /**
     * Road-distance estimate in km from the store to the destination, with its source:
     * "address" (national address coordinates) or "city" (configured city point).
     * km is null when the store point or the destination point is unknown, and is not rounded.
     */
    public static function destination_distance( $short_code, $city ) {
        $config = self::config();
        $none   = array( 'km' => null, 'source' => '' );
        if ( $config['store_lat'] <= 0.0 || $config['store_lng'] <= 0.0 ) {
            return $none;
        }

        $point  = null;
        $source = '';
        $code   = self::sanitize_short_code( $short_code );
        if ( '' !== $code && class_exists( 'Lawhaa_National_Address' ) ) {
            $point  = Lawhaa_National_Address::coordinates( $code );
            $source = 'address';
        }
        if ( ! $point ) {
            $point  = self::city_point( $city );
            $source = 'city';
        }
        if ( ! $point ) {
            return $none;
        }

        $km = self::haversine_km( $config['store_lat'], $config['store_lng'], $point[0], $point[1] ) * (float) $config['distance_road_factor'];

        // Unrounded: the max-distance check and the price use the exact value; display rounds.
        return array( 'km' => $km, 'source' => $source );
    }

    public static function haversine_km( $lat1, $lng1, $lat2, $lng2 ) {
        $d_lat = deg2rad( (float) $lat2 - (float) $lat1 );
        $d_lng = deg2rad( (float) $lng2 - (float) $lng1 );
        $a     = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( (float) $lat1 ) ) * cos( deg2rad( (float) $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

        return 6371.0 * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
    }

    /**
     * Configured point for a city name, from lines like "Dammam, الدمام | 26.42, 50.08".
     *
     * @return array|null [ lat, lng ]
     */
    public static function city_point( $city ) {
        $city_key = self::normalize_location( $city );
        if ( '' === $city_key ) {
            return null;
        }

        foreach ( self::alias_lines( self::config()['distance_city_points'] ) as $line ) {
            $parts = explode( '|', $line );
            if ( 2 !== count( $parts ) || ! preg_match( '/^\s*(-?[0-9.]+)\s*,\s*(-?[0-9.]+)\s*$/', $parts[1], $m ) ) {
                continue;
            }
            foreach ( explode( ',', $parts[0] ) as $name ) {
                if ( self::normalize_location( $name ) === $city_key ) {
                    return array( (float) $m[1], (float) $m[2] );
                }
            }
        }

        return null;
    }

    public static function sanitize_short_code( $code ) {
        $code = is_scalar( $code ) ? strtoupper( preg_replace( '/\s+/', '', (string) $code ) ) : '';

        return preg_match( '/^[A-Z]{4}[0-9]{4}$/', $code ) ? $code : '';
    }

    public static function carrier_cost( $weight_kg, $config = null ) {
        $config    = ( null === $config ? self::config() : self::sanitize_config( $config ) );
        $weight_kg = self::sanitize_non_negative_float( $weight_kg, 0.0 );
        $first_block_kg = (float) $config['carrier_first_block_kg'];
        if ( $weight_kg <= $first_block_kg ) {
            return (float) $config['carrier_first_10kg'];
        }
        $extra_steps = (int) ceil( ( $weight_kg - $first_block_kg ) / (float) $config['carrier_extra_step_kg'] );
        return (float) $config['carrier_first_10kg'] + $extra_steps * (float) $config['carrier_extra_step_fee'];
    }

    public static function heavy_cost( $weight_kg, $config = null ) {
        $config    = ( null === $config ? self::config() : self::sanitize_config( $config ) );
        $weight_kg = self::sanitize_non_negative_float( $weight_kg, 0.0 );
        $first_block_kg = (float) $config['heavy_first_block_kg'];
        if ( $weight_kg <= $first_block_kg ) {
            return (float) $config['heavy_first_10kg'];
        }
        return (float) $config['heavy_first_10kg'] + (int) ceil( $weight_kg - $first_block_kg ) * (float) $config['heavy_extra_per_kg'];
    }

    public static function parse_rate_code( $method_id ) {
        if ( ! is_scalar( $method_id ) ) {
            return '';
        }

        $method_id = (string) $method_id;
        if ( false === strpos( $method_id, 'lawhaa_rules:' ) ) {
            return '';
        }
        $parts = explode( ':', $method_id );
        return isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
    }

    public static function rate_family( $code ) {
        $code = is_scalar( $code ) ? sanitize_key( (string) $code ) : '';
        $map = array(
            'center_delivery' => 'center',
            'mrsool'          => 'mrsool',
            'c4d'             => 'fast',
            'local_pickup'    => 'pickup',
            'carrier'         => 'carrier',
            'carrier_free'    => 'carrier',
            'heavy_sink'      => 'heavy',
        );
        return isset( $map[ $code ] ) ? $map[ $code ] : '';
    }

    public static function rate_families() {
        return array( 'center', 'mrsool', 'fast', 'pickup', 'carrier', 'heavy' );
    }

    /**
     * Whether a webhook delivery should be blocked because the order's rate family
     * is excluded from OTO. Orders without a family (manual/admin orders) are not blocked.
     */
    public static function blocks_oto_delivery( $delivery_url, $family ) {
        $config = self::config();
        $match  = isset( $config['oto_webhook_match'] ) ? trim( (string) $config['oto_webhook_match'] ) : '';
        $family = is_scalar( $family ) ? sanitize_key( (string) $family ) : '';

        if ( '' === $match || '' === $family || ! is_scalar( $delivery_url ) || false === strpos( (string) $delivery_url, $match ) ) {
            return false;
        }

        return in_array( $family, (array) $config['oto_excluded_families'], true );
    }

    public static function cod_allowed_for_rate( $code ) {
        $config = self::config();
        $code   = is_scalar( $code ) ? sanitize_key( (string) $code ) : '';
        $map    = array(
            'center_delivery' => 'center_cod_allowed',
            'local_pickup'    => 'pickup_cod_allowed',
            'mrsool'          => 'mrsool_cod_allowed',
            'c4d'             => 'c4d_cod_allowed',
            'carrier'         => 'carrier_cod_allowed',
            'carrier_free'    => 'carrier_cod_allowed',
            'heavy_sink'      => 'heavy_cod_allowed',
        );

        $allowed = isset( $map[ $code ], $config[ $map[ $code ] ] ) && 'yes' === $config[ $map[ $code ] ];
        self::log_debug( 'COD availability decision', array( 'rate_code' => $code, 'cod_allowed' => $allowed ? 'yes' : 'no' ) );

        return $allowed;
    }

    public static function cod_fee_for_rate( $code ) {
        $config = self::config();
        $code   = is_scalar( $code ) ? sanitize_key( (string) $code ) : '';
        $map    = array(
            'center_delivery' => 'center_cod_fee',
            'local_pickup'    => 'pickup_cod_fee',
            'carrier'         => 'cod_carrier_fee',
            'carrier_free'    => 'cod_carrier_fee',
        );

        return isset( $map[ $code ], $config[ $map[ $code ] ] ) ? (float) $config[ $map[ $code ] ] : 0.0;
    }

    public static function carrier_codes() {
        return array( 'carrier', 'carrier_free' );
    }

    public static function log_debug( $message, $context = array() ) {
        $config = self::config();
        if ( empty( $config['debug_enabled'] ) || 'yes' !== $config['debug_enabled'] || ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        $safe_context = array();
        foreach ( (array) $context as $key => $value ) {
            if ( in_array( $key, array( 'city', 'district', 'group', 'weight_kg', 'amount', 'distance_km', 'rates', 'rate_code', 'cod_allowed', 'cod_fee' ), true ) ) {
                $safe_context[ $key ] = $value;
            }
        }

        wc_get_logger()->debug(
            $message . ' ' . wp_json_encode( $safe_context ),
            array( 'source' => 'lawhaa-shipping-rules' )
        );
    }
}
