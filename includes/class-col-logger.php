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
