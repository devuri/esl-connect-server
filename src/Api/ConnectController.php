<?php
declare(strict_types=1);

namespace EslConnectServer\Api;

use EslConnectServer\Store\StoreManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for ESL Connect endpoints.
 *
 * Handles all incoming API requests from connected stores.
 *
 * @since 0.1.0
 */
final class ConnectController
{
    /**
     * REST API namespace.
     */
    public const NAMESPACE = 'esl-connect/v1';

    /**
     * Register REST API routes.
     *
     * @since 0.1.0
     */
    public static function register_routes(): void
    {
        // POST /license/reserve - Check & reserve license slot.
        register_rest_route(
            self::NAMESPACE,
            '/license/reserve',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'handle_reserve' ],
                'permission_callback' => [ self::class, 'validate_signed_request' ],
                'args'                => [
                    'store_token' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'license_key_hash' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'product_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /license/release - Release license slot.
        register_rest_route(
            self::NAMESPACE,
            '/license/release',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'handle_release' ],
                'permission_callback' => [ self::class, 'validate_signed_request' ],
                'args'                => [
                    'store_token' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'license_key_hash' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // GET /status - Get connection status.
        register_rest_route(
            self::NAMESPACE,
            '/status',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'handle_status' ],
                'permission_callback' => [ self::class, 'validate_token_request' ],
                'args'                => [
                    'store_token' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /sync - Sync counts.
        register_rest_route(
            self::NAMESPACE,
            '/sync',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'handle_sync' ],
                'permission_callback' => [ self::class, 'validate_signed_request' ],
                'args'                => [
                    'store_token' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'reported_count' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // GET /health - Service health check (public).
        register_rest_route(
            self::NAMESPACE,
            '/health',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'handle_health' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Permission callback for signed requests.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True if valid, WP_Error on failure.
     */
    public static function validate_signed_request( WP_REST_Request $request )
    {
        return RequestValidator::validate_signed_request( $request );
    }

    /**
     * Permission callback for token-only requests.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True if valid, WP_Error on failure.
     */
    public static function validate_token_request( WP_REST_Request $request )
    {
        return RequestValidator::validate_token_request( $request );
    }

    /**
     * Handle reserve license slot request.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The response.
     */
    public static function handle_reserve( WP_REST_Request $request ): WP_REST_Response
    {
        $store_token      = $request->get_param( 'store_token' );
        $license_key_hash = $request->get_param( 'license_key_hash' );
        $product_id       = $request->get_param( 'product_id' );

        $result = StoreManager::reserve_license_slot( $store_token, $license_key_hash, $product_id );

        if ( $result['allowed'] ) {
            return new WP_REST_Response(
                [
                    'success' => true,
                    'allowed' => true,
                    'data'    => $result['data'],
                ],
                200
            );
        }

        // Determine HTTP status based on error.
        $status = 403;
        if ( 'store_not_found' === ( $result['error'] ?? '' ) ) {
            $status = 404;
        }

        return new WP_REST_Response(
            [
                'success' => false,
                'allowed' => false,
                'error'   => $result['error'] ?? 'unknown_error',
                'message' => $result['message'] ?? __( 'License creation denied.', 'esl-connect-server' ),
                'data'    => $result['data'] ?? [],
            ],
            $status
        );
    }

    /**
     * Handle release license slot request.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The response.
     */
    public static function handle_release( WP_REST_Request $request ): WP_REST_Response
    {
        $store_token      = $request->get_param( 'store_token' );
        $license_key_hash = $request->get_param( 'license_key_hash' );

        $result = StoreManager::release_license_slot( $store_token, $license_key_hash );

        if ( $result['success'] ) {
            return new WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $result['data'],
                ],
                200
            );
        }

        $status = 'store_not_found' === ( $result['error'] ?? '' ) ? 404 : 400;

        return new WP_REST_Response(
            [
                'success' => false,
                'error'   => $result['error'] ?? 'release_failed',
            ],
            $status
        );
    }

    /**
     * Handle status request.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The response.
     */
    public static function handle_status( WP_REST_Request $request ): WP_REST_Response
    {
        $store_token = $request->get_param( 'store_token' );

        $result = StoreManager::get_status( $store_token );

        if ( $result['success'] ) {
            return new WP_REST_Response( $result, 200 );
        }

        return new WP_REST_Response(
            [
                'success' => false,
                'error'   => $result['error'] ?? 'status_failed',
            ],
            404
        );
    }

    /**
     * Handle sync request.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The response.
     */
    public static function handle_sync( WP_REST_Request $request ): WP_REST_Response
    {
        $store_token    = $request->get_param( 'store_token' );
        $reported_count = $request->get_param( 'reported_count' );

        $result = StoreManager::sync( $store_token, $reported_count );

        if ( $result['success'] ) {
            return new WP_REST_Response( $result, 200 );
        }

        return new WP_REST_Response(
            [
                'success' => false,
                'error'   => $result['error'] ?? 'sync_failed',
            ],
            404
        );
    }

    /**
     * Handle health check request.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The response.
     */
    public static function handle_health( WP_REST_Request $request ): WP_REST_Response
    {
        $stats = StoreManager::get_health_stats();

        return new WP_REST_Response( $stats, 200 );
    }
}
