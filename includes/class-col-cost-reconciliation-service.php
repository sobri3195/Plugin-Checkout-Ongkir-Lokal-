<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Cost_Reconciliation_Service
{
    public function __construct(private COL_Settings $settings, private COL_Logger $logger)
    {
    }

    public function register(): void
    {
        add_action('col_reconciliation_ingest_actual_costs', [$this, 'ingest_actual_costs'], 10, 1);
        add_action('col_daily_variance_report', [$this, 'generate_periodic_report']);

        if (! wp_next_scheduled('col_daily_variance_report')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'col_daily_variance_report');
        }
    }

    public function ingest_actual_costs(array $records): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'col_cost_variances';

        $matched = 0;
        $unmatched = 0;
        $saved_rows = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $order_id = $this->resolve_order_id($record);
            if ($order_id <= 0) {
                $unmatched++;
                continue;
            }

            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
            if (! $order) {
                $unmatched++;
                continue;
            }

            $estimated_cost = (float) $order->get_meta('_col_selected_estimated_cost', true);
            if ($estimated_cost <= 0) {
                $estimated_cost = (float) $order->get_shipping_total();
            }

            $actual_cost = isset($record['actual_cost']) ? (float) $record['actual_cost'] : 0.0;
            $variance = $actual_cost - $estimated_cost;

            $courier = (string) ($record['courier'] ?? $order->get_meta('_col_selected_courier', true));
            $service = (string) ($record['service'] ?? $order->get_meta('_col_selected_service', true));
            $area = (string) ($record['area'] ?? $order->get_meta('_col_destination_area', true));
            $rule = (string) ($order->get_meta('_col_selected_rule_context', true));

            $saved = [
                'order_id' => $order_id,
                'courier' => $courier,
                'service' => $service,
                'area_code' => $area,
                'active_rule' => $rule,
                'estimated_cost' => $estimated_cost,
                'actual_cost' => $actual_cost,
                'variance' => $variance,
                'source_reference' => (string) ($record['source_reference'] ?? $record['awb'] ?? ''),
                'reconciled_at' => gmdate('Y-m-d H:i:s'),
            ];

            $wpdb->insert(
                $table,
                $saved,
                ['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s']
            );

            $order->update_meta_data('_col_actual_shipping_cost', $actual_cost);
            $order->update_meta_data('_col_shipping_variance', $variance);
            $order->save();

            $saved_rows[] = $saved;
            $matched++;
        }

        $this->logger->info('cost_reconciliation_ingested', 'Ingest biaya aktual selesai', [
            'matched' => $matched,
            'unmatched' => $unmatched,
        ]);

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'rows' => $saved_rows,
        ];
    }

    public function ingest_from_csv_content(string $csv_content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv_content)) ?: [];
        if (empty($lines)) {
            return ['matched' => 0, 'unmatched' => 0, 'rows' => []];
        }

        $header = str_getcsv((string) array_shift($lines));
        $records = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);
            $records[] = array_combine($header, $values) ?: [];
        }

        return $this->ingest_actual_costs($records);
    }

    public function build_variance_report_from_rows(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $courier = (string) ($row['courier'] ?? '');
            $service = (string) ($row['service'] ?? '');
            $area = (string) ($row['area_code'] ?? $row['area'] ?? '');
            $rule = (string) ($row['active_rule'] ?? '');
            $variance = (float) ($row['variance'] ?? 0);

            $key = implode('|', [$courier, $service, $area, $rule]);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'courier' => $courier,
                    'service' => $service,
                    'area' => $area,
                    'active_rule' => $rule,
                    'sample_count' => 0,
                    'total_variance' => 0.0,
                    'positive_variance_count' => 0,
                    'negative_variance_count' => 0,
                ];
            }

            $grouped[$key]['sample_count']++;
            $grouped[$key]['total_variance'] += $variance;

            if ($variance > 0) {
                $grouped[$key]['positive_variance_count']++;
            } elseif ($variance < 0) {
                $grouped[$key]['negative_variance_count']++;
            }
        }

        foreach ($grouped as &$item) {
            $item['average_variance'] = $item['sample_count'] > 0
                ? $item['total_variance'] / $item['sample_count']
                : 0.0;
        }
        unset($item);

        return array_values($grouped);
    }

    public function recommend_rule_tuning(array $report_rows, float $threshold, int $minimum_samples = 3): array
    {
        $recommendations = [];

        foreach ($report_rows as $row) {
            $avg = (float) ($row['average_variance'] ?? 0);
            $samples = (int) ($row['sample_count'] ?? 0);

            if ($samples < $minimum_samples || abs($avg) < $threshold) {
                continue;
            }

            $rounded_adjustment = (int) (round($avg / 500) * 500);
            if ($rounded_adjustment === 0) {
                continue;
            }

            $action = $rounded_adjustment > 0 ? 'add_surcharge' : 'reduce_surcharge';

            $recommendations[] = [
                'courier' => (string) ($row['courier'] ?? ''),
                'service' => (string) ($row['service'] ?? ''),
                'area' => (string) ($row['area'] ?? ''),
                'active_rule' => (string) ($row['active_rule'] ?? ''),
                'recommended_action' => $action,
                'suggested_adjustment' => $rounded_adjustment,
                'average_variance' => $avg,
                'sample_count' => $samples,
            ];
        }

        return $recommendations;
    }

    public function check_threshold_and_notify(array $report_rows, float $threshold): array
    {
        $alerts = [];

        foreach ($report_rows as $row) {
            $avg = (float) ($row['average_variance'] ?? 0);
            if (abs($avg) < $threshold) {
                continue;
            }

            $alerts[] = [
                'courier' => (string) ($row['courier'] ?? ''),
                'service' => (string) ($row['service'] ?? ''),
                'area' => (string) ($row['area'] ?? ''),
                'active_rule' => (string) ($row['active_rule'] ?? ''),
                'average_variance' => $avg,
                'threshold' => $threshold,
            ];
        }

        if (! empty($alerts)) {
            $this->logger->warning('cost_variance_threshold', 'Variance melebihi threshold', [
                'alerts' => $alerts,
            ]);

            if (function_exists('wp_mail')) {
                wp_mail(
                    get_option('admin_email'),
                    '[Checkout Ongkir Lokal] Alert variance shipping',
                    wp_json_encode($alerts, JSON_PRETTY_PRINT)
                );
            }
        }

        return $alerts;
    }

    public function generate_periodic_report(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'col_cost_variances';

        $period_days = max(1, (int) ($this->settings->all()['reconciliation_report_period_days'] ?? 7));
        $threshold = (float) ($this->settings->all()['reconciliation_variance_threshold'] ?? 5000);
        $since = gmdate('Y-m-d H:i:s', time() - ($period_days * DAY_IN_SECONDS));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT courier, service, area_code AS area, active_rule, variance
            FROM {$table}
            WHERE reconciled_at >= %s",
            $since
        ), ARRAY_A);

        $report_rows = $this->build_variance_report_from_rows(is_array($rows) ? $rows : []);
        $alerts = $this->check_threshold_and_notify($report_rows, $threshold);
        $recommendations = $this->recommend_rule_tuning($report_rows, $threshold);

        $report = [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'period_days' => $period_days,
            'rows' => $report_rows,
            'alerts' => $alerts,
            'recommendations' => $recommendations,
        ];

        update_option('col_last_variance_report', $report, false);

        $this->logger->info('cost_variance_report_generated', 'Laporan periodik variance dibuat', [
            'rows' => count($report_rows),
            'alerts' => count($alerts),
            'recommendations' => count($recommendations),
        ]);

        return $report;
    }

    private function resolve_order_id(array $record): int
    {
        $order_id = isset($record['order_id']) ? (int) $record['order_id'] : 0;
        if ($order_id > 0) {
            return $order_id;
        }

        $order_number = isset($record['order_number']) ? (string) $record['order_number'] : '';
        if ($order_number === '' || ! function_exists('wc_get_orders')) {
            return 0;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'type' => 'shop_order',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_order_number',
            'meta_value' => $order_number,
        ]);

        if (! empty($orders) && $orders[0] instanceof WC_Order) {
            return (int) $orders[0]->get_id();
        }

        return 0;
    }
}
