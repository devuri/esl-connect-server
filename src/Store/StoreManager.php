<?php
declare(strict_types=1);

namespace EslConnectServer\Store;

use EslConnectServer\Database\Installer;

/**
 * Store manager for connected store operations.
 *
 * Handles all CRUD operations and business logic for connected stores.
 *
 * @since 0.1.0
 */
final class StoreManager
{
    /**
     * Plan limits configuration.
     */
    private const PLAN_LIMITS = [
        'solo'   => 500,
        'studio' => null, // Unlimited.
        'agency' => null, // Unlimited.
    ];

    /**
     * Create a new connected store record.
     *
     * @since 0.1.0
     *
     * @param array $data Store data with keys: esl_license_id, license_key, store_url, plan.
     *
     * @return array{success: bool, store_token?: string, store_secret?: string, error?: string}
     */
    public static function create_store( array $data ): array
    {
        global $wpdb;

        $license_key   = $data['license_key'] ?? '';
        $esl_license_id = $data['esl_license_id'] ?? 0;
        $store_url     = $data['store_url'] ?? '';
        $plan          = $data['plan'] ?? 'solo';

        if ( empty( $license_key ) || empty( $esl_license_id ) || empty( $store_url ) ) {
            return [
                'success' => false,
                'error'   => 'missing_required_fields',
            ];
        }

        // Generate credentials derived from license key.
        $store_token  = hash( 'sha256', $license_key . ':connect' );
        $store_secret = hash( 'sha256', $license_key . ':secret' );

        // Check if store already exists.
        $existing = self::get_store_by_token( $store_token );

        if ( $existing ) {
            // Reactivate existing store.
            self::update_store( $store_token, [
                'is_connected' => 1,
                'plan'         => $plan,
                'license_limit' => self::get_limit_for_plan( $plan ),
                'store_url'    => $store_url,
            ] );

            return [
                'success'      => true,
                'store_token'  => $store_token,
                'store_secret' => $store_secret,
                'reactivated'  => true,
            ];
        }

        $table = Installer::get_stores_table();
        $now   = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $table,
            [
                'store_token'       => $store_token,
                'store_secret_hash' => hash( 'sha256', $store_secret ),
                'esl_license_id'    => $esl_license_id,
                'store_url'         => $store_url,
                'plan'              => $plan,
                'license_count'     => 0,
                'license_limit'     => self::get_limit_for_plan( $plan ),
                'is_connected'      => 1,
                'connected_at'      => $now,
                'last_seen_at'      => $now,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [
                'success' => false,
                'error'   => 'database_error',
            ];
        }

        /**
         * Fires when a new store connects to ESL Connect.
         *
         * @since 0.1.0
         *
         * @param int    $store_id       The new store record ID.
         * @param int    $esl_license_id The ESL license ID.
         * @param string $store_url      The store URL.
         * @param string $plan           The plan name.
         */
        do_action(
            'ppk_esl_connect_server_store_connected',
            $wpdb->insert_id,
            $esl_license_id,
            $store_url,
            $plan
        );

        return [
            'success'      => true,
            'store_token'  => $store_token,
            'store_secret' => $store_secret,
        ];
    }

    /**
     * Get a store by its token.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     *
     * @return object|null Store object or null if not found.
     */
    public static function get_store_by_token( string $store_token ): ?object
    {
        global $wpdb;

        $table = Installer::get_stores_table();

        $store = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE store_token = %s",
                $store_token
            )
        );

        return $store ?: null;
    }

    /**
     * Get a store by ESL license ID.
     *
     * @since 0.1.0
     *
     * @param int $esl_license_id The ESL license ID.
     *
     * @return object|null Store object or null if not found.
     */
    public static function get_store_by_license( int $esl_license_id ): ?object
    {
        global $wpdb;

        $table = Installer::get_stores_table();

        $store = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE esl_license_id = %d",
                $esl_license_id
            )
        );

        return $store ?: null;
    }

    /**
     * Update a store record.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     * @param array  $data        Data to update.
     *
     * @return bool True on success, false on failure.
     */
    public static function update_store( string $store_token, array $data ): bool
    {
        global $wpdb;

        $table = Installer::get_stores_table();

        // Add updated timestamp.
        $data['updated_at'] = current_time( 'mysql', true );

        // Build format array.
        $formats = [];
        foreach ( $data as $key => $value ) {
            if ( is_int( $value ) ) {
                $formats[] = '%d';
            } elseif ( is_float( $value ) ) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        $updated = $wpdb->update(
            $table,
            $data,
            [ 'store_token' => $store_token ],
            $formats,
            [ '%s' ]
        );

        return false !== $updated;
    }

    /**
     * Update the last seen timestamp for a store.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     */
    public static function touch_store( string $store_token ): void
    {
        global $wpdb;

        $table = Installer::get_stores_table();

        $wpdb->update(
            $table,
            [ 'last_seen_at' => current_time( 'mysql', true ) ],
            [ 'store_token' => $store_token ],
            [ '%s' ],
            [ '%s' ]
        );
    }

    /**
     * Check if license creation is allowed and reserve a slot.
     *
     * @since 0.1.0
     *
     * @param string $store_token      The store token.
     * @param string $license_key_hash Hash of the license key being created.
     * @param string $product_id       The product ID.
     *
     * @return array{allowed: bool, error?: string, message?: string, data?: array}
     */
    public static function reserve_license_slot(
        string $store_token,
        string $license_key_hash,
        string $product_id
    ): array {
        global $wpdb;

        $store = self::get_store_by_token( $store_token );

        if ( ! $store ) {
            self::log_event( $store_token, 'reserve_denied', [
                'license_key_hash' => $license_key_hash,
                'product_id'       => $product_id,
                'allowed'          => false,
                'denial_reason'    => 'store_not_found',
            ] );

            return [
                'allowed' => false,
                'error'   => 'store_not_found',
                'message' => __( 'Store not connected to ESL Connect.', 'esl-connect-server' ),
            ];
        }

        if ( ! $store->is_connected ) {
            self::log_event( $store_token, 'reserve_denied', [
                'license_key_hash' => $license_key_hash,
                'product_id'       => $product_id,
                'count_before'     => $store->license_count,
                'count_after'      => $store->license_count,
                'allowed'          => false,
                'denial_reason'    => 'store_disconnected',
            ] );

            return [
                'allowed' => false,
                'error'   => 'store_disconnected',
                'message' => __( 'Store has been disconnected from ESL Connect.', 'esl-connect-server' ),
            ];
        }

        $current_count = (int) $store->license_count;
        $limit         = $store->license_limit;

        // Check if at limit (null = unlimited).
        if ( null !== $limit && $current_count >= (int) $limit ) {
            self::log_event( $store_token, 'reserve_denied', [
                'license_key_hash' => $license_key_hash,
                'product_id'       => $product_id,
                'count_before'     => $current_count,
                'count_after'      => $current_count,
                'allowed'          => false,
                'denial_reason'    => 'license_limit_reached',
            ] );

            /**
             * Fires when a store reaches their license limit.
             *
             * @since 0.1.0
             *
             * @param string $store_token The store token.
             * @param string $plan        The store's plan.
             * @param int    $limit       The license limit.
             */
            do_action( 'ppk_esl_connect_server_limit_reached', $store_token, $store->plan, (int) $limit );

            return [
                'allowed' => false,
                'error'   => 'license_limit_reached',
                'message' => __( "You've reached your plan limit. Upgrade to continue creating licenses.", 'esl-connect-server' ),
                'data'    => [
                    'license_count' => $current_count,
                    'license_limit' => (int) $limit,
                    'remaining'     => 0,
                    'plan'          => $store->plan,
                    'upgrade_url'   => self::get_upgrade_url(),
                ],
            ];
        }

        // Reserve the slot - increment count.
        $new_count = $current_count + 1;
        $table     = Installer::get_stores_table();

        $wpdb->update(
            $table,
            [
                'license_count' => $new_count,
                'last_seen_at'  => current_time( 'mysql', true ),
            ],
            [ 'store_token' => $store_token ],
            [ '%d', '%s' ],
            [ '%s' ]
        );

        self::log_event( $store_token, 'license_reserved', [
            'license_key_hash' => $license_key_hash,
            'product_id'       => $product_id,
            'count_before'     => $current_count,
            'count_after'      => $new_count,
            'allowed'          => true,
        ] );

        /**
         * Fires when a license slot is reserved.
         *
         * @since 0.1.0
         *
         * @param string $store_token      The store token.
         * @param string $license_key_hash The license key hash.
         * @param int    $new_count        The new license count.
         */
        do_action( 'ppk_esl_connect_server_license_reserved', $store_token, $license_key_hash, $new_count );

        $remaining = null === $limit ? null : (int) $limit - $new_count;

        return [
            'allowed' => true,
            'data'    => [
                'license_count' => $new_count,
                'license_limit' => $limit,
                'remaining'     => $remaining,
                'plan'          => $store->plan,
            ],
        ];
    }

    /**
     * Release a license slot when a license is deleted.
     *
     * @since 0.1.0
     *
     * @param string $store_token      The store token.
     * @param string $license_key_hash Hash of the license key being deleted.
     *
     * @return array{success: bool, data?: array}
     */
    public static function release_license_slot( string $store_token, string $license_key_hash ): array
    {
        global $wpdb;

        $store = self::get_store_by_token( $store_token );

        if ( ! $store ) {
            return [
                'success' => false,
                'error'   => 'store_not_found',
            ];
        }

        $current_count = (int) $store->license_count;
        $new_count     = max( 0, $current_count - 1 );

        $table = Installer::get_stores_table();

        $wpdb->update(
            $table,
            [
                'license_count' => $new_count,
                'last_seen_at'  => current_time( 'mysql', true ),
                'over_limit'    => 0, // Clear over-limit flag if they delete.
            ],
            [ 'store_token' => $store_token ],
            [ '%d', '%s', '%d' ],
            [ '%s' ]
        );

        self::log_event( $store_token, 'license_released', [
            'license_key_hash' => $license_key_hash,
            'count_before'     => $current_count,
            'count_after'      => $new_count,
            'allowed'          => true,
        ] );

        /**
         * Fires when a license slot is released.
         *
         * @since 0.1.0
         *
         * @param string $store_token      The store token.
         * @param string $license_key_hash The license key hash.
         * @param int    $new_count        The new license count.
         */
        do_action( 'ppk_esl_connect_server_license_released', $store_token, $license_key_hash, $new_count );

        $limit     = $store->license_limit;
        $remaining = null === $limit ? null : (int) $limit - $new_count;

        return [
            'success' => true,
            'data'    => [
                'license_count' => $new_count,
                'license_limit' => $limit,
                'remaining'     => $remaining,
            ],
        ];
    }

    /**
     * Get connection status and plan entitlements.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public static function get_status( string $store_token ): array
    {
        $store = self::get_store_by_token( $store_token );

        if ( ! $store ) {
            return [
                'success' => false,
                'error'   => 'store_not_found',
            ];
        }

        // Update last seen.
        self::touch_store( $store_token );

        $count       = (int) $store->license_count;
        $limit       = $store->license_limit;
        $is_unlimited = null === $limit;
        $remaining   = $is_unlimited ? null : max( 0, (int) $limit - $count );
        $percent     = $is_unlimited ? 0 : round( ( $count / (int) $limit ) * 100, 1 );

        return [
            'success' => true,
            'data'    => [
                'connected'         => (bool) $store->is_connected,
                'plan'              => $store->plan,
                'license_count'     => $count,
                'license_limit'     => $limit,
                'remaining'         => $remaining,
                'usage_percent'     => $percent,
                'is_unlimited'      => $is_unlimited,
                'upgrade_available' => 'solo' === $store->plan,
                'next_plan'         => 'solo' === $store->plan ? 'studio' : null,
            ],
        ];
    }

    /**
     * Sync license count with reported count.
     *
     * Server count is always authoritative.
     *
     * @since 0.1.0
     *
     * @param string $store_token    The store token.
     * @param int    $reported_count The count reported by the client.
     *
     * @return array{success: bool, data?: array}
     */
    public static function sync( string $store_token, int $reported_count ): array
    {
        $store = self::get_store_by_token( $store_token );

        if ( ! $store ) {
            return [
                'success' => false,
                'error'   => 'store_not_found',
            ];
        }

        $server_count = (int) $store->license_count;
        $difference   = abs( $server_count - $reported_count );

        // Update last seen.
        self::touch_store( $store_token );

        // Log the sync event.
        self::log_event( $store_token, 'sync', [
            'count_before' => $reported_count,
            'count_after'  => $server_count,
            'allowed'      => true,
        ] );

        /**
         * Fires when a sync is performed.
         *
         * @since 0.1.0
         *
         * @param string $store_token    The store token.
         * @param int    $server_count   The server's count.
         * @param int    $reported_count The client's reported count.
         * @param int    $difference     The difference between counts.
         */
        do_action( 'ppk_esl_connect_server_sync', $store_token, $server_count, $reported_count, $difference );

        return [
            'success' => true,
            'data'    => [
                'server_count'   => $server_count,
                'reported_count' => $reported_count,
                'difference'     => $difference,
                'action'         => 'server_authoritative',
                'license_limit'  => $store->license_limit,
            ],
        ];
    }

    /**
     * Update store plan and limits.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     * @param string $new_plan    The new plan name.
     *
     * @return bool True on success, false on failure.
     */
    public static function update_plan( string $store_token, string $new_plan ): bool
    {
        $store = self::get_store_by_token( $store_token );

        if ( ! $store ) {
            return false;
        }

        $new_limit     = self::get_limit_for_plan( $new_plan );
        $current_count = (int) $store->license_count;

        $data = [
            'plan'          => $new_plan,
            'license_limit' => $new_limit,
        ];

        // Check if downgrade puts them over limit.
        if ( null !== $new_limit && $current_count > $new_limit ) {
            $data['over_limit']       = 1;
            $data['over_limit_count'] = $current_count - $new_limit;

            /**
             * Fires when a store is over limit after plan change.
             *
             * @since 0.1.0
             *
             * @param string $store_token   The store token.
             * @param string $new_plan      The new plan.
             * @param int    $current_count Current license count.
             * @param int    $new_limit     New license limit.
             */
            do_action(
                'ppk_esl_connect_server_store_over_limit',
                $store_token,
                $new_plan,
                $current_count,
                $new_limit
            );
        } else {
            $data['over_limit']       = 0;
            $data['over_limit_count'] = null;
        }

        return self::update_store( $store_token, $data );
    }

    /**
     * Disconnect a store.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     *
     * @return bool True on success.
     */
    public static function disconnect_store( string $store_token ): bool
    {
        $result = self::update_store( $store_token, [ 'is_connected' => 0 ] );

        if ( $result ) {
            /**
             * Fires when a store is disconnected.
             *
             * @since 0.1.0
             *
             * @param string $store_token The store token.
             */
            do_action( 'ppk_esl_connect_server_store_disconnected', $store_token );
        }

        return $result;
    }

    /**
     * Get the license limit for a plan.
     *
     * @since 0.1.0
     *
     * @param string $plan The plan name.
     *
     * @return int|null The limit or null for unlimited.
     */
    public static function get_limit_for_plan( string $plan ): ?int
    {
        /**
         * Filter the plan limits configuration.
         *
         * @since 0.1.0
         *
         * @param array $limits Associative array of plan => limit (null = unlimited).
         */
        $limits = apply_filters( 'ppk_esl_connect_server_plan_limits', self::PLAN_LIMITS );

        return $limits[ $plan ] ?? 500;
    }

    /**
     * Get the upgrade URL.
     *
     * @since 0.1.0
     *
     * @return string The upgrade URL.
     */
    private static function get_upgrade_url(): string
    {
        /**
         * Filter the upgrade URL shown to customers at limit.
         *
         * @since 0.1.0
         *
         * @param string $url The upgrade URL.
         */
        return apply_filters(
            'ppk_esl_connect_server_upgrade_url',
            'https://premiumpocket.dev/pricing/'
        );
    }

    /**
     * Log an event to the events table.
     *
     * @since 0.1.0
     *
     * @param string $store_token The store token.
     * @param string $event_type  The event type.
     * @param array  $data        Additional event data.
     */
    public static function log_event( string $store_token, string $event_type, array $data = [] ): void
    {
        global $wpdb;

        $table = Installer::get_events_table();

        $ip_address = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        $wpdb->insert(
            $table,
            [
                'store_token'      => $store_token,
                'event_type'       => $event_type,
                'license_key_hash' => $data['license_key_hash'] ?? null,
                'product_id'       => $data['product_id'] ?? null,
                'count_before'     => $data['count_before'] ?? 0,
                'count_after'      => $data['count_after'] ?? 0,
                'allowed'          => $data['allowed'] ?? 1,
                'denial_reason'    => $data['denial_reason'] ?? null,
                'ip_address'       => $ip_address,
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
        );
    }

    /**
     * Get stores list for admin display.
     *
     * @since 0.1.0
     *
     * @param array $args Query arguments.
     *
     * @return array{stores: array, total: int}
     */
    public static function get_stores_list( array $args = [] ): array
    {
        global $wpdb;

        $defaults = [
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'last_seen_at',
            'order'    => 'DESC',
            'plan'     => '',
            'search'   => '',
        ];

        $args   = wp_parse_args( $args, $defaults );
        $table  = Installer::get_stores_table();
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        // Build WHERE clause.
        $where = '1=1';
        $params = [];

        if ( ! empty( $args['plan'] ) ) {
            $where .= ' AND plan = %s';
            $params[] = $args['plan'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where .= ' AND (store_url LIKE %s OR store_name LIKE %s)';
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // Whitelist orderby columns.
        $allowed_orderby = [ 'last_seen_at', 'license_count', 'plan', 'connected_at', 'store_url' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'last_seen_at';
        $order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        // Get total count.
        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if ( ! empty( $params ) ) {
            $count_query = $wpdb->prepare( $count_query, ...$params );
        }
        $total = (int) $wpdb->get_var( $count_query );

        // Get stores.
        $query = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $args['per_page'];
        $params[] = $offset;

        $stores = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) );

        return [
            'stores' => $stores ?: [],
            'total'  => $total,
        ];
    }

    /**
     * Get health statistics.
     *
     * @since 0.1.0
     *
     * @return array Health statistics.
     */
    public static function get_health_stats(): array
    {
        global $wpdb;

        $stores_table = Installer::get_stores_table();
        $events_table = Installer::get_events_table();

        $today_start = gmdate( 'Y-m-d 00:00:00' );

        // Connected stores count.
        $connected_stores = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$stores_table} WHERE is_connected = 1"
        );

        // Total stores.
        $total_stores = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$stores_table}"
        );

        // Events today.
        $events_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$events_table} WHERE created_at >= %s",
                $today_start
            )
        );

        // Stores at limit.
        $stores_at_limit = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$stores_table} 
             WHERE is_connected = 1 
             AND license_limit IS NOT NULL 
             AND license_count >= license_limit"
        );

        // Denials today.
        $denials_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$events_table} WHERE created_at >= %s AND allowed = 0",
                $today_start
            )
        );

        return [
            'status'           => 'healthy',
            'version'          => ESL_CONNECT_SERVER_VERSION,
            'database'         => 'connected',
            'connected_stores' => $connected_stores,
            'total_stores'     => $total_stores,
            'events_today'     => $events_today,
            'stores_at_limit'  => $stores_at_limit,
            'denials_today'    => $denials_today,
            'timestamp'        => gmdate( 'c' ),
        ];
    }
}
