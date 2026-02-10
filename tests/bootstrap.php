<?php

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower(trim($title));
        return preg_replace('/[^a-z0-9]+/', '-', $title) ?: '';
    }
}

require_once __DIR__ . '/../includes/class-col-origin-repository.php';
require_once __DIR__ . '/../includes/class-col-shipment-planner.php';
require_once __DIR__ . '/../includes/class-col-shipment-rate-aggregator.php';


if (! function_exists('current_time')) {
    function current_time(string $type)
    {
        if ($type === 'G') {
            return 23;
        }

        return gmdate('Y-m-d H:i:s');
    }
}

require_once __DIR__ . '/../includes/class-col-settings.php';
require_once __DIR__ . '/../includes/class-col-logger.php';
require_once __DIR__ . '/../includes/class-col-rule-engine.php';

require_once __DIR__ . '/../includes/class-col-address-intelligence.php';
