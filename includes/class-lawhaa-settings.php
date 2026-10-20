<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lawhaa_Settings {
    const OPTION_NAME = 'lawhaa_shipping_rules_settings';
    const MENU_SLUG   = 'lawhaa-shipping-rules';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Simple Shipping Rule', 'lawhaa-shipping-rules' ),
            __( 'Simple Shipping Rule', 'lawhaa-shipping-rules' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting(
            self::OPTION_NAME,
            self::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => Lawhaa_Shipping_Rules::default_config(),
            )
        );
    }

    public function sanitize_settings( $input ) {
        $config = Lawhaa_Shipping_Rules::sanitize_config( is_array( $input ) ? $input : array() );

        // Look up the store point when the store short code is new or changed.
        $previous = get_option( self::OPTION_NAME, array() );
        $previous = is_array( $previous ) && isset( $previous['store_short_code'] ) ? (string) $previous['store_short_code'] : '';
        if ( '' !== $config['store_short_code'] && ( $config['store_short_code'] !== $previous || $config['store_lat'] <= 0.0 || $config['store_lng'] <= 0.0 ) ) {
            $point = Lawhaa_National_Address::coordinates( $config['store_short_code'] );
            if ( $point ) {
                $config['store_lat'] = $point[0];
                $config['store_lng'] = $point[1];
            } else {
                add_settings_error( self::OPTION_NAME, 'lawhaa_store_point', __( 'Could not find the store national address. Enter the store latitude and longitude manually.', 'lawhaa-shipping-rules' ) );
            }
        }

        return $config;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage shipping settings.', 'lawhaa-shipping-rules' ) );
        }

        $settings = Lawhaa_Shipping_Rules::config();
        ?>
        <div class="wrap lawhaa-settings-wrap">
            <h1><?php esc_html_e( 'Simple Shipping Rule', 'lawhaa-shipping-rules' ); ?></h1>
            <p><?php esc_html_e( 'Configure shipping prices, city/district aliases, COD rules, and debug logging without editing PHP files.', 'lawhaa-shipping-rules' ); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_NAME ); ?>
                <?php $this->render_sections( $settings ); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function render_sections( $settings ) {
        $this->section_open( __( 'Center Shipping', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'center_enabled', __( 'Enable center shipping', 'lawhaa-shipping-rules' ) );
        $this->textarea( $settings, 'local_15_aliases', __( 'City/district group for 15 SAR shipping', 'lawhaa-shipping-rules' ), __( 'One alias per line. Supports Arabic and English.', 'lawhaa-shipping-rules' ) );
        $this->textarea( $settings, 'local_25_aliases', __( 'City/district group for 25 SAR shipping', 'lawhaa-shipping-rules' ), __( 'One alias per line. Supports Arabic and English.', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'center_local_15_cost', __( 'Group 1 price', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'center_local_25_cost', __( 'Group 2 price', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'center_free_min', __( 'Free shipping threshold', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'center_label_en', __( 'Delivery label English', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'center_label_ar', __( 'Delivery label Arabic', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'center_delivery_estimate', __( 'Delivery estimate (English/default)', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'center_delivery_estimate_ar', __( 'Delivery estimate (Arabic)', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'center_cod_allowed', __( 'COD allowed', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'center_cod_fee', __( 'COD fee', 'lawhaa-shipping-rules' ) );
        $this->section_close();

        $this->section_open( __( 'Distance (MRSOOL / C4D)', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'store_short_code', __( 'Store national address short code', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'store_lat', __( 'Store latitude', 'lawhaa-shipping-rules' ), '0.000001' );
        $this->number( $settings, 'store_lng', __( 'Store longitude', 'lawhaa-shipping-rules' ), '0.000001' );
        $this->number( $settings, 'distance_road_factor', __( 'Road factor', 'lawhaa-shipping-rules' ) );
        $this->textarea( $settings, 'distance_city_points', __( 'City points', 'lawhaa-shipping-rules' ), __( 'Used when the customer has no national address short code. One city per line: names separated by commas | latitude, longitude', 'lawhaa-shipping-rules' ) );
        $this->section_close();

        $this->fast_section( $settings, __( 'MRSOOL Fast Shipping', 'lawhaa-shipping-rules' ), 'mrsool', __( 'Enable MRSOOL', 'lawhaa-shipping-rules' ) );
        $this->fast_section( $settings, __( 'C4D Fast Shipping', 'lawhaa-shipping-rules' ), 'c4d', __( 'Enable C4D', 'lawhaa-shipping-rules' ) );

        $this->section_open( __( 'Local Pickup', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'pickup_enabled', __( 'Enable local pickup', 'lawhaa-shipping-rules' ) );
        $this->textarea( $settings, 'pickup_aliases', __( 'Allowed cities/districts', 'lawhaa-shipping-rules' ), __( 'One alias per line.', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'pickup_cost', __( 'Pickup price', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'pickup_cod_allowed', __( 'COD allowed', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'pickup_cod_fee', __( 'COD fee', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'pickup_label_en', __( 'Label English', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'pickup_label_ar', __( 'Label Arabic', 'lawhaa-shipping-rules' ) );
        $this->section_close();

        $this->section_open( __( 'Carrier Shipping', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'carrier_enabled', __( 'Enable carrier shipping', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'carrier_first_block_kg', __( 'First block weight (kg)', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'carrier_first_10kg', __( 'First block price', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'carrier_extra_step_kg', __( 'Additional block weight (kg)', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'carrier_extra_step_fee', __( 'Additional block price', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'carrier_free_min', __( 'Free shipping threshold', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'carrier_free_max_kg', __( 'Free shipping max weight (kg)', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'carrier_cod_allowed', __( 'COD allowed', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'cod_carrier_fee', __( 'COD fee', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'carrier_delivery_estimate', __( 'Delivery estimate (English/default)', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'carrier_delivery_estimate_ar', __( 'Delivery estimate (Arabic)', 'lawhaa-shipping-rules' ) );
        $this->section_close();

        $this->section_open( __( 'Heavy / Sink Shipping', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'heavy_enabled', __( 'Enable heavy / sink shipping', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'heavy_threshold_kg', __( 'Trigger minimum weight (kg)', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'heavy_first_block_kg', __( 'First block weight (kg)', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'heavy_first_10kg', __( 'First block price', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, 'heavy_extra_per_kg', __( 'Additional kg price', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'heavy_cod_allowed', __( 'COD allowed', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'heavy_delivery_estimate', __( 'Delivery estimate (English/default)', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'heavy_delivery_estimate_ar', __( 'Delivery estimate (Arabic)', 'lawhaa-shipping-rules' ) );
        $this->section_close();

        $this->section_open( __( 'OTO', 'lawhaa-shipping-rules' ) );
        $this->family_checkboxes( $settings, 'oto_excluded_families', __( 'Do not send these delivery types to OTO', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, 'oto_webhook_match', __( 'OTO webhook URL contains', 'lawhaa-shipping-rules' ) );
        $this->section_close();

        $this->section_open( __( 'Debug / Compatibility', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, 'debug_enabled', __( 'Enable WooCommerce debug logging', 'lawhaa-shipping-rules' ) );
        $this->section_close();
    }

    private function fast_section( $settings, $title, $prefix, $enable_label ) {
        $this->section_open( $title );
        $this->checkbox( $settings, $prefix . '_enabled', $enable_label );
        $this->textarea( $settings, $prefix . '_aliases', __( 'Allowed cities/districts', 'lawhaa-shipping-rules' ), __( 'One alias per line. Supports Arabic and English.', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, $prefix . '_max_km', __( 'Max distance (km)', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, $prefix . '_base_km', __( 'Base distance (km)', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, $prefix . '_base_cost', __( 'Base distance price', 'lawhaa-shipping-rules' ) );
        $this->number( $settings, $prefix . '_per_km', __( 'Additional km price', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, $prefix . '_delivery_estimate', __( 'Delivery estimate (English/default)', 'lawhaa-shipping-rules' ) );
        $this->text( $settings, $prefix . '_delivery_estimate_ar', __( 'Delivery estimate (Arabic)', 'lawhaa-shipping-rules' ) );
        $this->checkbox( $settings, $prefix . '_cod_allowed', __( 'COD allowed', 'lawhaa-shipping-rules' ) );
        $this->section_close();
    }

    private function section_open( $title ) {
        echo '<h2>' . esc_html( $title ) . '</h2><table class="form-table" role="presentation"><tbody>';
    }

    private function section_close() {
        echo '</tbody></table>';
    }

    private function field_name( $key ) {
        return self::OPTION_NAME . '[' . esc_attr( $key ) . ']';
    }

    private function checkbox( $settings, $key, $label ) {
        printf(
            '<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s" value="no"><label><input type="checkbox" name="%2$s" value="yes" %3$s> %4$s</label></td></tr>',
            esc_html( $label ),
            $this->field_name( $key ),
            checked( ! empty( $settings[ $key ] ) && 'yes' === $settings[ $key ], true, false ),
            esc_html__( 'Enabled', 'lawhaa-shipping-rules' )
        );
    }

    private function family_checkboxes( $settings, $key, $label ) {
        $labels   = array(
            'center'  => __( 'Center delivery', 'lawhaa-shipping-rules' ),
            'mrsool'  => __( 'MRSOOL', 'lawhaa-shipping-rules' ),
            'fast'    => __( 'Fast delivery (C4D)', 'lawhaa-shipping-rules' ),
            'pickup'  => __( 'Local pickup', 'lawhaa-shipping-rules' ),
            'carrier' => __( 'Carrier shipping', 'lawhaa-shipping-rules' ),
            'heavy'   => __( 'Heavy / sink shipping', 'lawhaa-shipping-rules' ),
        );
        $selected = isset( $settings[ $key ] ) ? (array) $settings[ $key ] : array();
        $boxes    = '';
        foreach ( Lawhaa_Shipping_Rules::rate_families() as $family ) {
            $boxes .= sprintf(
                '<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
                $this->field_name( $key ),
                esc_attr( $family ),
                checked( in_array( $family, $selected, true ), true, false ),
                esc_html( isset( $labels[ $family ] ) ? $labels[ $family ] : $family )
            );
        }

        printf(
            '<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s[]" value="">%3$s</td></tr>',
            esc_html( $label ),
            $this->field_name( $key ),
            $boxes
        );
    }

    private function number( $settings, $key, $label, $step = '0.01' ) {
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" min="0" step="%5$s" class="regular-text" id="%1$s" name="%3$s" value="%4$s"></td></tr>',
            esc_attr( $key ),
            esc_html( $label ),
            $this->field_name( $key ),
            esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' ),
            esc_attr( $step )
        );
    }

    private function text( $settings, $key, $label ) {
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s" name="%3$s" value="%4$s"></td></tr>',
            esc_attr( $key ),
            esc_html( $label ),
            $this->field_name( $key ),
            esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' )
        );
    }

    private function textarea( $settings, $key, $label, $description = '' ) {
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea class="large-text" rows="6" id="%1$s" name="%3$s">%4$s</textarea>%5$s</td></tr>',
            esc_attr( $key ),
            esc_html( $label ),
            $this->field_name( $key ),
            esc_textarea( isset( $settings[ $key ] ) ? $settings[ $key ] : '' ),
            $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
        );
    }
}
