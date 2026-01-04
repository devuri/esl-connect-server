<?php
declare(strict_types=1);

namespace EslConnectServer;

use EslConnectServer\Admin\AdminPage;
use EslConnectServer\Api\ConnectController;
use EslConnectServer\Database\Installer;
use EslConnectServer\Integrations\EslIntegration;

/**
 * Main plugin class.
 *
 * Orchestrates initialization of all plugin components.
 *
 * @since 0.1.0
 */
final class Plugin
{
    /**
     * Database version for migration tracking.
     */
    public const DB_VERSION = '1.0.0';

    /**
     * Option key for storing database version.
     */
    public const OPTION_DB_VERSION = 'ppk_esl_connect_server_db_version';

    /**
     * Initialize plugin – register hooks only.
     *
     * @since 0.1.0
     */
    public static function init(): void
    {
        $instance = new self();

        // Core initialization after plugins loaded.
        add_action( 'plugins_loaded', [ $instance, 'setup' ] );

        // REST API registration.
        add_action( 'rest_api_init', [ ConnectController::class, 'register_routes' ] );

        // ESL integration hooks.
        EslIntegration::init();

        // Admin page (only in admin context).
        if ( is_admin() ) {
            AdminPage::init();
        }
    }

    /**
     * Plugin activation callback.
     *
     * @since 0.1.0
     */
    public static function activate(): void
    {
        Installer::install();

        // Store current DB version.
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );

        // Clear any cached data.
        wp_cache_flush();
    }

    /**
     * Setup plugin after WordPress is loaded.
     *
     * @since 0.1.0
     */
    public function setup(): void
    {
        // Check for database updates.
        $this->maybe_update_database();

        // Load text domain.
        load_plugin_textdomain(
            'esl-connect-server',
            false,
            dirname( plugin_basename( ESL_CONNECT_SERVER_FILE ) ) . '/languages'
        );

        /**
         * Fires after ESL Connect Server is fully loaded.
         *
         * @since 0.1.0
         */
        do_action( 'ppk_esl_connect_server_loaded' );
    }

    /**
     * Check if database needs updating and run migrations.
     *
     * @since 0.1.0
     */
    private function maybe_update_database(): void
    {
        $installed_version = get_option( self::OPTION_DB_VERSION, '0.0.0' );

        if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
            Installer::install();
            update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
        }
    }
}
