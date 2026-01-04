<?php
declare(strict_types=1);

namespace EslConnectServer\Database;

/**
 * Database installer and migrator.
 *
 * Handles creation and updates of custom database tables.
 *
 * @since 0.1.0
 */
final class Installer
{
    /**
     * Stores table name (without prefix).
     */
    public const TABLE_STORES = 'esl_connect_stores';

    /**
     * Events table name (without prefix).
     */
    public const TABLE_EVENTS = 'esl_connect_events';

    /**
     * Install database tables.
     *
     * Uses dbDelta for safe table creation/updates.
     *
     * @since 0.1.0
     */
    public static function install(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $stores_table = $wpdb->prefix . self::TABLE_STORES;
        $events_table = $wpdb->prefix . self::TABLE_EVENTS;

        // SQL for stores table.
        $stores_sql = "CREATE TABLE {$stores_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_token VARCHAR(64) NOT NULL,
            store_secret_hash VARCHAR(64) NOT NULL,
            esl_license_id BIGINT UNSIGNED NOT NULL,
            store_url VARCHAR(500) NOT NULL,
            store_name VARCHAR(255) DEFAULT NULL,
            plan VARCHAR(50) NOT NULL DEFAULT 'solo',
            license_count INT UNSIGNED NOT NULL DEFAULT 0,
            license_limit INT UNSIGNED DEFAULT NULL,
            is_connected TINYINT(1) NOT NULL DEFAULT 1,
            over_limit TINYINT(1) NOT NULL DEFAULT 0,
            over_limit_count INT UNSIGNED DEFAULT NULL,
            connected_at DATETIME DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY store_token (store_token),
            KEY esl_license_id (esl_license_id),
            KEY plan (plan),
            KEY is_connected (is_connected),
            KEY last_seen_at (last_seen_at)
        ) {$charset_collate};";

        // SQL for events table.
        $events_sql = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_token VARCHAR(64) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            license_key_hash VARCHAR(64) DEFAULT NULL,
            product_id VARCHAR(100) DEFAULT NULL,
            count_before INT UNSIGNED NOT NULL DEFAULT 0,
            count_after INT UNSIGNED NOT NULL DEFAULT 0,
            allowed TINYINT(1) NOT NULL DEFAULT 1,
            denial_reason VARCHAR(255) DEFAULT NULL,
            request_data TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY store_token (store_token),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY allowed (allowed)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $stores_sql );
        dbDelta( $events_sql );
    }

    /**
     * Get the full stores table name with prefix.
     *
     * @since 0.1.0
     *
     * @return string Full table name.
     */
    public static function get_stores_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_STORES;
    }

    /**
     * Get the full events table name with prefix.
     *
     * @since 0.1.0
     *
     * @return string Full table name.
     */
    public static function get_events_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_EVENTS;
    }

    /**
     * Uninstall database tables.
     *
     * Called on plugin uninstall (if user chooses to remove data).
     *
     * @since 0.1.0
     */
    public static function uninstall(): void
    {
        global $wpdb;

        $stores_table = self::get_stores_table();
        $events_table = self::get_events_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are constants.
        $wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are constants.
        $wpdb->query( "DROP TABLE IF EXISTS {$stores_table}" );

        // Remove options.
        delete_option( \EslConnectServer\Plugin::OPTION_DB_VERSION );
    }
}
