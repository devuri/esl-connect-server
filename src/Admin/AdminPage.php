<?php
declare(strict_types=1);

namespace EslConnectServer\Admin;

use EslConnectServer\Store\StoreManager;

/**
 * Admin page for ESL Connect Server.
 *
 * Displays connected stores and health metrics.
 *
 * @since 0.1.0
 */
final class AdminPage
{
    /**
     * Menu slug.
     */
    private const MENU_SLUG = 'esl-connect-server';

    /**
     * Initialize admin page.
     *
     * @since 0.1.0
     */
    public static function init(): void
    {
        $instance = new self();

        add_action( 'admin_menu', [ $instance, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $instance, 'enqueue_styles' ] );
    }

    /**
     * Register admin menu.
     *
     * @since 0.1.0
     */
    public function register_menu(): void
    {
        add_menu_page(
            __( 'ESL Connect', 'esl-connect-server' ),
            __( 'ESL Connect', 'esl-connect-server' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ],
            'dashicons-cloud',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Connected Stores', 'esl-connect-server' ),
            __( 'Stores', 'esl-connect-server' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Health & Stats', 'esl-connect-server' ),
            __( 'Health', 'esl-connect-server' ),
            'manage_options',
            self::MENU_SLUG . '-health',
            [ $this, 'render_health_page' ]
        );
    }

    /**
     * Enqueue admin styles.
     *
     * @since 0.1.0
     *
     * @param string $hook_suffix The current admin page hook.
     */
    public function enqueue_styles( string $hook_suffix ): void
    {
        if ( false === strpos( $hook_suffix, self::MENU_SLUG ) ) {
            return;
        }

        // Inline styles for admin pages.
        wp_add_inline_style( 'wp-admin', $this->get_inline_styles() );
    }

    /**
     * Render the main admin page (stores list).
     *
     * @since 0.1.0
     */
    public function render_page(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'esl-connect-server' ) );
        }

        // Get pagination parameters.
        $page     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $plan     = isset( $_GET['plan'] ) ? sanitize_text_field( wp_unslash( $_GET['plan'] ) ) : '';
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $per_page = 20;

        $result = StoreManager::get_stores_list( [
            'page'     => $page,
            'per_page' => $per_page,
            'plan'     => $plan,
            'search'   => $search,
        ] );

        $stores      = $result['stores'];
        $total       = $result['total'];
        $total_pages = (int) ceil( $total / $per_page );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Connected Stores', 'esl-connect-server' ); ?>
            </h1>

            <?php $this->render_stats_summary(); ?>

            <form method="get" class="esl-connect-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">

                <select name="plan">
                    <option value=""><?php esc_html_e( 'All Plans', 'esl-connect-server' ); ?></option>
                    <option value="solo" <?php selected( $plan, 'solo' ); ?>><?php esc_html_e( 'Solo', 'esl-connect-server' ); ?></option>
                    <option value="studio" <?php selected( $plan, 'studio' ); ?>><?php esc_html_e( 'Studio', 'esl-connect-server' ); ?></option>
                    <option value="agency" <?php selected( $plan, 'agency' ); ?>><?php esc_html_e( 'Agency', 'esl-connect-server' ); ?></option>
                </select>

                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search stores...', 'esl-connect-server' ); ?>">

                <?php submit_button( __( 'Filter', 'esl-connect-server' ), 'secondary', 'submit', false ); ?>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" class="column-token"><?php esc_html_e( 'Token', 'esl-connect-server' ); ?></th>
                        <th scope="col" class="column-url"><?php esc_html_e( 'Store URL', 'esl-connect-server' ); ?></th>
                        <th scope="col" class="column-plan"><?php esc_html_e( 'Plan', 'esl-connect-server' ); ?></th>
                        <th scope="col" class="column-usage"><?php esc_html_e( 'Usage', 'esl-connect-server' ); ?></th>
                        <th scope="col" class="column-status"><?php esc_html_e( 'Status', 'esl-connect-server' ); ?></th>
                        <th scope="col" class="column-last-seen"><?php esc_html_e( 'Last Seen', 'esl-connect-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $stores ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No connected stores found.', 'esl-connect-server' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $stores as $store ) : ?>
                            <?php $this->render_store_row( $store ); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $this->render_pagination( $page, $total_pages, $total ); ?>
        </div>
        <?php
    }

    /**
     * Render a store row.
     *
     * @since 0.1.0
     *
     * @param object $store The store object.
     */
    private function render_store_row( object $store ): void
    {
        $token_display = substr( $store->store_token, 0, 8 ) . '...';
        $is_unlimited  = null === $store->license_limit;
        $usage_percent = $is_unlimited ? 0 : round( ( (int) $store->license_count / (int) $store->license_limit ) * 100 );
        $is_at_limit   = ! $is_unlimited && $usage_percent >= 100;
        $is_warning    = ! $is_unlimited && $usage_percent >= 80 && $usage_percent < 100;

        $status_class = $store->is_connected ? 'status-connected' : 'status-disconnected';
        $status_label = $store->is_connected
            ? __( 'Connected', 'esl-connect-server' )
            : __( 'Disconnected', 'esl-connect-server' );

        ?>
        <tr>
            <td class="column-token">
                <code title="<?php echo esc_attr( $store->store_token ); ?>"><?php echo esc_html( $token_display ); ?></code>
            </td>
            <td class="column-url">
                <a href="<?php echo esc_url( $store->store_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html( $store->store_url ); ?>
                </a>
            </td>
            <td class="column-plan">
                <span class="plan-badge plan-<?php echo esc_attr( $store->plan ); ?>">
                    <?php echo esc_html( ucfirst( $store->plan ) ); ?>
                </span>
            </td>
            <td class="column-usage">
                <?php if ( $is_unlimited ) : ?>
                    <strong><?php echo esc_html( number_format( (int) $store->license_count ) ); ?></strong>
                    <span class="usage-unlimited"><?php esc_html_e( 'Unlimited', 'esl-connect-server' ); ?></span>
                <?php else : ?>
                    <strong><?php echo esc_html( number_format( (int) $store->license_count ) ); ?></strong>
                    / <?php echo esc_html( number_format( (int) $store->license_limit ) ); ?>
                    <span class="usage-percent <?php echo $is_at_limit ? 'at-limit' : ( $is_warning ? 'warning' : '' ); ?>">
                        (<?php echo esc_html( $usage_percent ); ?>%)
                    </span>
                <?php endif; ?>
            </td>
            <td class="column-status">
                <span class="status-badge <?php echo esc_attr( $status_class ); ?>">
                    <?php echo esc_html( $status_label ); ?>
                </span>
                <?php if ( $store->over_limit ) : ?>
                    <span class="status-badge status-over-limit" title="<?php esc_attr_e( 'Over limit after downgrade', 'esl-connect-server' ); ?>">
                        <?php esc_html_e( 'Over Limit', 'esl-connect-server' ); ?>
                    </span>
                <?php endif; ?>
            </td>
            <td class="column-last-seen">
                <?php if ( $store->last_seen_at ) : ?>
                    <span title="<?php echo esc_attr( $store->last_seen_at ); ?>">
                        <?php echo esc_html( human_time_diff( strtotime( $store->last_seen_at ) ) ); ?>
                        <?php esc_html_e( 'ago', 'esl-connect-server' ); ?>
                    </span>
                <?php else : ?>
                    <span class="never-seen"><?php esc_html_e( 'Never', 'esl-connect-server' ); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render stats summary.
     *
     * @since 0.1.0
     */
    private function render_stats_summary(): void
    {
        $stats = StoreManager::get_health_stats();

        ?>
        <div class="esl-connect-stats">
            <div class="stat-box">
                <span class="stat-value"><?php echo esc_html( number_format( $stats['connected_stores'] ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Connected Stores', 'esl-connect-server' ); ?></span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo esc_html( number_format( $stats['events_today'] ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Requests Today', 'esl-connect-server' ); ?></span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo esc_html( number_format( $stats['stores_at_limit'] ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'At Limit', 'esl-connect-server' ); ?></span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo esc_html( number_format( $stats['denials_today'] ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Denials Today', 'esl-connect-server' ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render pagination.
     *
     * @since 0.1.0
     *
     * @param int $current_page Current page number.
     * @param int $total_pages  Total pages.
     * @param int $total_items  Total items.
     */
    private function render_pagination( int $current_page, int $total_pages, int $total_items ): void
    {
        if ( $total_pages <= 1 ) {
            return;
        }

        $base_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );

        // Preserve existing query params.
        $query_args = [];

        if ( isset( $_GET['plan'] ) ) {
            $query_args['plan'] = sanitize_text_field( wp_unslash( $_GET['plan'] ) );
        }

        if ( isset( $_GET['s'] ) ) {
            $query_args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }

        ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    printf(
                        /* translators: %s: Number of items */
                        esc_html( _n( '%s item', '%s items', $total_items, 'esl-connect-server' ) ),
                        esc_html( number_format_i18n( $total_items ) )
                    );
                    ?>
                </span>
                <span class="pagination-links">
                    <?php if ( $current_page > 1 ) : ?>
                        <a class="prev-page button" href="<?php echo esc_url( add_query_arg( array_merge( $query_args, [ 'paged' => $current_page - 1 ] ), $base_url ) ); ?>">
                            <span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'esl-connect-server' ); ?></span>
                            <span aria-hidden="true">‹</span>
                        </a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <span class="tablenav-paging-text">
                            <?php echo esc_html( $current_page ); ?>
                            <?php esc_html_e( 'of', 'esl-connect-server' ); ?>
                            <span class="total-pages"><?php echo esc_html( $total_pages ); ?></span>
                        </span>
                    </span>

                    <?php if ( $current_page < $total_pages ) : ?>
                        <a class="next-page button" href="<?php echo esc_url( add_query_arg( array_merge( $query_args, [ 'paged' => $current_page + 1 ] ), $base_url ) ); ?>">
                            <span class="screen-reader-text"><?php esc_html_e( 'Next page', 'esl-connect-server' ); ?></span>
                            <span aria-hidden="true">›</span>
                        </a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Render health page.
     *
     * @since 0.1.0
     */
    public function render_health_page(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'esl-connect-server' ) );
        }

        $stats = StoreManager::get_health_stats();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ESL Connect Health', 'esl-connect-server' ); ?></h1>

            <div class="esl-connect-health">
                <div class="health-status health-<?php echo esc_attr( $stats['status'] ); ?>">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php echo esc_html( ucfirst( $stats['status'] ) ); ?>
                </div>

                <table class="widefat" style="max-width: 600px;">
                    <tbody>
                        <tr>
                            <th><?php esc_html_e( 'Version', 'esl-connect-server' ); ?></th>
                            <td><code><?php echo esc_html( $stats['version'] ); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Database', 'esl-connect-server' ); ?></th>
                            <td><?php echo esc_html( ucfirst( $stats['database'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Total Stores', 'esl-connect-server' ); ?></th>
                            <td><?php echo esc_html( number_format( $stats['total_stores'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Connected Stores', 'esl-connect-server' ); ?></th>
                            <td><?php echo esc_html( number_format( $stats['connected_stores'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Stores at Limit', 'esl-connect-server' ); ?></th>
                            <td><?php echo esc_html( number_format( $stats['stores_at_limit'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Events Today', 'esl-connect-server' ); ?></th>
                            <td><?php echo esc_html( number_format( $stats['events_today'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Denials Today', 'esl-connect-server' ); ?></th>
                            <td><?php echo esc_html( number_format( $stats['denials_today'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Timestamp', 'esl-connect-server' ); ?></th>
                            <td><code><?php echo esc_html( $stats['timestamp'] ); ?></code></td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e( 'API Endpoint', 'esl-connect-server' ); ?></h2>
                <p>
                    <code><?php echo esc_url( rest_url( 'esl-connect/v1/health' ) ); ?></code>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Get inline styles for admin pages.
     *
     * @since 0.1.0
     *
     * @return string CSS styles.
     */
    private function get_inline_styles(): string
    {
        return '
            .esl-connect-stats {
                display: flex;
                gap: 20px;
                margin: 20px 0;
            }
            .esl-connect-stats .stat-box {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 15px 25px;
                text-align: center;
            }
            .esl-connect-stats .stat-value {
                display: block;
                font-size: 28px;
                font-weight: 600;
                color: #1d2327;
            }
            .esl-connect-stats .stat-label {
                display: block;
                font-size: 13px;
                color: #50575e;
                margin-top: 5px;
            }
            .esl-connect-filters {
                margin: 15px 0;
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .plan-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .plan-solo { background: #e7f3ff; color: #0073aa; }
            .plan-studio { background: #e7f6e7; color: #00a32a; }
            .plan-agency { background: #f0e7ff; color: #7c3aed; }
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
            }
            .status-connected { background: #d4edda; color: #155724; }
            .status-disconnected { background: #f8d7da; color: #721c24; }
            .status-over-limit { background: #fff3cd; color: #856404; margin-left: 5px; }
            .usage-unlimited { color: #00a32a; font-size: 12px; margin-left: 5px; }
            .usage-percent { font-size: 12px; color: #50575e; }
            .usage-percent.warning { color: #dba617; }
            .usage-percent.at-limit { color: #d63638; font-weight: 600; }
            .never-seen { color: #999; font-style: italic; }
            .esl-connect-health .health-status {
                font-size: 24px;
                margin: 20px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .esl-connect-health .health-healthy { color: #00a32a; }
            .esl-connect-health .health-healthy .dashicons { color: #00a32a; }
        ';
    }
}
