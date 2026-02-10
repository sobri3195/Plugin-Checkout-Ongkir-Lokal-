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
            'fallback_dimensions_cm' => [
                'length' => 10,
                'width' => 10,
                'height' => 10,
            ],
            'box_presets' => [
                [
                    'id' => 'small',
                    'name' => 'Small Box',
                    'inner_length_cm' => 20,
                    'inner_width_cm' => 20,
                    'inner_height_cm' => 20,
                    'max_weight_gram' => 5000,
                ],
                [
                    'id' => 'medium',
                    'name' => 'Medium Box',
                    'inner_length_cm' => 30,
                    'inner_width_cm' => 30,
                    'inner_height_cm' => 30,
                    'max_weight_gram' => 15000,
                ],
                [
                    'id' => 'large',
                    'name' => 'Large Box',
                    'inner_length_cm' => 40,
                    'inner_width_cm' => 40,
                    'inner_height_cm' => 40,
                    'max_weight_gram' => 30000,
                ],
            ],
            'volumetric_divisors' => [
                'jne' => 6000,
                'jnt' => 6000,
                'anteraja' => 5000,
                'default' => 6000,
            ],
            'smart_shipping_weights' => [
                'price' => 35,
                'eta' => 25,
                'reliability' => 20,
                'margin_impact' => 20,
            ],
            'courier_reliability' => [
                'jne' => 85,
                'jnt' => 78,
                'anteraja' => 80,
                'backup' => 60,
                'default' => 70,
            ],
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
            'fallback_dimensions_cm' => [
                'length' => 10,
                'width' => 10,
                'height' => 10,
            ],
            'box_presets' => [
                [
                    'id' => 'small',
                    'name' => 'Small Box',
                    'inner_length_cm' => 20,
                    'inner_width_cm' => 20,
                    'inner_height_cm' => 20,
                    'max_weight_gram' => 5000,
                ],
                [
                    'id' => 'medium',
                    'name' => 'Medium Box',
                    'inner_length_cm' => 30,
                    'inner_width_cm' => 30,
                    'inner_height_cm' => 30,
                    'max_weight_gram' => 15000,
                ],
                [
                    'id' => 'large',
                    'name' => 'Large Box',
                    'inner_length_cm' => 40,
                    'inner_width_cm' => 40,
                    'inner_height_cm' => 40,
                    'max_weight_gram' => 30000,
                ],
            ],
            'volumetric_divisors' => [
                'jne' => 6000,
                'jnt' => 6000,
                'anteraja' => 5000,
                'default' => 6000,
            ],
            'reconciliation_report_period_days' => 7,
            'reconciliation_variance_threshold' => 5000,
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

        add_submenu_page(
            'checkout-ongkir-lokal',
            __('Smart Shipping Dashboard', 'checkout-ongkir-lokal'),
            __('Smart Shipping Dashboard', 'checkout-ongkir-lokal'),
            'manage_woocommerce',
            'checkout-ongkir-lokal-dashboard',
            [$this, 'render_smart_shipping_dashboard']
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
        echo '<tr><th scope="row">Threshold Alert Anomali (%)</th><td><input type="number" min="1" max="500" name="observability_alert_threshold_pct" value="' . esc_attr((string) ($settings['observability_alert_threshold_pct'] ?? 35)) . '"></td></tr>';
        echo '<tr><th scope="row">Email Notifikasi</th><td><input type="email" class="regular-text" name="observability_alert_email" value="' . esc_attr((string) ($settings['observability_alert_email'] ?? '')) . '"></td></tr>';
        echo '<tr><th scope="row">Slack Webhook</th><td><input type="url" class="regular-text" name="observability_slack_webhook" value="' . esc_attr((string) ($settings['observability_slack_webhook'] ?? '')) . '"><p class="description">Opsional: incoming webhook URL untuk alert anomali.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Simpan Pengaturan');
        echo '</form>';
        echo '</div>';
    }

    public function render_smart_shipping_dashboard(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $metrics = $this->get_smart_shipping_metrics();

        echo '<div class="wrap"><h1>Smart Shipping Recommendation Dashboard</h1>';
        echo '<p>Performa eksperimen A/B mode tanpa rekomendasi vs dengan rekomendasi.</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Variant</th><th>Checkout Exposure</th><th>Orders</th><th>Conversion Rate</th><th>Average Shipping Cost</th></tr></thead><tbody>';

        foreach ($metrics as $variant => $row) {
            echo '<tr>';
            echo '<td>' . esc_html($variant) . '</td>';
            echo '<td>' . esc_html((string) $row['exposure']) . '</td>';
            echo '<td>' . esc_html((string) $row['orders']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row['conversion_rate'], 2)) . '%</td>';
            echo '<td>Rp ' . esc_html(number_format_i18n((float) $row['avg_shipping_cost'], 0)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function get_smart_shipping_metrics(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'col_logs';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, context_json FROM {$table} WHERE event_type IN (%s, %s) ORDER BY id DESC LIMIT 5000",
            'checkout_started',
            'shipping_method_selected'
        ), ARRAY_A);

        $metrics = [
            'without_recommendation' => ['exposure' => 0, 'orders' => 0, 'shipping_total' => 0.0, 'avg_shipping_cost' => 0.0, 'conversion_rate' => 0.0],
            'with_recommendation' => ['exposure' => 0, 'orders' => 0, 'shipping_total' => 0.0, 'avg_shipping_cost' => 0.0, 'conversion_rate' => 0.0],
        ];

        foreach ($rows as $row) {
            $context = json_decode((string) ($row['context_json'] ?? ''), true);
            if (! is_array($context)) {
                continue;
            }

            $variant = (string) ($context['ab_variant'] ?? 'without_recommendation');
            if (! isset($metrics[$variant])) {
                $metrics[$variant] = ['exposure' => 0, 'orders' => 0, 'shipping_total' => 0.0, 'avg_shipping_cost' => 0.0, 'conversion_rate' => 0.0];
            }

            if (($row['event_type'] ?? '') === 'checkout_started') {
                $metrics[$variant]['exposure']++;
            }

            if (($row['event_type'] ?? '') === 'shipping_method_selected') {
                $metrics[$variant]['orders']++;
                $metrics[$variant]['shipping_total'] += (float) ($context['selected_cost'] ?? 0);
            }
        }

        foreach ($metrics as $variant => $row) {
            $metrics[$variant]['conversion_rate'] = $row['exposure'] > 0 ? ($row['orders'] / $row['exposure']) * 100 : 0.0;
            $metrics[$variant]['avg_shipping_cost'] = $row['orders'] > 0 ? $row['shipping_total'] / $row['orders'] : 0.0;
            unset($metrics[$variant]['shipping_total']);
        }

        return $metrics;
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

        $current['observability_alert_threshold_pct'] = max(1, min(500, (int) ($_POST['observability_alert_threshold_pct'] ?? 35)));
        $current['observability_alert_email'] = isset($_POST['observability_alert_email']) ? sanitize_email((string) wp_unslash($_POST['observability_alert_email'])) : '';
        $current['observability_slack_webhook'] = isset($_POST['observability_slack_webhook']) ? esc_url_raw((string) wp_unslash($_POST['observability_slack_webhook'])) : '';

        update_option($this->option_key, $current);
    }

    private function to_score(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }
}
