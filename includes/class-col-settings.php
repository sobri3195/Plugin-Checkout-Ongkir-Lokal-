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
            'cod_risk_enabled' => 'yes',
            'cod_risk_block_threshold' => 80,
            'cod_risk_review_threshold' => 60,
            'cod_risk_weights' => [
                'order_value' => 25,
                'area_distance' => 20,
                'customer_history' => 25,
                'address_quality' => 15,
                'order_time' => 15,
            ],
            'cod_risk_risky_hours' => [22, 23, 0, 1, 2, 3, 4],
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
            'cod_risk_enabled' => 'yes',
            'cod_risk_block_threshold' => 80,
            'cod_risk_review_threshold' => 60,
            'cod_risk_weights' => [
                'order_value' => 25,
                'area_distance' => 20,
                'customer_history' => 25,
                'address_quality' => 15,
                'order_time' => 15,
            ],
            'cod_risk_risky_hours' => [22, 23, 0, 1, 2, 3, 4],
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

        if (isset($_POST['col_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['col_settings_nonce'])), 'col_save_settings')) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>Pengaturan berhasil disimpan.</p></div>';
        }

        $settings = $this->all();
        $weights = $settings['cod_risk_weights'] ?? [];
        $risky_hours = implode(',', array_map('strval', $settings['cod_risk_risky_hours'] ?? []));

        echo '<div class="wrap"><h1>Checkout Ongkir Lokal</h1>';
        echo '<p>Konfigurasi modul COD Risk Scoring.</p>';
        echo '<form method="post">';
        wp_nonce_field('col_save_settings', 'col_settings_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Aktifkan COD Risk</th><td><label><input type="checkbox" name="cod_risk_enabled" value="yes" ' . checked($settings['cod_risk_enabled'] ?? 'yes', 'yes', false) . '> Aktif</label></td></tr>';
        echo '<tr><th scope="row">Threshold Blok COD</th><td><input type="number" min="0" max="100" name="cod_risk_block_threshold" value="' . esc_attr((string) ($settings['cod_risk_block_threshold'] ?? 80)) . '"></td></tr>';
        echo '<tr><th scope="row">Threshold Syarat Tambahan</th><td><input type="number" min="0" max="100" name="cod_risk_review_threshold" value="' . esc_attr((string) ($settings['cod_risk_review_threshold'] ?? 60)) . '"></td></tr>';
        echo '<tr><th scope="row">Bobot Sinyal (0-100)</th><td>';
        foreach (['order_value', 'area_distance', 'customer_history', 'address_quality', 'order_time'] as $signal) {
            echo '<p><label>' . esc_html($signal) . ': <input type="number" min="0" max="100" name="cod_risk_weights[' . esc_attr($signal) . ']" value="' . esc_attr((string) ($weights[$signal] ?? 0)) . '"></label></p>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row">Jam Rawan (0-23)</th><td><input type="text" class="regular-text" name="cod_risk_risky_hours" value="' . esc_attr($risky_hours) . '"><p class="description">Pisahkan dengan koma. Contoh: 22,23,0,1,2</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Simpan Pengaturan');
        echo '</form>';
        echo '</div>';
    }

    private function save_settings(): void
    {
        $current = $this->all();
        $current['cod_risk_enabled'] = isset($_POST['cod_risk_enabled']) ? 'yes' : 'no';
        $current['cod_risk_block_threshold'] = $this->to_score($_POST['cod_risk_block_threshold'] ?? 80);
        $current['cod_risk_review_threshold'] = $this->to_score($_POST['cod_risk_review_threshold'] ?? 60);

        $submitted_weights = isset($_POST['cod_risk_weights']) && is_array($_POST['cod_risk_weights']) ? wp_unslash($_POST['cod_risk_weights']) : [];
        $weights = [];
        foreach (['order_value', 'area_distance', 'customer_history', 'address_quality', 'order_time'] as $signal) {
            $weights[$signal] = $this->to_score($submitted_weights[$signal] ?? 0);
        }
        $current['cod_risk_weights'] = $weights;

        $risky_hours_raw = isset($_POST['cod_risk_risky_hours']) ? (string) wp_unslash($_POST['cod_risk_risky_hours']) : '';
        $hours = array_filter(array_map('trim', explode(',', $risky_hours_raw)), static fn(string $value): bool => $value !== '');
        $current['cod_risk_risky_hours'] = array_values(array_unique(array_filter(array_map(static function (string $value): int {
            return max(0, min(23, (int) $value));
        }, $hours), static fn(int $hour): bool => $hour >= 0 && $hour <= 23)));

        update_option($this->option_key, $current);
    }

    private function to_score(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }
}
