<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coordinates for a Saudi national address short code (e.g. EMDC3076), from OTO's
 * getNationalAddressFromShortCode API.
 *
 * Integration: reuses the refresh token saved by the "KSA National Address Validator" plugin
 * by OTO (options otoksa_refresh_token / ksa_nav_refresh_token). This plugin keeps its own
 * access-token transient and never writes the validator's options.
 */
final class Lawhaa_National_Address {
    const API_BASE      = 'https://api.tryoto.com/rest/v2/';
    const TOKEN_KEY     = 'lawhaa_oto_access_token';
    const CACHE_PREFIX  = 'lawhaa_na_';
    const CACHE_TTL     = 30 * DAY_IN_SECONDS;
    const FAILURE_TTL   = 10 * MINUTE_IN_SECONDS;
    const TIMEOUT       = 5;

    /**
     * @return array|null [ lat, lng ], or null when unknown. Results and failures are cached.
     */
    public static function coordinates( $short_code ) {
        $code = Lawhaa_Shipping_Rules::sanitize_short_code( $short_code );
        if ( '' === $code ) {
            return null;
        }

        $cache_key = self::CACHE_PREFIX . strtolower( $code );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return isset( $cached['lat'], $cached['lng'] ) ? array( (float) $cached['lat'], (float) $cached['lng'] ) : null;
        }

        $point = self::lookup( $code );
        if ( $point ) {
            set_transient( $cache_key, array( 'lat' => $point[0], 'lng' => $point[1] ), self::CACHE_TTL );
        } else {
            set_transient( $cache_key, array( 'failed' => 1 ), self::FAILURE_TTL );
        }

        return $point;
    }

    private static function lookup( $code ) {
        $data = self::request_address( $code, self::access_token() );
        if ( 'expired' === $data ) {
            delete_transient( self::TOKEN_KEY );
            $data = self::request_address( $code, self::access_token() );
        }
        if ( ! is_array( $data ) || ! isset( $data['lat'], $data['lng'] ) || ! is_numeric( $data['lat'] ) || ! is_numeric( $data['lng'] ) ) {
            Lawhaa_Shipping_Rules::log_debug( 'National address lookup failed' );
            return null;
        }

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        if ( $lat < 16.0 || $lat > 33.0 || $lng < 34.0 || $lng > 56.0 ) {
            return null;
        }

        return array( $lat, $lng );
    }

    /**
     * @return array|string|null Address data, 'expired' for an expired token, or null.
     */
    private static function request_address( $code, $token ) {
        if ( '' === $token ) {
            return null;
        }

        $response = wp_remote_post(
            self::API_BASE . 'getNationalAddressFromShortCode',
            array(
                'timeout' => self::TIMEOUT,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ),
                'body'    => wp_json_encode( array( 'shortAddressCode' => $code ) ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 401 === (int) wp_remote_retrieve_response_code( $response ) || ( is_array( $body ) && isset( $body['code'] ) && 401 === (int) $body['code'] ) ) {
            return 'expired';
        }
        if ( ! is_array( $body ) || empty( $body['success'] ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
            return null;
        }

        return $body['data'];
    }

    private static function access_token() {
        $token = get_transient( self::TOKEN_KEY );
        if ( is_string( $token ) && '' !== $token ) {
            return $token;
        }

        $refresh_token = (string) get_option( 'otoksa_refresh_token', '' );
        if ( '' === $refresh_token ) {
            $refresh_token = (string) get_option( 'ksa_nav_refresh_token', '' );
        }
        if ( '' === $refresh_token ) {
            return '';
        }

        $response = wp_remote_post(
            self::API_BASE . 'refreshToken',
            array(
                'timeout' => self::TIMEOUT,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array( 'refresh_token' => $refresh_token ) ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
            return '';
        }

        set_transient( self::TOKEN_KEY, $body['access_token'], 50 * MINUTE_IN_SECONDS );

        return $body['access_token'];
    }
}
