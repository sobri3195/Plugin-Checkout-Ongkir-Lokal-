<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Logger
{
    public function info(string $event_type, string $message, array $context = []): void
    {
        $this->write('info', $event_type, $message, $context);
    }

    public function warning(string $event_type, string $message, array $context = []): void
    {
        $this->write('warning', $event_type, $message, $context);
    }

    public function error(string $event_type, string $message, array $context = []): void
    {
        $this->write('error', $event_type, $message, $context);
    }


    public function log_delivery_promise_comparison(int $order_id, array $promises, string $actual_delivery_date, array $context = []): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'col_delivery_promise_logs';

        foreach ($promises as $rate_id => $payload) {
            $promise = is_array($payload['promise'] ?? null) ? $payload['promise'] : [];
            $min_days = (int) ($promise['eta_min_days'] ?? 0);
            $max_days = (int) ($promise['eta_max_days'] ?? 0);
            $created_at = current_time('mysql');
            $promised_min_date = gmdate('Y-m-d', strtotime('+' . $min_days . ' day'));
            $promised_max_date = gmdate('Y-m-d', strtotime('+' . $max_days . ' day'));
            $delta_days = (int) floor((strtotime($actual_delivery_date) - strtotime($promised_max_date)) / DAY_IN_SECONDS);

            $wpdb->insert($table, [
                'order_id' => $order_id,
                'shipping_rate_id' => (string) $rate_id,
                'courier' => (string) ($payload['courier'] ?? ''),
                'service' => (string) ($payload['service'] ?? ''),
                'baseline_eta_label' => (string) ($promise['baseline_eta_label'] ?? ''),
                'promised_min_days' => max(0, $min_days),
                'promised_max_days' => max(0, $max_days),
                'promised_min_date' => $promised_min_date,
                'promised_max_date' => $promised_max_date,
                'confidence_label' => (string) ($promise['confidence'] ?? 'low'),
                'reasons_json' => wp_json_encode($promise['reasons'] ?? []),
                'actual_delivery_at' => gmdate('Y-m-d', strtotime($actual_delivery_date)),
                'delta_days' => $delta_days,
                'tracking_payload_json' => wp_json_encode($context['tracking_payload'] ?? []),
                'created_at' => $created_at,
                'updated_at' => $created_at,
            ]);
        }
    }

    private function write(string $level, string $event_type, string $message, array $context): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'col_logs';

        $wpdb->insert($table, [
            'request_id' => $context['request_id'] ?? wp_generate_uuid4(),
            'level' => $level,
            'event_type' => $event_type,
            'provider' => $context['provider'] ?? '',
            'cache_status' => $context['cache_status'] ?? '',
            'fallback_used' => ! empty($context['fallback_used']) ? 1 : 0,
            'message' => $message,
            'context_json' => wp_json_encode($context),
            'created_at' => current_time('mysql'),
        ]);
    }
}
