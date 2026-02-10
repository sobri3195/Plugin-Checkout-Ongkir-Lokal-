<?php
/**
 * Theme setup for Checkout Ongkir Lokal Companion.
 *
 * @package Checkout_Ongkir_Lokal_Companion
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('woocommerce');
});

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'checkout-ongkir-lokal-companion-style',
        get_stylesheet_uri(),
        [],
        wp_get_theme()->get('Version')
    );
});

add_action('admin_notices', static function (): void {
    if (! current_user_can('activate_plugins')) {
        return;
    }

    if (is_plugin_active('checkout-ongkir-lokal/checkout-ongkir-lokal.php')) {
        return;
    }

    echo '<div class="notice notice-warning"><p>';
    echo esc_html__('Checkout Ongkir Lokal plugin belum aktif. Aktifkan plugin agar fitur ongkir lokal berjalan.', 'checkout-ongkir-lokal-companion');
    echo '</p></div>';
});

if (! function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
