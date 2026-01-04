<?php

/**
 * Plugin Name:       Basic-Plugin
 * Plugin URI:        https://github.com/devuri/wp-basic-plugin
 * Description:       Plugin bootstrap file.
 * Version:           0.1.0
 * Requires at least: 5.3.0
 * Requires PHP:      7.3.5
 * Author:            uriel
 * Author URI:        https://github.com/devuri
 * Text Domain:       wp-basic-plugin
 * License:           GPLv2
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

// optionally require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Plugin.php';
