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

function get_option( $name, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    if ( isset( $GLOBALS['lawhaa_test_options'][ $name ] ) ) {
        return $GLOBALS['lawhaa_test_options'][ $name ];
    }
    return $default;
}

function get_locale() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return isset( $GLOBALS['lawhaa_test_locale'] ) ? $GLOBALS['lawhaa_test_locale'] : 'en_US';
}

function determine_locale() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return get_locale();
}

function wc_format_decimal( $number, $dp = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return false === $dp ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' );
}

function wc_price( $price ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return (string) $price . ' SAR';
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

function lawhaa_rate_label( $rates, $code ) {
    foreach ( $rates as $rate ) {
        if ( $rate['code'] === $code ) {
            return (string) $rate['label'];
        }
    }
    return '';
}

// Rate-cost scenarios: [ label, city, weight_kg, amount, expected code => cost ].
// Includes the README-CODEX pricing cases plus boundary cases at the expensive
// thresholds (exactly at / just past mrsool_max_kg, carrier_free_max_kg, heavy_threshold_kg).
$cost_scenarios = array(
    array( 'Dammam, 5 kg, 200 SAR', 'Dammam', 5, 200, array( 'center_delivery' => 15.0, 'mrsool' => 38.0, 'c4d' => 29.0, 'local_pickup' => 0.0 ) ),
    array( 'Qatif, 5 kg, 200 SAR', 'Qatif', 5, 200, array( 'center_delivery' => 25.0, 'mrsool' => 38.0, 'c4d' => 29.0, 'local_pickup' => 0.0 ) ),
    array( 'Dammam, 5 kg, 350 SAR (center free)', 'Dammam', 5, 350, array( 'center_delivery' => 0.0, 'mrsool' => 38.0, 'c4d' => 29.0, 'local_pickup' => 0.0 ) ),
    array( 'Riyadh, 20 kg, 200 SAR', 'Riyadh', 20, 200, array( 'carrier' => 30.0 ) ),
    array( 'Riyadh, 18 kg, 352 SAR (carrier free)', 'Riyadh', 18, 352, array( 'carrier_free' => 0.0 ) ),
    array( 'Riyadh, 40 kg, 400 SAR (carrier free)', 'Riyadh', 40, 400, array( 'carrier_free' => 0.0 ) ),
    array( 'Riyadh, 60 kg, 400 SAR', 'Riyadh', 60, 400, array( 'carrier' => 74.0 ) ),
    array( 'Riyadh, 151 kg (heavy)', 'Riyadh', 151, 400, array( 'heavy_sink' => 307.0 ) ),
    array( 'Carrier free at exactly carrier_free_max_kg (50)', 'Riyadh', 50, 400, array( 'carrier_free' => 0.0 ) ),
    array( 'Carrier not free just past carrier_free_max_kg (50.01)', 'Riyadh', 50.01, 400, array( 'carrier' => 63.0 ) ),
    array( 'Heavy NOT triggered at exactly heavy_threshold_kg (150)', 'Riyadh', 150, 400, array( 'carrier' => 174.0 ) ),
    array( 'Heavy triggered just past heavy_threshold_kg (150.01)', 'Riyadh', 150.01, 400, array( 'heavy_sink' => 307.0 ) ),
    array( 'Zero-weight local cart bills fast at first-kg minimum', 'Dammam', 0, 200, array( 'center_delivery' => 15.0, 'mrsool' => 30.0, 'c4d' => 25.0, 'local_pickup' => 0.0 ) ),
);
foreach ( $cost_scenarios as $scenario ) {
    list( $label, $city, $weight, $amount, $expected ) = $scenario;
    lawhaa_test_assert_same( $label, $expected, lawhaa_rate_costs( lawhaa_rates_for( $city, $weight, $amount ) ) );
}

// Rate-code presence scenarios: [ label, city, weight_kg, amount, expected ordered codes ].
$code_scenarios = array(
    array( 'Mrsool/C4D offered at exactly mrsool_max_kg (50)', 'Dammam', 50, 200, array( 'center_delivery', 'mrsool', 'c4d', 'local_pickup' ) ),
    array( 'Mrsool/C4D dropped just past mrsool_max_kg (50.01)', 'Dammam', 50.01, 200, array( 'center_delivery', 'local_pickup' ) ),
);
foreach ( $code_scenarios as $scenario ) {
    list( $label, $city, $weight, $amount, $expected ) = $scenario;
    lawhaa_test_assert_same( $label, $expected, array_keys( lawhaa_rate_costs( lawhaa_rates_for( $city, $weight, $amount ) ) ) );
}

// Group allowlists (pickup_groups/fast_groups) must still restrict pickup/fast delivery (see docs/FILTER-EXAMPLES.php).
// config() memoizes per request, so reset the cache whenever the stored option changes mid-test.
$GLOBALS['lawhaa_test_options']['lawhaa_shipping_rules_settings'] = array(
    'fast_groups'   => array( 'local_15' ),
    'pickup_groups' => array( 'local_15' ),
);
Lawhaa_Shipping_Rules::reset_cache();
lawhaa_test_assert_same(
    'fast_groups/pickup_groups limited to local_15 still serves Dammam',
    array( 'center_delivery', 'mrsool', 'c4d', 'local_pickup' ),
    array_keys( lawhaa_rate_costs( lawhaa_rates_for( 'Dammam', 5, 200 ) ) )
);
lawhaa_test_assert_same(
    'fast_groups/pickup_groups limited to local_15 drops fast/pickup for Qatif (local_25)',
    array( 'center_delivery' ),
    array_keys( lawhaa_rate_costs( lawhaa_rates_for( 'Qatif', 5, 200 ) ) )
);
unset( $GLOBALS['lawhaa_test_options']['lawhaa_shipping_rules_settings'] );
Lawhaa_Shipping_Rules::reset_cache();

// Delivery estimates are localized: the English/default value on en, the *_ar companion on ar.
lawhaa_test_assert_same(
    'English locale shows the default delivery estimate',
    true,
    false !== strpos( lawhaa_rate_label( lawhaa_rates_for( 'Dammam', 5, 200 ), 'mrsool' ), '2-4 hours' )
);
$GLOBALS['lawhaa_test_locale'] = 'ar';
lawhaa_test_assert_same(
    'Arabic locale shows the Arabic delivery estimate',
    true,
    false !== strpos( lawhaa_rate_label( lawhaa_rates_for( 'Dammam', 5, 200 ), 'mrsool' ), 'خلال 2-4 ساعات' )
);
unset( $GLOBALS['lawhaa_test_locale'] );

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

$conflict_files = array(
    'docs/IMPLEMENTATION-NOTES.md',
    'includes/class-lawhaa-checkout.php',
    'includes/class-lawhaa-shipping-rules.php',
    'tests/rules-smoke.php',
);
foreach ( $conflict_files as $conflict_file ) {
    $contents = file_get_contents( __DIR__ . '/../' . $conflict_file );
    lawhaa_test_assert_same( $conflict_file . ' has no conflict markers', false, false !== strpos( $contents, str_repeat( '<', 7 ) ) || false !== strpos( $contents, str_repeat( '=', 7 ) ) || false !== strpos( $contents, str_repeat( '>', 7 ) ) );
}

if ( $failures ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo 'README-CODEX shipping scenarios passed.' . PHP_EOL;
