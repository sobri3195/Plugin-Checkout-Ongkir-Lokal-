<?php
/**
 * Plugin Name: Checkout Ongkir Lokal
 * Description: Integrasi ongkir lokal Indonesia untuk WooCommerce dengan COD Rules, anti-down mode, cache, logging, dan override kecamatan.
 * Version: 0.1.0
 * Author: Checkout Ongkir Lokal Team
 * Text Domain: checkout-ongkir-lokal
 */

if (! defined('ABSPATH')) {
    exit;
}

define('COL_PLUGIN_FILE', __FILE__);
define('COL_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('COL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COL_VERSION', '0.1.0');

require_once COL_PLUGIN_PATH . 'includes/class-col-plugin.php';

add_action('plugins_loaded', static function () {
    if (! class_exists('WooCommerce')) {
        return;
    }

    COL_Plugin::instance()->init();
});
