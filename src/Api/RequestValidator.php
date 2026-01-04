<?php
declare(strict_types=1);

namespace EslConnectServer\Api;

use EslConnectServer\Store\StoreManager;
use WP_Error;
use WP_REST_Request;

/**
 * Request validator for ESL Connect API.
 *
 * Handles HMAC signature verification, timestamp validation, and rate limiting.
 *
 * @since 0.1.0
 */
final class RequestValidator
{
    /**
     * Timestamp window in seconds (5 minutes).
     */
    private const TIMESTAMP_WINDOW = 300;

    /**
     * Default rate limit (requests per minute).
     */
    private const DEFAULT_RATE_LIMIT = 60;

    /**
     * Rate limit window in seconds.
     */
    private const RATE_LIMIT_WINDOW = 60;

    /**
     * Validate a signed request.
     *
     * Checks signature, timestamp, and rate limits.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The REST request.
     *
     * @return true|WP_Error True if valid, WP_Error on failure.
     */
    public static function validate_signed_request( WP_REST_Request $request )
    {
        // Get required headers.
        $timestamp = $request->get_header( 'X-ESL-Timestamp' );
        $signature = $request->get_header( 'X-ESL-Signature' );

        if ( empty( $timestamp ) || empty( $signature ) ) {
            return new WP_Error(
                'missing_auth_headers',
                __( 'Missing required authentication headers.', 'esl-connect-server' ),
                [ 'status' => 401 ]
            );
        }

        // Validate timestamp.
        $timestamp_int = (int) $timestamp;
        $now           = time();

        if ( abs( $now - $timestamp_int ) > self::TIMESTAMP_WINDOW ) {
            return new WP_Error(
                'timestamp_expired',
                __( 'Request timestamp is too old or too far in the future.', 'esl-connect-server' ),
                [ 'status' => 401 ]
            );
        }

        // Get store token from body.
        $body_params = $request->get_json_params();
        $store_token = isset( $body_params['store_token'] )
            ? sanitize_text_field( $body_params['store_token'] )
            : '';

        if ( empty( $store_token ) ) {
            return new WP_Error(
                'missing_store_token',
                __( 'Store token is required.', 'esl-connect-server' ),
                [ 'status' => 400 ]
            );
        }

        // Get store to retrieve secret hash.
        $store = StoreManager::get_store_by_token( $store_token );

        if ( ! $store ) {
            return new WP_Error(
                'store_not_found',
                __( 'Store not connected to ESL Connect.', 'esl-connect-server' ),
                [ 'status' => 404 ]
            );
        }

        // Verify signature.
        $body = $request->get_body();
        $expected_signature = self::generate_signature( $store_token, $timestamp_int, $body, $store->store_secret_hash );

        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return new WP_Error(
                'invalid_signature',
                __( 'Request signature is invalid.', 'esl-connect-server' ),
                [ 'status' => 401 ]
            );
        }

        // Check rate limit.
        $rate_limit_check = self::check_rate_limit( $store_token );

        if ( is_wp_error( $rate_limit_check ) ) {
            return $rate_limit_check;
        }

        return true;
    }

    /**
     * Validate a token-only request (for GET endpoints).
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The REST request.
     *
     * @return true|WP_Error True if valid, WP_Error on failure.
     */
    public static function validate_token_request( WP_REST_Request $request )
    {
        $store_token = $request->get_param( 'store_token' );

        if ( empty( $store_token ) ) {
            return new WP_Error(
                'missing_store_token',
                __( 'Store token is required.', 'esl-connect-server' ),
                [ 'status' => 400 ]
            );
        }

        $store_token = sanitize_text_field( $store_token );
        $store       = StoreManager::get_store_by_token( $store_token );

        if ( ! $store ) {
            return new WP_Error(
                'store_not_found',
                __( 'Store not connected to ESL Connect.', 'esl-connect-server' ),
                [ 'status' => 404 ]
            );
        }

        // Check rate limit.
        $rate_limit_check = self::check_rate_limit( $store_token );

        if ( is_wp_error( $rate_limit_check ) ) {
            return $rate_limit_check;
        }

        return true;
    }

    /**
     * Generate HMAC signature.
     *
     * The signature is generated using the store's secret hash, which means
     * we never store the actual secret - only a hash of it. The client generates
     * the same signature using their secret, and we compare using our hash.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     * @param int    $timestamp   Unix timestamp.
     * @param string $body        Request body (JSON string).
     * @param string $secret_hash The stored hash of the client's secret.
     *
     * @return string HMAC-SHA256 signature.
     */
    private static function generate_signature(
        string $store_token,
        int $timestamp,
        string $body,
        string $secret_hash
    ): string {
        // The client generates: HMAC(store_token:timestamp:body, secret)
        // We stored: hash(secret) as store_secret_hash
        // We can't recreate the client's signature directly since we don't have their secret.
        //
        // Instead, we need to derive the same secret they have.
        // The secret is: hash('sha256', license_key . ':secret')
        // But we don't have the license key either!
        //
        // Solution: The client must use the secret_hash itself as the HMAC key,
        // OR we need to store the secret (not just its hash).
        //
        // For maximum security while still being able to verify:
        // We'll use the secret_hash as the HMAC key on both sides.
        // Client: HMAC(payload, secret_hash) where secret_hash = hash(secret)
        // Server: HMAC(payload, stored_secret_hash)

        $payload = $store_token . ':' . $timestamp . ':' . $body;

        return hash_hmac( 'sha256', $payload, $secret_hash );
    }

    /**
     * Check rate limit for a store.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     *
     * @return true|WP_Error True if within limit, WP_Error if exceeded.
     */
    private static function check_rate_limit( string $store_token ): bool
    {
        $transient_key = 'esl_connect_rate_' . md5( $store_token );
        $current_count = (int) get_transient( $transient_key );

        /**
         * Filter the rate limit for a store.
         *
         * @since 0.1.0
         *
         * @param int    $limit       Requests per minute.
         * @param string $store_token The store token.
         */
        $limit = (int) apply_filters( 'ppk_esl_connect_server_rate_limit', self::DEFAULT_RATE_LIMIT, $store_token );

        if ( $current_count >= $limit ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many requests. Please try again later.', 'esl-connect-server' ),
                [
                    'status'      => 429,
                    'retry_after' => self::RATE_LIMIT_WINDOW,
                ]
            );
        }

        // Increment counter.
        if ( 0 === $current_count ) {
            set_transient( $transient_key, 1, self::RATE_LIMIT_WINDOW );
        } else {
            set_transient( $transient_key, $current_count + 1, self::RATE_LIMIT_WINDOW );
        }

        return true;
    }

    /**
     * Get IP address from request.
     *
     * @since 0.1.0
     *
     * @return string IP address.
     */
    public static function get_client_ip(): string
    {
        // Check for forwarded IP (load balancer/proxy).
        $forwarded_for = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
            : '';

        if ( ! empty( $forwarded_for ) ) {
            // Take the first IP if multiple are present.
            $ips = explode( ',', $forwarded_for );
            $ip  = trim( $ips[0] );

            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        // Fall back to REMOTE_ADDR.
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        return $remote_addr;
    }
}
