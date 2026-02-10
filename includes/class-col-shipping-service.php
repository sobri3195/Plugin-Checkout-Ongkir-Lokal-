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

if (class_exists('WC_Shipping_Method') && ! class_exists('COL_Pickup_Point_Shipping_Method')) {
    class COL_Pickup_Point_Shipping_Method extends WC_Shipping_Method
    {
        public function __construct()
        {
            $this->id = 'col_pickup_point';
            $this->method_title = 'Ambil di Pickup Point';
            $this->method_description = 'Pengiriman ke pickup point/PUDO terdekat.';
            $this->enabled = 'yes';
            $this->title = 'Ambil di Pickup Point';
            $this->supports = ['shipping-zones', 'instance-settings'];
        }

        public function calculate_shipping($package = []): void
        {
            $this->add_rate([
                'id' => $this->id,
                'label' => $this->title,
                'cost' => 0,
            ]);
        }
    }
}

class COL_Shipping_Service
{
    public function __construct(
        private COL_Settings $settings,
        private COL_Rule_Engine $rule_engine,
        private COL_Logger $logger,
        private COL_Shipment_Planner $shipment_planner,
        private COL_Shipment_Rate_Aggregator $shipment_rate_aggregator
    ) {
    }

    public function register_shipping_method(): void
    {
        add_action('col_calculate_shipping_package', [$this, 'calculate_and_add_rates'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_plan_order_metadata'], 10, 2);
    }

    public function add_shipping_method(array $methods): array
    {
        $methods['checkout_ongkir_lokal'] = 'COL_Shipping_Method';
        $methods['col_pickup_point'] = 'COL_Pickup_Point_Shipping_Method';
        return $methods;
    }

    public function calculate_and_add_rates(WC_Shipping_Method $method, array $package): void
    {
        $context = $this->build_context($package);
        $plan_result = $this->resolve_shipment_plan($context['cart_lines']);

        if (! $plan_result['is_available']) {
            return;
        }

        $shipment_rates = [];
        foreach ($plan_result['shipments'] as $shipment) {
            $cache_key = $this->build_cache_key($context, $shipment);
            $shipment_rates[] = $this->fetch_rates_with_antidown($context, $cache_key, $shipment);
        }

        $aggregated_rates = $this->shipment_rate_aggregator->aggregate($shipment_rates);
        $meta_payload = [
            'plan_id' => $plan_result['plan_id'],
            'strategy' => $plan_result['strategy'],
            'origin_list' => array_column($plan_result['shipments'], 'origin_region_code'),
            'shipment_count' => count($plan_result['shipments']),
            'shipments' => $plan_result['shipments'],
            'per_shipment_rates' => $shipment_rates,
        ];

        foreach ($aggregated_rates as $rate) {
            $computed = $this->rule_engine->apply_surcharge_and_override($rate, $context);
            $rate_id = 'col:' . sanitize_title($computed['courier'] . '_' . $computed['service']);
            $method->add_rate([
                'id' => $rate_id,
                'label' => sprintf(
                    '%s - %s (%s, %d pengiriman)',
                    strtoupper($computed['courier']),
                    $computed['service'],
                    $computed['eta_label'],
                    $rate['shipment_count']
                ),
                'cost' => $computed['price'],
                'meta_data' => [
                    'col_plan_payload' => wp_json_encode($meta_payload),
                    'col_service_key' => $rate_id,
                ],
            ]);
        }

        if (WC()->session) {
            WC()->session->set('col_last_plan_payload', $meta_payload);
        }
    }

    public function save_plan_order_metadata(WC_Order $order, array $data): void
    {
        if (! WC()->session) {
            return;
        }

        $payload = WC()->session->get('col_last_plan_payload');
        if (! is_array($payload)) {
            return;
        }

        $order->update_meta_data('_col_plan_id', $payload['plan_id'] ?? '');
        $order->update_meta_data('_col_origin_list', $payload['origin_list'] ?? []);
        $order->update_meta_data('_col_shipment_count', (int) ($payload['shipment_count'] ?? 0));
        $order->update_meta_data('_col_per_shipment_cost', $payload['per_shipment_rates'] ?? []);
    }

    private function resolve_shipment_plan(array $cart_lines): array
    {
        $plan_candidates = $this->shipment_planner->build_plan($cart_lines);
        $strategy = apply_filters('col_shipment_strategy', $this->settings->all()['shipment_strategy'] ?? 'balanced', $plan_candidates, $cart_lines);

        $single = $plan_candidates['single_origin'];
        $split = $plan_candidates['split_shipment'];

        if (! $single['is_available']) {
            $selected = $split;
            $strategy = 'split_fallback';
        } elseif (! $split['is_available']) {
            $selected = $single;
            $strategy = 'single_only';
        } else {
            $selected = match ($strategy) {
                'termurah' => $this->pick_min_shipments($single, $split),
                'tercepat' => $this->pick_min_shipments($single, $split),
                default => $this->pick_balanced($single, $split),
            };
        }

        return [
            'is_available' => ! empty($selected['shipments']),
            'plan_id' => 'col-plan-' . wp_generate_uuid4(),
            'strategy' => $strategy,
            'shipments' => $selected['shipments'] ?? [],
        ];
    }

    private function pick_min_shipments(array $single, array $split): array
    {
        return count($single['shipments']) <= count($split['shipments']) ? $single : $split;
    }

    private function pick_balanced(array $single, array $split): array
    {
        return $single['score'] <= $split['score'] ? $single : $split;
    }

    private function build_context(array $package): array
    {
        $cart_lines = [];
        foreach ($package['contents'] ?? [] as $line) {
            $product = $line['data'] ?? null;
            if (! $product || ! method_exists($product, 'get_id')) {
                continue;
            }

            $cart_lines[] = [
                'product_id' => (int) $product->get_id(),
                'quantity' => (int) ($line['quantity'] ?? 1),
                'unit_weight_gram' => max(1, (int) (($product->get_weight() ?: 0) * 1000)),
            ];
        }

        $total_weight = array_reduce($cart_lines, static function (int $carry, array $line): int {
            return $carry + ($line['quantity'] * $line['unit_weight_gram']);
        }, 0);

        return [
            'destination_city' => $package['destination']['city'] ?? '',
            'destination_postcode' => $package['destination']['postcode'] ?? '',
            'destination_district_code' => $package['destination']['state'] ?? '',
            'weight_gram' => max(1, $total_weight),
            'cart_total' => WC()->cart ? (float) WC()->cart->get_subtotal() : 0,
            'product_tags' => [],
            'is_remote_area' => false,
            'cart_lines' => $cart_lines,
        ];
    }

    private function build_cache_key(array $context, array $shipment): string
    {
        $settings = $this->settings->all();
        return md5(implode('|', [
            $settings['provider'],
            $shipment['origin_region_code'] ?? '',
            $context['destination_city'],
            $context['destination_postcode'],
            $shipment['weight_gram'],
            implode(',', $settings['enabled_couriers']),
        ]));
    }

    private function fetch_rates_with_antidown(array $context, string $cache_key, array $shipment): array
    {
        $cached = $this->get_cache($cache_key);
        if (! empty($cached)) {
            $this->logger->info('cache_hit', 'Rate cache hit', ['cache_status' => 'hit']);
            return $cached;
        }

        $settings = $this->settings->all();
        $live = $this->simulate_provider_call(
            $settings['enabled_couriers'],
            (int) $shipment['weight_gram'],
            (string) ($shipment['origin_region_code'] ?? ''),
            (string) $context['destination_district_code']
        );

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

    private function simulate_provider_call(array $couriers, int $weight_gram, string $origin_region_code, string $destination_region_code): array
    {
        if ($weight_gram > 30000) {
            return [];
        }

        $distance_factor = $origin_region_code === $destination_region_code ? 1000 : 2500;
        $result = [];
        foreach ($couriers as $courier) {
            $result[] = [
                'courier' => $courier,
                'service' => 'REG',
                'eta_label' => $origin_region_code === $destination_region_code ? '1-2 hari' : '2-4 hari',
                'price' => 12000 + $distance_factor + (int) ceil($weight_gram / 1000) * 2000,
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
