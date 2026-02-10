<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Settings
{
    private string $option_key = 'col_settings';

    public function ensure_defaults(): void
    {
        $defaults = [
            'provider' => 'rajaongkir',
            'api_key' => '',
            'origin_type' => 'district',
            'origin_value' => '',
            'cache_ttl_seconds' => 900,
            'request_timeout_seconds' => 7,
            'retry_count' => 1,
            'anti_down_mode' => 'fallback_then_flat',
            'flat_rate_backup' => 18000,
            'stale_max_age_minutes' => 720,
            'enabled_couriers' => ['jne', 'jnt', 'anteraja'],
            'shipment_strategy' => 'balanced',
        ];

        add_option($this->option_key, $defaults);
    }

    public function all(): array
    {
        $saved = get_option($this->option_key, []);

        return wp_parse_args($saved, [
            'provider' => 'rajaongkir',
            'cache_ttl_seconds' => 900,
            'request_timeout_seconds' => 7,
            'retry_count' => 1,
            'anti_down_mode' => 'fallback_then_flat',
            'flat_rate_backup' => 18000,
            'stale_max_age_minutes' => 720,
            'enabled_couriers' => ['jne', 'jnt', 'anteraja'],
            'shipment_strategy' => 'balanced',
        ]);
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            __('Checkout Ongkir Lokal', 'checkout-ongkir-lokal'),
            __('Ongkir Lokal', 'checkout-ongkir-lokal'),
            'manage_woocommerce',
            'checkout-ongkir-lokal',
            [$this, 'render_settings_page'],
            'dashicons-location-alt',
            56
        );
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<div class="wrap"><h1>Checkout Ongkir Lokal</h1>';
        echo '<p>Halaman UI admin untuk provider, cache TTL, COD builder, surcharge builder, override kecamatan, dan log filter disiapkan pada milestone V1.</p>';
        echo '</div>';
    }
}
