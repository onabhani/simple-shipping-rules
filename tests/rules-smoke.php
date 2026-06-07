<?php
/**
 * Smoke tests for the README-CODEX shipping scenarios.
 *
 * Run from the plugin root with:
 * php tests/rules-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/../' );

function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return $text;
}

function apply_filters( $hook_name, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return $value;
}

function sanitize_key( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
}

function sanitize_text_field( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}

function wp_unslash( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return $value;
}

function wc_clean( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return is_array( $value ) ? array_map( 'wc_clean', $value ) : sanitize_text_field( $value );
}

require __DIR__ . '/../includes/class-lawhaa-shipping-rules.php';

$failures = array();

set_error_handler(
    function( $severity, $message, $file, $line ) {
        global $failures;
        $failures[] = sprintf( 'PHP warning/notice: %s in %s:%d', $message, $file, $line );
        return true;
    }
);

function lawhaa_test_assert_same( $label, $expected, $actual ) {
    global $failures;
    if ( $expected !== $actual ) {
        $failures[] = sprintf( '%s: expected %s, got %s', $label, var_export( $expected, true ), var_export( $actual, true ) );
    }
}

function lawhaa_rates_for( $city, $weight_kg, $amount ) {
    return Lawhaa_Shipping_Rules::build_rates(
        array(
            'country'   => 'SA',
            'city'      => $city,
            'district'  => '',
            'group'     => Lawhaa_Shipping_Rules::city_group( $city ),
            'weight_kg' => $weight_kg,
            'amount'    => $amount,
        )
    );
}

function lawhaa_rate_costs( $rates ) {
    $costs = array();
    foreach ( $rates as $rate ) {
        $costs[ $rate['code'] ] = (float) $rate['cost'];
    }
    return $costs;
}

lawhaa_test_assert_same(
    'Dammam, 5 kg, 200 SAR',
    array(
        'center_delivery' => 15.0,
        'mrsool'          => 38.0,
        'c4d'             => 29.0,
        'local_pickup'    => 0.0,
    ),
    lawhaa_rate_costs( lawhaa_rates_for( 'Dammam', 5, 200 ) )
);

lawhaa_test_assert_same(
    'Qatif, 5 kg, 200 SAR',
    array(
        'center_delivery' => 25.0,
        'mrsool'          => 38.0,
        'c4d'             => 29.0,
        'local_pickup'    => 0.0,
    ),
    lawhaa_rate_costs( lawhaa_rates_for( 'Qatif', 5, 200 ) )
);

lawhaa_test_assert_same(
    'Dammam, 5 kg, 350 SAR',
    array(
        'center_delivery' => 0.0,
        'mrsool'          => 38.0,
        'c4d'             => 29.0,
        'local_pickup'    => 0.0,
    ),
    lawhaa_rate_costs( lawhaa_rates_for( 'Dammam', 5, 350 ) )
);

lawhaa_test_assert_same(
    'Riyadh, 20 kg, 200 SAR',
    array( 'carrier' => 30.0 ),
    lawhaa_rate_costs( lawhaa_rates_for( 'Riyadh', 20, 200 ) )
);

lawhaa_test_assert_same(
    'Riyadh, 40 kg, 400 SAR',
    array( 'carrier_free' => 0.0 ),
    lawhaa_rate_costs( lawhaa_rates_for( 'Riyadh', 40, 400 ) )
);

lawhaa_test_assert_same(
    'Riyadh, 60 kg, 400 SAR',
    array( 'carrier' => 74.0 ),
    lawhaa_rate_costs( lawhaa_rates_for( 'Riyadh', 60, 400 ) )
);

lawhaa_test_assert_same(
    'Any city, 151 kg',
    array( 'heavy_sink' => 307.0 ),
    lawhaa_rate_costs( lawhaa_rates_for( 'Riyadh', 151, 400 ) )
);

lawhaa_test_assert_same( 'COD allowed for center', true, Lawhaa_Shipping_Rules::cod_allowed_for_rate( 'center_delivery' ) );
lawhaa_test_assert_same( 'COD blocked for Mrsool', false, Lawhaa_Shipping_Rules::cod_allowed_for_rate( 'mrsool' ) );
lawhaa_test_assert_same( 'COD blocked for C4D', false, Lawhaa_Shipping_Rules::cod_allowed_for_rate( 'c4d' ) );
lawhaa_test_assert_same( 'COD allowed for carrier', true, Lawhaa_Shipping_Rules::cod_allowed_for_rate( 'carrier' ) );
lawhaa_test_assert_same( 'COD blocked for heavy sink', false, Lawhaa_Shipping_Rules::cod_allowed_for_rate( 'heavy_sink' ) );

lawhaa_test_assert_same(
    'Sanitized config prevents invalid carrier divisor',
    0.9,
    Lawhaa_Shipping_Rules::sanitize_config( array( 'carrier_extra_step_kg' => 0 ) )['carrier_extra_step_kg']
);

lawhaa_test_assert_same(
    'Non-numeric config uses default',
    300.0,
    Lawhaa_Shipping_Rules::sanitize_config( array( 'center_free_min' => array( 'bad' ) ) )['center_free_min']
);

lawhaa_test_assert_same(
    'Unknown config keys are stripped',
    false,
    array_key_exists( 'unexpected_key', Lawhaa_Shipping_Rules::sanitize_config( array( 'unexpected_key' => 'value' ) ) )
);

lawhaa_test_assert_same(
    'Nested group config entries are ignored',
    array( 'local_15' ),
    Lawhaa_Shipping_Rules::sanitize_config( array( 'pickup_groups' => array( array( 'bad' ), 'local_15' ) ) )['pickup_groups']
);

lawhaa_test_assert_same(
    'Array destination values are rejected',
    '',
    Lawhaa_Shipping_Rules::get_destination_value( array( 'destination' => array( 'city' => array( 'Dammam' ) ) ), 'city' )
);

lawhaa_test_assert_same(
    'Non-array destination is rejected',
    '',
    Lawhaa_Shipping_Rules::get_destination_value( array( 'destination' => 'Dammam' ), 'city' )
);

lawhaa_test_assert_same(
    'Unknown destination keys are rejected',
    '',
    Lawhaa_Shipping_Rules::get_destination_value( array( 'destination' => array( 'email' => 'x@example.test' ) ), 'email' )
);

lawhaa_test_assert_same(
    'Array locations normalize to empty string',
    '',
    Lawhaa_Shipping_Rules::normalize_location( array( 'Dammam' ) )
);

lawhaa_test_assert_same(
    'Invalid rate code payload is rejected',
    '',
    Lawhaa_Shipping_Rules::parse_rate_code( array( 'lawhaa_rules:carrier' ) )
);

lawhaa_test_assert_same(
    'Carrier cost tolerates unsafe public inputs',
    18.0,
    Lawhaa_Shipping_Rules::carrier_cost( array( 'bad' ), array( 'carrier_extra_step_kg' => 0 ) )
);

if ( $failures ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo 'README-CODEX shipping scenarios passed.' . PHP_EOL;
