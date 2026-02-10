<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Observability
{
    private string $events_table;
    private string $rollups_table;
    private string $anomalies_table;

    public function __construct(private COL_Settings $settings)
    {
        global $wpdb;
        $this->events_table = $wpdb->prefix . 'col_metric_events';
        $this->rollups_table = $wpdb->prefix . 'col_metric_rollups';
        $this->anomalies_table = $wpdb->prefix . 'col_metric_anomalies';
    }

    public function register(): void
    {
        add_action('col_observability_aggregate', [$this, 'run_periodic_aggregation']);

        if (! wp_next_scheduled('col_observability_aggregate')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'col_observability_aggregate');
        }

        add_action('admin_menu', [$this, 'register_dashboard_menu']);
    }

    public function record_metric_event(string $event_name, array $payload): void
    {
        global $wpdb;

        $wpdb->insert($this->events_table, [
            'event_name' => sanitize_key($event_name),
            'provider' => sanitize_text_field((string) ($payload['provider'] ?? '')), 
            'courier' => sanitize_text_field((string) ($payload['courier'] ?? '')),
            'area_code' => sanitize_text_field((string) ($payload['area_code'] ?? '')),
            'status' => sanitize_text_field((string) ($payload['status'] ?? '')),
            'cache_status' => sanitize_text_field((string) ($payload['cache_status'] ?? '')),
            'fallback_used' => ! empty($payload['fallback_used']) ? 1 : 0,
            'is_timeout' => ! empty($payload['is_timeout']) ? 1 : 0,
            'response_time_ms' => isset($payload['response_time_ms']) ? (int) $payload['response_time_ms'] : 0,
            'shipping_cost' => isset($payload['shipping_cost']) ? (int) $payload['shipping_cost'] : 0,
            'meta_json' => wp_json_encode($payload),
            'created_at' => current_time('mysql'),
        ]);
    }

    public function run_periodic_aggregation(): void
    {
        $now = current_time('timestamp');
        $last_hour_start = gmdate('Y-m-d H:00:00', $now - HOUR_IN_SECONDS);
        $last_hour_end = gmdate('Y-m-d H:00:00', $now);
        $today_start = gmdate('Y-m-d 00:00:00', $now);
        $tomorrow_start = gmdate('Y-m-d 00:00:00', $now + DAY_IN_SECONDS);

        $this->aggregate_window('hourly', $last_hour_start, $last_hour_end);
        $this->aggregate_window('daily', $today_start, $tomorrow_start);
        $this->detect_anomalies_and_notify($today_start, $tomorrow_start);
    }

    public function register_dashboard_menu(): void
    {
        add_submenu_page(
            'checkout-ongkir-lokal',
            __('Observability Dashboard', 'checkout-ongkir-lokal'),
            __('Observability', 'checkout-ongkir-lokal'),
            'manage_woocommerce',
            'col-observability-dashboard',
            [$this, 'render_dashboard_page']
        );
    }

    public function render_dashboard_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $range = isset($_GET['range']) ? sanitize_text_field(wp_unslash($_GET['range'])) : '24h';
        $provider = isset($_GET['provider']) ? sanitize_text_field(wp_unslash($_GET['provider'])) : '';
        $area = isset($_GET['area']) ? sanitize_text_field(wp_unslash($_GET['area'])) : '';

        [$start, $end] = $this->resolve_range($range);
        $cards = $this->query_kpis($start, $end, $provider, $area);
        $distribution = $this->query_distribution($start, $end, $provider, $area);
        $anomalies = $this->query_anomalies(20);

        echo '<div class="wrap"><h1>Observability Dashboard Ongkir</h1>';
        echo '<form method="get" style="margin-bottom:16px;">';
        echo '<input type="hidden" name="page" value="col-observability-dashboard" />';
        echo '<label>Rentang Waktu <select name="range">';
        foreach (['24h' => '24 Jam', '7d' => '7 Hari', '30d' => '30 Hari'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($range, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>Provider <input type="text" name="provider" value="' . esc_attr($provider) . '" placeholder="rajaongkir"></label> ';
        echo '<label>Area <input type="text" name="area" value="' . esc_attr($area) . '" placeholder="district/state"></label> ';
        submit_button('Filter', 'secondary', '', false);
        echo '</form>';

        echo '<h2>KPI Teknis & Bisnis</h2><table class="widefat striped"><tbody>';
        foreach ([
            'API Success Rate' => number_format((float) ($cards['api_success_rate'] ?? 0), 2) . '%',
            'API Error Rate' => number_format((float) ($cards['api_error_rate'] ?? 0), 2) . '%',
            'Timeout Rate' => number_format((float) ($cards['timeout_rate'] ?? 0), 2) . '%',
            'Cache Hit Rate' => number_format((float) ($cards['cache_hit_rate'] ?? 0), 2) . '%',
            'Cache Miss Rate' => number_format((float) ($cards['cache_miss_rate'] ?? 0), 2) . '%',
            'Fallback Usage Rate' => number_format((float) ($cards['fallback_rate'] ?? 0), 2) . '%',
        ] as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Distribusi Ongkir per Area/Kurir</h2><table class="widefat striped"><thead><tr><th>Area</th><th>Kurir</th><th>Provider</th><th>Rata-rata Ongkir</th><th>Total Event</th></tr></thead><tbody>';
        if (empty($distribution)) {
            echo '<tr><td colspan="5">Belum ada data distribusi.</td></tr>';
        } else {
            foreach ($distribution as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $row['area_code']) . '</td>';
                echo '<td>' . esc_html((string) $row['courier']) . '</td>';
                echo '<td>' . esc_html((string) $row['provider']) . '</td>';
                echo '<td>Rp ' . esc_html(number_format((float) $row['avg_cost'], 0, ',', '.')) . '</td>';
                echo '<td>' . esc_html((string) $row['events']) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '<h2>Anomali (Baseline Harian/Mingguan)</h2><table class="widefat striped"><thead><tr><th>Waktu</th><th>Metric</th><th>Observed</th><th>Baseline</th><th>Deviasi</th><th>Status Notifikasi</th></tr></thead><tbody>';
        if (empty($anomalies)) {
            echo '<tr><td colspan="6">Belum ada anomali.</td></tr>';
        } else {
            foreach ($anomalies as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
                echo '<td>' . esc_html((string) $row['metric_key']) . '</td>';
                echo '<td>' . esc_html((string) $row['observed_value']) . '</td>';
                echo '<td>' . esc_html((string) $row['baseline_value']) . '</td>';
                echo '<td>' . esc_html(number_format((float) $row['deviation_pct'], 2)) . '%</td>';
                echo '<td>' . esc_html(! empty($row['notified_at']) ? 'Terkirim' : 'Belum') . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    private function aggregate_window(string $period_type, string $start, string $end): void
    {
        global $wpdb;
        $metrics = $this->query_kpis($start, $end, '', '');

        $wpdb->replace($this->rollups_table, [
            'period_type' => $period_type,
            'period_start' => $start,
            'provider' => '',
            'courier' => '',
            'area_code' => '',
            'metrics_json' => wp_json_encode($metrics),
            'created_at' => current_time('mysql'),
        ]);
    }

    private function detect_anomalies_and_notify(string $today_start, string $tomorrow_start): void
    {
        $current = $this->query_kpis($today_start, $tomorrow_start, '', '');
        $daily_baseline = $this->build_daily_baseline();
        $weekly_baseline = $this->build_weekly_baseline();
        $threshold = (float) ($this->settings->all()['observability_alert_threshold_pct'] ?? 35);

        $metric_candidates = [
            'api_error_rate' => $daily_baseline['api_error_rate'] ?? 0,
            'timeout_rate' => $daily_baseline['timeout_rate'] ?? 0,
            'fallback_rate' => $daily_baseline['fallback_rate'] ?? 0,
            'cache_miss_rate' => $weekly_baseline['cache_miss_rate'] ?? 0,
        ];

        foreach ($metric_candidates as $metric => $baseline) {
            $observed = (float) ($current[$metric] ?? 0);
            $deviation = self::calculate_deviation_pct($observed, (float) $baseline);
            if ($deviation < $threshold) {
                continue;
            }

            $anomaly_id = $this->store_anomaly($metric, $observed, (float) $baseline, $deviation);
            $this->send_anomaly_notifications($anomaly_id, $metric, $observed, (float) $baseline, $deviation);
        }
    }

    private function store_anomaly(string $metric, float $observed, float $baseline, float $deviation): int
    {
        global $wpdb;
        $wpdb->insert($this->anomalies_table, [
            'metric_key' => $metric,
            'observed_value' => $observed,
            'baseline_value' => $baseline,
            'deviation_pct' => $deviation,
            'status' => 'open',
            'created_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function send_anomaly_notifications(int $anomaly_id, string $metric, float $observed, float $baseline, float $deviation): void
    {
        $settings = $this->settings->all();
        $subject = sprintf('[COL] Anomali %s', $metric);
        $message = sprintf(
            "Anomali terdeteksi\nMetric: %s\nObserved: %.2f\nBaseline: %.2f\nDeviation: %.2f%%",
            $metric,
            $observed,
            $baseline,
            $deviation
        );

        $notified = false;
        $email = sanitize_email((string) ($settings['observability_alert_email'] ?? ''));
        if (! empty($email)) {
            $notified = wp_mail($email, $subject, $message) || $notified;
        }

        $slack = esc_url_raw((string) ($settings['observability_slack_webhook'] ?? ''));
        if (! empty($slack)) {
            $response = wp_remote_post($slack, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(['text' => $message]),
                'timeout' => 10,
            ]);
            $notified = ! is_wp_error($response) || $notified;
        }

        if ($notified) {
            global $wpdb;
            $wpdb->update(
                $this->anomalies_table,
                ['notified_at' => current_time('mysql')],
                ['id' => $anomaly_id]
            );
        }
    }

    private function build_daily_baseline(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col("SELECT metrics_json FROM {$this->rollups_table} WHERE period_type = 'daily' ORDER BY period_start DESC LIMIT 7");
        return $this->average_metrics($rows);
    }

    private function build_weekly_baseline(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col("SELECT metrics_json FROM {$this->rollups_table} WHERE period_type = 'daily' ORDER BY period_start DESC LIMIT 28");
        return $this->average_metrics($rows);
    }

    private function average_metrics(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $totals = [];
        $count = 0;
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row, true);
            if (! is_array($decoded)) {
                continue;
            }

            $count++;
            foreach ($decoded as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + (float) $value;
            }
        }

        if ($count === 0) {
            return [];
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = $value / $count;
        }

        return $totals;
    }

    private function query_kpis(string $start, string $end, string $provider, string $area): array
    {
        global $wpdb;
        $where = 'created_at >= %s AND created_at < %s';
        $params = [$start, $end];

        if ($provider !== '') {
            $where .= ' AND provider = %s';
            $params[] = $provider;
        }
        if ($area !== '') {
            $where .= ' AND area_code = %s';
            $params[] = $area;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS api_success,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS api_error,
                SUM(CASE WHEN is_timeout = 1 THEN 1 ELSE 0 END) AS timeout_events,
                SUM(CASE WHEN cache_status = 'hit' THEN 1 ELSE 0 END) AS cache_hit,
                SUM(CASE WHEN cache_status = 'miss' THEN 1 ELSE 0 END) AS cache_miss,
                SUM(CASE WHEN fallback_used = 1 THEN 1 ELSE 0 END) AS fallback_usage
             FROM {$this->events_table}
             WHERE {$where}",
            ...$params
        ), ARRAY_A);

        $row = $rows[0] ?? [];
        $total = max(1, (int) ($row['total'] ?? 0));

        return [
            'api_success_rate' => ((int) ($row['api_success'] ?? 0) / $total) * 100,
            'api_error_rate' => ((int) ($row['api_error'] ?? 0) / $total) * 100,
            'timeout_rate' => ((int) ($row['timeout_events'] ?? 0) / $total) * 100,
            'cache_hit_rate' => ((int) ($row['cache_hit'] ?? 0) / $total) * 100,
            'cache_miss_rate' => ((int) ($row['cache_miss'] ?? 0) / $total) * 100,
            'fallback_rate' => ((int) ($row['fallback_usage'] ?? 0) / $total) * 100,
        ];
    }

    private function query_distribution(string $start, string $end, string $provider, string $area): array
    {
        global $wpdb;
        $where = 'created_at >= %s AND created_at < %s';
        $params = [$start, $end];

        if ($provider !== '') {
            $where .= ' AND provider = %s';
            $params[] = $provider;
        }

        if ($area !== '') {
            $where .= ' AND area_code = %s';
            $params[] = $area;
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT area_code, courier, provider, AVG(shipping_cost) AS avg_cost, COUNT(*) AS events
             FROM {$this->events_table}
             WHERE {$where} AND shipping_cost > 0
             GROUP BY area_code, courier, provider
             ORDER BY events DESC
             LIMIT 100",
            ...$params
        ), ARRAY_A);
    }

    private function query_anomalies(int $limit): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, metric_key, observed_value, baseline_value, deviation_pct, notified_at
             FROM {$this->anomalies_table}
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    private function resolve_range(string $range): array
    {
        $end = current_time('timestamp');
        $seconds = match ($range) {
            '7d' => 7 * DAY_IN_SECONDS,
            '30d' => 30 * DAY_IN_SECONDS,
            default => DAY_IN_SECONDS,
        };

        return [gmdate('Y-m-d H:i:s', $end - $seconds), gmdate('Y-m-d H:i:s', $end)];
    }

    public static function calculate_deviation_pct(float $observed, float $baseline): float
    {
        if ($baseline <= 0.0) {
            return $observed > 0 ? 100.0 : 0.0;
        }

        return abs(($observed - $baseline) / $baseline) * 100;
    }
}
