<?php
/**
 * Plugin Name: TCW Content Assistant
 * Description: Topic queue, AI-assisted article drafting, and optional featured image support for editorial workflows.
 * Version: 1.0.5
 * Author: OpenAI
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: tcw-content-assistant
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TCW_CA_VERSION', '1.0.5');
define('TCW_CA_FILE', __FILE__);
define('TCW_CA_DIR', plugin_dir_path(__FILE__));
define('TCW_CA_URL', plugin_dir_url(__FILE__));

require_once TCW_CA_DIR . 'includes/class-tcw-ca-plugin.php';

function tcw_ca() {
    return TCW_CA_Plugin::instance();
}

register_activation_hook(__FILE__, array('TCW_CA_Plugin', 'activate'));

add_action('plugins_loaded', 'tcw_ca');
