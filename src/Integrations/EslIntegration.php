<?php
declare(strict_types=1);

namespace EslConnectServer\Integrations;

use EslConnectServer\Store\StoreManager;

/**
 * ESL and EDD integration hooks.
 *
 * Connects ESL Connect to the existing ESL license system and EDD subscriptions.
 *
 * @since 0.1.0
 */
final class EslIntegration
{
    /**
     * ESL product ID (the ESL plugin product).
     *
     * This should be configured via filter or constant.
     */
    private const ESL_PRODUCT_ID = 0;

    /**
     * Initialize integration hooks.
     *
     * @since 0.1.0
     */
    public static function init(): void
    {
        $instance = new self();

        // Hook into ESL license activation.
        add_action( 'esl_license_activated', [ $instance, 'on_license_activated' ], 10, 3 );

        // Hook into ESL license deactivation.
        add_action( 'esl_license_deactivated', [ $instance, 'on_license_deactivated' ], 10, 2 );

        // Filter to add Connect data to activation response.
        add_filter( 'esl_activation_response', [ $instance, 'add_connect_to_response' ], 10, 2 );

        // EDD subscription hooks.
        add_action( 'edd_subscription_status_change', [ $instance, 'on_subscription_status_change' ], 10, 3 );

        // EDD Recurring - subscription upgraded/downgraded.
        add_action( 'edd_recurring_post_record_signup', [ $instance, 'on_subscription_created' ], 10, 2 );

        // Plan change hooks (if using custom upgrade flow).
        add_action( 'edd_subscription_upgraded', [ $instance, 'on_subscription_upgraded' ], 10, 4 );
        add_action( 'edd_subscription_downgraded', [ $instance, 'on_subscription_downgraded' ], 10, 4 );
    }

    /**
     * Handle ESL license activation.
     *
     * Creates a connected store record when an ESL license is activated.
     *
     * @since 0.1.0
     *
     * @param int    $license_id The ESL license ID.
     * @param string $site_url   The site URL being activated.
     * @param object $license    The license object.
     */
    public function on_license_activated( int $license_id, string $site_url, object $license ): void
    {
        // Only process for ESL product licenses.
        if ( ! $this->is_esl_product( $license ) ) {
            return;
        }

        // Determine plan from license/order.
        $plan = $this->determine_plan_from_license( $license );

        // Create or reactivate store.
        StoreManager::create_store( [
            'esl_license_id' => $license_id,
            'license_key'    => $license->license_key,
            'store_url'      => $site_url,
            'plan'           => $plan,
        ] );
    }

    /**
     * Handle ESL license deactivation.
     *
     * @since 0.1.0
     *
     * @param int    $license_id The license ID.
     * @param string $site_url   The site URL being deactivated.
     */
    public function on_license_deactivated( int $license_id, string $site_url ): void
    {
        // Get the store by license ID.
        $store = StoreManager::get_store_by_license( $license_id );

        if ( $store ) {
            StoreManager::disconnect_store( $store->store_token );
        }
    }

    /**
     * Add Connect credentials to activation response.
     *
     * @since 0.1.0
     *
     * @param array  $response The activation response.
     * @param object $license  The license object.
     *
     * @return array Modified response with Connect data.
     */
    public function add_connect_to_response( array $response, object $license ): array
    {
        // Only add Connect data for ESL product licenses.
        if ( ! $this->is_esl_product( $license ) ) {
            return $response;
        }

        // Generate credentials (same derivation as StoreManager::create_store).
        $store_token  = hash( 'sha256', $license->license_key . ':connect' );
        $store_secret = hash( 'sha256', $license->license_key . ':secret' );

        // For signature verification, we use the hash of the secret.
        // Client stores: store_secret
        // Client uses for HMAC: hash(store_secret) = store_secret_hash
        // We verify against: stored store_secret_hash
        $store_secret_hash = hash( 'sha256', $store_secret );

        // Get plan info.
        $plan  = $this->determine_plan_from_license( $license );
        $limit = StoreManager::get_limit_for_plan( $plan );

        $response['data']['connect'] = [
            'enabled'      => true,
            'store_token'  => $store_token,
            'store_secret' => $store_secret_hash, // Client uses this for HMAC.
            'plan'         => $plan,
            'license_limit' => $limit,
            'api_base'     => rest_url( 'esl-connect/v1' ),
        ];

        return $response;
    }

    /**
     * Handle subscription status change.
     *
     * @since 0.1.0
     *
     * @param string $old_status The old status.
     * @param string $new_status The new status.
     * @param object $subscription The subscription object.
     */
    public function on_subscription_status_change( string $old_status, string $new_status, object $subscription ): void
    {
        // Only process for ESL product subscriptions.
        if ( ! $this->is_esl_subscription( $subscription ) ) {
            return;
        }

        $license = $this->get_license_for_subscription( $subscription );

        if ( ! $license ) {
            return;
        }

        $store = StoreManager::get_store_by_license( (int) $license->id );

        if ( ! $store ) {
            return;
        }

        switch ( $new_status ) {
            case 'cancelled':
            case 'expired':
            case 'failing':
                StoreManager::disconnect_store( $store->store_token );
                break;

            case 'active':
                if ( ! $store->is_connected ) {
                    StoreManager::update_store( $store->store_token, [ 'is_connected' => 1 ] );
                }
                break;
        }
    }

    /**
     * Handle new subscription creation.
     *
     * @since 0.1.0
     *
     * @param object $subscription The subscription.
     * @param array  $args         Subscription arguments.
     */
    public function on_subscription_created( object $subscription, array $args ): void
    {
        // Only process for ESL product subscriptions.
        if ( ! $this->is_esl_subscription( $subscription ) ) {
            return;
        }

        $license = $this->get_license_for_subscription( $subscription );

        if ( ! $license ) {
            return;
        }

        $plan = $this->determine_plan_from_subscription( $subscription );

        // Store will be created when license is activated.
        // This hook is here for future use if we need to pre-create records.
    }

    /**
     * Handle subscription upgrade.
     *
     * @since 0.1.0
     *
     * @param int    $subscription_id Old subscription ID.
     * @param int    $new_subscription_id New subscription ID.
     * @param object $subscription The subscription object.
     * @param int    $price_id The new price ID.
     */
    public function on_subscription_upgraded( int $subscription_id, int $new_subscription_id, object $subscription, int $price_id ): void
    {
        $this->handle_plan_change( $subscription, $price_id );
    }

    /**
     * Handle subscription downgrade.
     *
     * @since 0.1.0
     *
     * @param int    $subscription_id Old subscription ID.
     * @param int    $new_subscription_id New subscription ID.
     * @param object $subscription The subscription object.
     * @param int    $price_id The new price ID.
     */
    public function on_subscription_downgraded( int $subscription_id, int $new_subscription_id, object $subscription, int $price_id ): void
    {
        $this->handle_plan_change( $subscription, $price_id );
    }

    /**
     * Handle plan change (upgrade or downgrade).
     *
     * @since 0.1.0
     *
     * @param object $subscription The subscription.
     * @param int    $price_id     The new price ID.
     */
    private function handle_plan_change( object $subscription, int $price_id ): void
    {
        if ( ! $this->is_esl_subscription( $subscription ) ) {
            return;
        }

        $license = $this->get_license_for_subscription( $subscription );

        if ( ! $license ) {
            return;
        }

        $store = StoreManager::get_store_by_license( (int) $license->id );

        if ( ! $store ) {
            return;
        }

        $new_plan = $this->get_plan_from_price_id( $price_id );

        StoreManager::update_plan( $store->store_token, $new_plan );
    }

    /**
     * Check if license is for ESL product.
     *
     * @since 0.1.0
     *
     * @param object $license The license object.
     *
     * @return bool True if ESL product.
     */
    private function is_esl_product( object $license ): bool
    {
        /**
         * Filter the ESL product ID.
         *
         * @since 0.1.0
         *
         * @param int $product_id The ESL product ID in EDD.
         */
        $esl_product_id = (int) apply_filters( 'ppk_esl_connect_server_esl_product_id', self::ESL_PRODUCT_ID );

        if ( 0 === $esl_product_id ) {
            // If not configured, assume all licenses are ESL (for testing).
            return true;
        }

        return (int) $license->product_id === $esl_product_id;
    }

    /**
     * Check if subscription is for ESL product.
     *
     * @since 0.1.0
     *
     * @param object $subscription The subscription object.
     *
     * @return bool True if ESL subscription.
     */
    private function is_esl_subscription( object $subscription ): bool
    {
        /**
         * Filter the ESL product ID.
         *
         * @since 0.1.0
         *
         * @param int $product_id The ESL product ID in EDD.
         */
        $esl_product_id = (int) apply_filters( 'ppk_esl_connect_server_esl_product_id', self::ESL_PRODUCT_ID );

        if ( 0 === $esl_product_id ) {
            return true;
        }

        return (int) $subscription->product_id === $esl_product_id;
    }

    /**
     * Determine plan from license data.
     *
     * @since 0.1.0
     *
     * @param object $license The license object.
     *
     * @return string Plan name.
     */
    private function determine_plan_from_license( object $license ): string
    {
        // Try to get price_id from order meta or license.
        $price_id = null;

        if ( isset( $license->price_id ) ) {
            $price_id = (int) $license->price_id;
        } elseif ( ! empty( $license->order_id ) && function_exists( 'edd_get_payment_meta' ) ) {
            $cart_details = edd_get_payment_meta( (int) $license->order_id, 'cart_details', true );

            if ( is_array( $cart_details ) ) {
                foreach ( $cart_details as $item ) {
                    if ( (int) $item['id'] === (int) $license->product_id ) {
                        $price_id = (int) ( $item['item_number']['options']['price_id'] ?? 0 );
                        break;
                    }
                }
            }
        }

        return $this->get_plan_from_price_id( $price_id );
    }

    /**
     * Determine plan from subscription.
     *
     * @since 0.1.0
     *
     * @param object $subscription The subscription object.
     *
     * @return string Plan name.
     */
    private function determine_plan_from_subscription( object $subscription ): string
    {
        $price_id = isset( $subscription->price_id ) ? (int) $subscription->price_id : null;

        return $this->get_plan_from_price_id( $price_id );
    }

    /**
     * Map price ID to plan name.
     *
     * @since 0.1.0
     *
     * @param int|null $price_id The EDD price ID.
     *
     * @return string Plan name.
     */
    private function get_plan_from_price_id( ?int $price_id ): string
    {
        /**
         * Filter the price ID to plan mapping.
         *
         * @since 0.1.0
         *
         * @param array $map Associative array of price_id => plan name.
         */
        $price_map = apply_filters(
            'ppk_esl_connect_server_price_plan_map',
            [
                1 => 'solo',
                2 => 'studio',
                3 => 'agency',
            ]
        );

        return $price_map[ $price_id ] ?? 'solo';
    }

    /**
     * Get license for a subscription.
     *
     * @since 0.1.0
     *
     * @param object $subscription The subscription.
     *
     * @return object|null License object or null.
     */
    private function get_license_for_subscription( object $subscription ): ?object
    {
        if ( ! class_exists( '\LicenseServer\License' ) ) {
            return null;
        }

        // Try to find license by subscription's parent payment.
        $parent_payment_id = $subscription->parent_payment_id ?? 0;

        if ( ! $parent_payment_id ) {
            return null;
        }

        global $wpdb;

        // Query ESL licenses table for license matching the order.
        $table = $wpdb->prefix . 'sftw_licenses';

        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %s LIMIT 1",
                (string) $parent_payment_id
            )
        );

        return $license ?: null;
    }
}
