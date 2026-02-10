<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Pickup_Point_Service
{
    private const SESSION_KEY = 'col_selected_pickup_point';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $static_points = [
        [
            'id' => 'jakarta-pusat-10110-1',
            'name' => 'PUDO Gambir',
            'address' => 'Jl. Medan Merdeka Timur No. 10, Jakarta Pusat 10110',
            'operating_hours' => '08:00 - 22:00',
            'coordinates' => ['lat' => -6.1766, 'lng' => 106.8306],
            'city' => 'JAKARTA PUSAT',
            'postcode' => '10110',
            'source' => 'static',
        ],
        [
            'id' => 'bandung-40115-1',
            'name' => 'PUDO Dago',
            'address' => 'Jl. Ir. H. Juanda No. 45, Bandung 40115',
            'operating_hours' => '09:00 - 21:00',
            'coordinates' => ['lat' => -6.8915, 'lng' => 107.6107],
            'city' => 'BANDUNG',
            'postcode' => '40115',
            'source' => 'static',
        ],
        [
            'id' => 'surabaya-60241-1',
            'name' => 'PUDO Tunjungan',
            'address' => 'Jl. Tunjungan No. 88, Surabaya 60241',
            'operating_hours' => '08:00 - 20:00',
            'coordinates' => ['lat' => -7.2575, 'lng' => 112.7521],
            'city' => 'SURABAYA',
            'postcode' => '60241',
            'source' => 'static',
        ],
    ];

    public function __construct(
        private COL_Pickup_Point_Provider_Interface $provider,
        private COL_Logger $logger,
        private COL_Settings $settings
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_assets']);
        add_action('woocommerce_after_order_notes', [$this, 'render_checkout_field']);

        add_action('wp_ajax_col_get_pickup_points', [$this, 'ajax_get_pickup_points']);
        add_action('wp_ajax_nopriv_col_get_pickup_points', [$this, 'ajax_get_pickup_points']);

        add_action('wp_ajax_col_set_pickup_point', [$this, 'ajax_set_pickup_point']);
        add_action('wp_ajax_nopriv_col_set_pickup_point', [$this, 'ajax_set_pickup_point']);

        add_action('woocommerce_after_checkout_validation', [$this, 'validate_pickup_point'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_pickup_point_to_order'], 20, 2);
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'save_pickup_point_to_order_store_api'], 10, 2);
    }

    public function enqueue_checkout_assets(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'col-pickup-point-checkout',
            COL_PLUGIN_URL . 'includes/pickup-point-checkout.js',
            ['jquery'],
            COL_VERSION,
            true
        );

        wp_localize_script('col-pickup-point-checkout', 'colPickupPoint', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('col_pickup_point_nonce'),
            'shippingMethodId' => 'col_pickup_point',
            'labels' => [
                'placeholder' => __('Pilih pickup point', 'checkout-ongkir-lokal'),
                'loading' => __('Memuat pickup point...', 'checkout-ongkir-lokal'),
                'empty' => __('Pickup point tidak tersedia untuk destinasi ini.', 'checkout-ongkir-lokal'),
                'error' => __('Gagal memuat pickup point. Coba lagi.', 'checkout-ongkir-lokal'),
            ],
        ]);
    }

    public function render_checkout_field(): void
    {
        echo '<div id="col-pickup-point-wrapper" style="display:none; margin-top:12px;">';
        echo '<h3>' . esc_html__('Ambil di Pickup Point', 'checkout-ongkir-lokal') . '</h3>';
        echo '<p class="form-row form-row-wide">';
        echo '<label for="col_pickup_point_select">' . esc_html__('Pilih Pickup Point', 'checkout-ongkir-lokal') . ' <abbr class="required" title="required">*</abbr></label>';
        echo '<select id="col_pickup_point_select" class="input-select"><option value="">' . esc_html__('Pilih pickup point', 'checkout-ongkir-lokal') . '</option></select>';
        echo '</p>';
        echo '<div id="col-pickup-point-meta" style="font-size:13px;"></div>';
        echo '</div>';
    }

    public function ajax_get_pickup_points(): void
    {
        $this->verify_nonce();

        $city = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
        $postcode = isset($_POST['postcode']) ? sanitize_text_field(wp_unslash($_POST['postcode'])) : '';

        $result = $this->resolve_pickup_points($city, $postcode);

        wp_send_json_success([
            'points' => $result['points'],
            'source' => $result['source'],
        ]);
    }

    public function ajax_set_pickup_point(): void
    {
        $this->verify_nonce();

        $point = isset($_POST['point']) ? json_decode(wp_unslash((string) $_POST['point']), true) : [];
        if (! is_array($point) || empty($point['id'])) {
            if (WC()->session) {
                WC()->session->set(self::SESSION_KEY, null);
            }
            wp_send_json_error(['message' => 'invalid_point']);
        }

        if (WC()->session) {
            WC()->session->set(self::SESSION_KEY, $point);
        }

        $this->logger->info('point_selected', 'Pickup point dipilih customer', [
            'provider' => $this->settings->all()['provider'] ?? 'unknown',
            'pickup_point_id' => $point['id'],
        ]);

        wp_send_json_success(['stored' => true]);
    }

    public function validate_pickup_point(array $data, WP_Error $errors): void
    {
        if (! $this->is_pickup_point_shipping_selected()) {
            return;
        }

        $selected = WC()->session ? WC()->session->get(self::SESSION_KEY) : null;
        if (! is_array($selected) || empty($selected['id'])) {
            $errors->add('col_pickup_point_required', __('Silakan pilih pickup point sebelum checkout.', 'checkout-ongkir-lokal'));
            return;
        }

        $city = $data['shipping_city'] ?? $data['billing_city'] ?? '';
        $postcode = $data['shipping_postcode'] ?? $data['billing_postcode'] ?? '';

        $valid_ids = array_column($this->resolve_pickup_points((string) $city, (string) $postcode)['points'], 'id');
        if (! in_array($selected['id'], $valid_ids, true)) {
            $this->logger->warning('point_invalidated', 'Pickup point tidak valid dengan tujuan terbaru', [
                'provider' => $this->settings->all()['provider'] ?? 'unknown',
                'pickup_point_id' => $selected['id'],
            ]);

            $errors->add('col_pickup_point_invalid', __('Pickup point sudah tidak valid. Silakan pilih ulang.', 'checkout-ongkir-lokal'));
        }
    }

    public function save_pickup_point_to_order(WC_Order $order): void
    {
        if (! $this->is_pickup_point_shipping_selected()) {
            return;
        }

        $selected = WC()->session ? WC()->session->get(self::SESSION_KEY) : null;
        if (! is_array($selected)) {
            return;
        }

        $this->persist_order_meta($order, $selected);
    }

    public function save_pickup_point_to_order_store_api(WC_Order $order): void
    {
        $selected = WC()->session ? WC()->session->get(self::SESSION_KEY) : null;
        if (! is_array($selected)) {
            return;
        }

        if (! $this->is_pickup_point_shipping_selected()) {
            return;
        }

        $this->persist_order_meta($order, $selected);
    }

    /**
     * @return array{points: array<int, array<string, mixed>>, source: string}
     */
    private function resolve_pickup_points(string $city, string $postcode): array
    {
        $city = strtoupper(trim($city));
        $postcode = trim($postcode);

        $cache_key = 'col_pickup_points_' . md5($city . '|' . $postcode);
        $cached = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached)) {
            $this->logger->info('list_loaded', 'Pickup point loaded dari cache', [
                'provider' => $this->settings->all()['provider'] ?? 'unknown',
                'cache_status' => 'hit',
                'source' => 'cache',
            ]);

            return ['points' => $cached, 'source' => 'cache'];
        }

        $live_points = $this->provider->get_pickup_points($city, $postcode);
        if (! empty($live_points)) {
            $ttl = (int) ($this->settings->all()['cache_ttl_seconds'] ?? 900);
            set_transient($cache_key, $live_points, $ttl);
            update_option($cache_key . '_stale', ['points' => $live_points, 'fetched_at' => time()], false);

            $this->logger->info('list_loaded', 'Pickup point loaded dari provider realtime', [
                'provider' => $this->settings->all()['provider'] ?? 'unknown',
                'cache_status' => 'miss',
                'source' => 'realtime',
            ]);

            return ['points' => $live_points, 'source' => 'realtime'];
        }

        $stale = get_option($cache_key . '_stale', []);
        if (is_array($stale) && ! empty($stale['points'])) {
            $this->logger->warning('list_loaded', 'Provider pickup point down, gunakan stale cache', [
                'provider' => $this->settings->all()['provider'] ?? 'unknown',
                'cache_status' => 'stale',
                'fallback_used' => true,
                'source' => 'stale_cache',
            ]);

            return ['points' => $stale['points'], 'source' => 'stale_cache'];
        }

        $static = $this->filter_static_points($city, $postcode);
        $this->logger->warning('list_loaded', 'Provider pickup point down, gunakan fallback statis', [
            'provider' => $this->settings->all()['provider'] ?? 'unknown',
            'cache_status' => 'miss',
            'fallback_used' => true,
            'source' => 'static_fallback',
        ]);

        return ['points' => $static, 'source' => 'static_fallback'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function filter_static_points(string $city, string $postcode): array
    {
        return array_values(array_filter($this->static_points, static function (array $point) use ($city, $postcode): bool {
            $city_match = $city !== '' && str_contains((string) $point['city'], $city);
            $postcode_match = $postcode !== '' && (string) $point['postcode'] === $postcode;

            if ($city !== '' && $postcode !== '') {
                return $city_match || $postcode_match;
            }

            if ($city !== '') {
                return $city_match;
            }

            if ($postcode !== '') {
                return $postcode_match;
            }

            return false;
        }));
    }

    private function verify_nonce(): void
    {
        check_ajax_referer('col_pickup_point_nonce', 'nonce');
    }

    private function is_pickup_point_shipping_selected(): bool
    {
        if (! WC()->session) {
            return false;
        }

        $methods = WC()->session->get('chosen_shipping_methods', []);
        foreach ((array) $methods as $method) {
            if (is_string($method) && str_starts_with($method, 'col_pickup_point')) {
                return true;
            }
        }

        return false;
    }

    private function persist_order_meta(WC_Order $order, array $selected): void
    {
        $order->update_meta_data('_col_pickup_point_id', $selected['id'] ?? '');
        $order->update_meta_data('_col_pickup_point_name', $selected['name'] ?? '');
        $order->update_meta_data('_col_pickup_point_address', $selected['address'] ?? '');
        $order->update_meta_data('_col_pickup_point_operating_hours', $selected['operating_hours'] ?? '');
        $order->update_meta_data('_col_pickup_point_coordinates', $selected['coordinates'] ?? []);
    }
}
