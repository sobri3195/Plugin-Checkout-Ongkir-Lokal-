<?php

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-col-settings.php';
require_once __DIR__ . '/class-col-shipping-service.php';
require_once __DIR__ . '/class-col-rule-engine.php';
require_once __DIR__ . '/class-col-logger.php';
require_once __DIR__ . '/class-col-origin-repository.php';
require_once __DIR__ . '/class-col-shipment-planner.php';
require_once __DIR__ . '/class-col-shipment-rate-aggregator.php';
require_once __DIR__ . '/class-col-packaging-optimizer.php';
require_once __DIR__ . '/class-col-pickup-point-provider.php';
require_once __DIR__ . '/class-col-pickup-point-service.php';
require_once __DIR__ . '/class-col-cod-risk-service.php';

class COL_Plugin
{
    private static ?COL_Plugin $instance = null;

    private COL_Settings $settings;
    private COL_Logger $logger;
    private COL_Rule_Engine $rule_engine;
    private COL_Shipping_Service $shipping_service;
    private COL_Pickup_Point_Service $pickup_point_service;
    private COL_COD_Risk_Service $cod_risk_service;

    public static function instance(): COL_Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        $this->settings = new COL_Settings();
        $this->logger = new COL_Logger();
        $this->rule_engine = new COL_Rule_Engine($this->settings, $this->logger);

        global $wpdb;
        $origin_repository = new COL_Origin_Repository($wpdb);
        $shipment_planner = new COL_Shipment_Planner($origin_repository);
        $shipment_rate_aggregator = new COL_Shipment_Rate_Aggregator();
        $packaging_optimizer = new COL_Packaging_Optimizer();
        $this->shipping_service = new COL_Shipping_Service(
            $this->settings,
            $this->rule_engine,
            $this->logger,
            $shipment_planner,
            $shipment_rate_aggregator,
            $packaging_optimizer
        );
        $pickup_point_provider = new COL_Pickup_Point_Provider($this->settings);
        $this->pickup_point_service = new COL_Pickup_Point_Service(
            $pickup_point_provider,
            $this->logger,
            $this->settings
        );

        $this->cod_risk_service = new COL_COD_Risk_Service($this->settings, $this->rule_engine, $this->logger);

        add_action('woocommerce_shipping_init', [$this->shipping_service, 'register_shipping_method']);
        add_filter('woocommerce_shipping_methods', [$this->shipping_service, 'add_shipping_method']);
        add_action('admin_menu', [$this->settings, 'register_admin_menu']);
        $this->pickup_point_service->register();
        $this->cod_risk_service->register();

        register_activation_hook(COL_PLUGIN_FILE, [$this, 'activate']);
    }

    public function activate(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'col_';

        $sql = [];
        $sql[] = "CREATE TABLE {$prefix}rate_cache (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(191) NOT NULL,
            provider VARCHAR(50) NOT NULL,
            origin_key VARCHAR(50) NOT NULL,
            destination_key VARCHAR(50) NOT NULL,
            courier VARCHAR(50) NOT NULL,
            service VARCHAR(100) NOT NULL,
            weight_gram INT UNSIGNED NOT NULL,
            volumetric_weight_gram INT UNSIGNED DEFAULT 0,
            price INT UNSIGNED NOT NULL,
            eta_label VARCHAR(100) DEFAULT '',
            payload_json LONGTEXT NULL,
            fetched_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uniq_cache_key (cache_key),
            KEY idx_expires (expires_at)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$prefix}logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(64) NOT NULL,
            level VARCHAR(20) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            provider VARCHAR(50) DEFAULT '',
            cache_status VARCHAR(20) DEFAULT '',
            fallback_used TINYINT(1) DEFAULT 0,
            message TEXT NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_created_at (created_at),
            KEY idx_event_type (event_type),
            KEY idx_provider (provider)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$prefix}district_overrides (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            province_code VARCHAR(20) DEFAULT '',
            city_code VARCHAR(20) DEFAULT '',
            district_code VARCHAR(20) NOT NULL,
            postal_code VARCHAR(20) DEFAULT '',
            override_mode VARCHAR(20) NOT NULL,
            override_value DECIMAL(12,2) NOT NULL,
            priority SMALLINT UNSIGNED DEFAULT 100,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_district (district_code, postal_code)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$prefix}cod_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(191) NOT NULL,
            action_type VARCHAR(20) NOT NULL,
            match_scope VARCHAR(50) NOT NULL,
            operator_type VARCHAR(20) NOT NULL,
            value_json LONGTEXT NOT NULL,
            priority SMALLINT UNSIGNED DEFAULT 100,
            stop_on_match TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_active_priority (is_active, priority)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$prefix}area_mappings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(50) NOT NULL,
            province_name VARCHAR(191) NOT NULL,
            city_name VARCHAR(191) NOT NULL,
            district_name VARCHAR(191) NOT NULL,
            postal_code VARCHAR(20) DEFAULT '',
            provider_area_id VARCHAR(100) NOT NULL,
            normalized_hash VARCHAR(64) NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_provider_hash (provider, normalized_hash)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$prefix}warehouses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            address TEXT NULL,
            region_code VARCHAR(50) NOT NULL,
            priority SMALLINT UNSIGNED DEFAULT 100,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_priority (priority),
            KEY idx_region_code (region_code)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$prefix}product_origins (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            stock_qty INT UNSIGNED DEFAULT 0,
            priority SMALLINT UNSIGNED DEFAULT 100,
            is_fallback TINYINT(1) DEFAULT 0,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_product_origin (product_id, warehouse_id),
            KEY idx_product_priority (product_id, priority),
            KEY idx_warehouse_id (warehouse_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        $this->settings->ensure_defaults();
    }
}
