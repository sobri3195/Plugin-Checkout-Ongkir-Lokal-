<?php

if (! defined('ABSPATH')) {
    exit;
}

if (class_exists('WC_Shipping_Method') && ! class_exists('COL_Shipping_Method')) {
    class COL_Shipping_Method extends WC_Shipping_Method
    {
        public function __construct()
        {
            $this->id = 'checkout_ongkir_lokal';
            $this->method_title = 'Checkout Ongkir Lokal';
            $this->method_description = 'Metode ongkir lokal dengan fallback anti-down.';
            $this->enabled = 'yes';
            $this->title = 'Ongkir Lokal';
            $this->supports = ['shipping-zones', 'instance-settings'];
        }

        public function calculate_shipping($package = []): void
        {
            do_action('col_calculate_shipping_package', $this, $package);
        }
    }
}

class COL_Shipping_Service
{
    public function __construct(
        private COL_Settings $settings,
        private COL_Rule_Engine $rule_engine,
        private COL_Logger $logger
    ) {
    }

    public function register_shipping_method(): void
    {
        add_action('col_calculate_shipping_package', [$this, 'calculate_and_add_rates'], 10, 2);
    }

    public function add_shipping_method(array $methods): array
    {
        $methods['checkout_ongkir_lokal'] = 'COL_Shipping_Method';
        return $methods;
    }

    public function calculate_and_add_rates(WC_Shipping_Method $method, array $package): void
    {
        $context = $this->build_context($package);
        $cache_key = $this->build_cache_key($context);

        $rates = $this->fetch_rates_with_antidown($context, $cache_key);

        foreach ($rates as $rate) {
            $computed = $this->rule_engine->apply_surcharge_and_override($rate, $context);
            $method->add_rate([
                'id' => 'col:' . sanitize_title($computed['courier'] . '_' . $computed['service']),
                'label' => sprintf('%s - %s (%s)', strtoupper($computed['courier']), $computed['service'], $computed['eta_label']),
                'cost' => $computed['price'],
            ]);
        }
    }

    private function build_context(array $package): array
    {
        $weight = 0;
        foreach ($package['contents'] ?? [] as $line) {
            $weight += (int) (($line['data']->get_weight() ?: 0) * 1000) * (int) ($line['quantity'] ?? 1);
        }

        return [
            'destination_city' => $package['destination']['city'] ?? '',
            'destination_postcode' => $package['destination']['postcode'] ?? '',
            'destination_district_code' => $package['destination']['state'] ?? '',
            'weight_gram' => max(1, $weight),
            'cart_total' => WC()->cart ? (float) WC()->cart->get_subtotal() : 0,
            'product_tags' => [],
            'is_remote_area' => false,
        ];
    }

    private function build_cache_key(array $context): string
    {
        $settings = $this->settings->all();
        return md5(implode('|', [
            $settings['provider'],
            $context['destination_city'],
            $context['destination_postcode'],
            $context['weight_gram'],
            implode(',', $settings['enabled_couriers']),
        ]));
    }

    private function fetch_rates_with_antidown(array $context, string $cache_key): array
    {
        $cached = $this->get_cache($cache_key);
        if (! empty($cached)) {
            $this->logger->info('cache_hit', 'Rate cache hit', ['cache_status' => 'hit']);
            return $cached;
        }

        $settings = $this->settings->all();
        $live = $this->simulate_provider_call($settings['enabled_couriers'], $context['weight_gram']);

        if (! empty($live)) {
            $this->set_cache($cache_key, $live, (int) $settings['cache_ttl_seconds']);
            return $live;
        }

        $fallback = $this->get_latest_stale_cache($cache_key, (int) $settings['stale_max_age_minutes']);
        if (! empty($fallback)) {
            $this->logger->warning('fallback_used', 'Menggunakan fallback tarif terakhir', [
                'fallback_used' => true,
                'cache_status' => 'stale',
            ]);
            return $fallback;
        }

        $this->logger->error('flat_rate_backup', 'API dan fallback gagal, pakai flat rate backup', [
            'fallback_used' => true,
        ]);

        return [[
            'courier' => 'backup',
            'service' => 'flat-rate',
            'eta_label' => '2-5 hari',
            'price' => (int) $settings['flat_rate_backup'],
        ]];
    }

    private function simulate_provider_call(array $couriers, int $weight_gram): array
    {
        if ($weight_gram > 30000) {
            return [];
        }

        $result = [];
        foreach ($couriers as $courier) {
            $result[] = [
                'courier' => $courier,
                'service' => 'REG',
                'eta_label' => '2-3 hari',
                'price' => 14000 + (int) ceil($weight_gram / 1000) * 2500,
            ];
        }

        return $result;
    }

    private function get_cache(string $cache_key): array
    {
        $cache = get_transient('col_rate_' . $cache_key);
        return is_array($cache) ? $cache : [];
    }

    private function set_cache(string $cache_key, array $rates, int $ttl): void
    {
        set_transient('col_rate_' . $cache_key, $rates, $ttl);
    }

    private function get_latest_stale_cache(string $cache_key, int $max_age_minutes): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'col_rate_cache';
        $since = gmdate('Y-m-d H:i:s', time() - ($max_age_minutes * 60));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT payload_json FROM {$table} WHERE cache_key = %s AND fetched_at >= %s ORDER BY fetched_at DESC LIMIT 1",
            $cache_key,
            $since
        ));

        if (! $row) {
            return [];
        }

        $decoded = json_decode($row->payload_json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
