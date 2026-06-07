<?php
/**
 * Example customizations for Lawhaa Shipping Rules.
 * Put snippets like these in a small site plugin, not in the theme functions.php.
 */

// 1) Change default pricing/thresholds.
add_filter( 'lawhaa_shipping_rule_config', function( $config ) {
    $config['center_free_min']     = 300;
    $config['carrier_free_min']    = 350;
    $config['cod_carrier_fee']     = 15;
    $config['heavy_threshold_kg']  = 150;
    return $config;
} );

// 2) Add more aliases for city/district matching.
add_filter( 'lawhaa_shipping_city_aliases', function( $aliases ) {
    $aliases['local_15'][] = 'dammam industrial city';
    $aliases['local_25'][] = 'rakah';
    return $aliases;
} );

// 3) Disable pickup for local_25, keep it only for Dammam/Saihat.
add_filter( 'lawhaa_shipping_rule_config', function( $config ) {
    $config['pickup_groups'] = array( 'local_15' );
    return $config;
} );

// 4) Disable fast delivery for local_25 if needed.
add_filter( 'lawhaa_shipping_rule_config', function( $config ) {
    $config['fast_groups'] = array( 'local_15' );
    return $config;
} );
