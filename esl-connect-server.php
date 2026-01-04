<?php
/**
 * Plugin Name: ESL Connect Server
 * Plugin URI:  https://premiumpocket.dev/plugins/esl-connect-server
 * Description: Server-side plan enforcement for Easy Software License. Runs exclusively on PremiumPocket infrastructure.
 * Version:     0.1.0
 * Author:      uriel
 * Text Domain: esl-connect-server
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package EslConnectServer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ESL_CONNECT_SERVER_VERSION', '0.1.0' );
define( 'ESL_CONNECT_SERVER_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESL_CONNECT_SERVER_URL', plugin_dir_url( __FILE__ ) );
define( 'ESL_CONNECT_SERVER_FILE', __FILE__ );

// Load classes.
require_once ESL_CONNECT_SERVER_PATH . 'src/Plugin.php';
require_once ESL_CONNECT_SERVER_PATH . 'src/Database/Installer.php';
require_once ESL_CONNECT_SERVER_PATH . 'src/Store/StoreManager.php';
require_once ESL_CONNECT_SERVER_PATH . 'src/Api/RequestValidator.php';
require_once ESL_CONNECT_SERVER_PATH . 'src/Api/ConnectController.php';
require_once ESL_CONNECT_SERVER_PATH . 'src/Integrations/EslIntegration.php';
require_once ESL_CONNECT_SERVER_PATH . 'src/Admin/AdminPage.php';

use EslConnectServer\Plugin;

// Register activation hook.
register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );

// Initialize plugin.
Plugin::init();
