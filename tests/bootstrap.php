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
