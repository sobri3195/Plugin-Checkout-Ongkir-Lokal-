<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Address_Intelligence_Service
{
    private const SESSION_ANALYSIS_KEY = 'col_address_intelligence_analysis';
    private const SESSION_CONFIRMATION_KEY = 'col_address_intelligence_confirmation';

    public function __construct(
        private COL_Address_Intelligence $intelligence
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_assets']);
        add_action('woocommerce_after_order_notes', [$this, 'render_checkout_confirmation']);
        add_action('wp_ajax_col_address_intelligence_suggest', [$this, 'ajax_suggest']);
        add_action('wp_ajax_nopriv_col_address_intelligence_suggest', [$this, 'ajax_suggest']);
        add_action('wp_ajax_col_address_intelligence_confirm', [$this, 'ajax_confirm']);
        add_action('wp_ajax_nopriv_col_address_intelligence_confirm', [$this, 'ajax_confirm']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_confirmation'], 20, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_order_meta'], 30, 2);
    }

    public function enqueue_checkout_assets(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'col-address-intelligence-checkout',
            COL_PLUGIN_URL . 'includes/address-intelligence-checkout.js',
            ['jquery'],
            COL_VERSION,
            true
        );

        wp_localize_script('col-address-intelligence-checkout', 'colAddressIntelligence', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('col_address_intelligence_nonce'),
            'minConfidence' => 70,
            'labels' => [
                'checking' => __('Menganalisis alamat...', 'checkout-ongkir-lokal'),
                'confirmTitle' => __('Konfirmasi area pengiriman', 'checkout-ongkir-lokal'),
                'selectPlaceholder' => __('Pilih kecamatan / kode pos', 'checkout-ongkir-lokal'),
                'highConfidence' => __('Alamat terdeteksi dengan confidence tinggi.', 'checkout-ongkir-lokal'),
            ],
        ]);
    }

    public function render_checkout_confirmation(): void
    {
        echo '<div id="col-address-intelligence" style="margin-top:12px;">';
        echo '<h3>' . esc_html__('Address Intelligence', 'checkout-ongkir-lokal') . '</h3>';
        echo '<p id="col-ai-status" style="font-size:13px;color:#666;"></p>';
        echo '<div id="col-ai-confirmation" style="display:none;">';
        echo '<label for="col_ai_suggestion_select">' . esc_html__('Konfirmasi Kecamatan/Kode Pos', 'checkout-ongkir-lokal') . '</label>';
        echo '<select id="col_ai_suggestion_select" class="input-select"><option value="">' . esc_html__('Pilih kecamatan / kode pos', 'checkout-ongkir-lokal') . '</option></select>';
        echo '<p id="col-ai-warning" style="color:#b32d2e;font-size:13px;margin-top:6px;"></p>';
        echo '</div>';
        echo '</div>';
    }

    public function ajax_suggest(): void
    {
        $this->verify_nonce();

        $raw_address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
        $result = $this->intelligence->suggest($raw_address);

        if (WC()->session) {
            WC()->session->set(self::SESSION_ANALYSIS_KEY, [
                'raw' => $raw_address,
                'normalized' => $result['normalized'],
                'confidence' => $result['confidence'],
                'ambiguous' => $result['ambiguous'],
                'suggestions' => $result['suggestions'],
            ]);

            if (! $result['ambiguous']) {
                $best = $result['suggestions'][0] ?? [];
                WC()->session->set(self::SESSION_CONFIRMATION_KEY, $best);
            }
        }

        wp_send_json_success($result);
    }

    public function ajax_confirm(): void
    {
        $this->verify_nonce();

        $raw = isset($_POST['suggestion']) ? wp_unslash((string) $_POST['suggestion']) : '';
        $suggestion = json_decode($raw, true);
        if (! is_array($suggestion) || empty($suggestion['district'])) {
            if (WC()->session) {
                WC()->session->set(self::SESSION_CONFIRMATION_KEY, null);
            }
            wp_send_json_error(['message' => 'invalid_suggestion']);
        }

        if (WC()->session) {
            WC()->session->set(self::SESSION_CONFIRMATION_KEY, $suggestion);
        }

        wp_send_json_success(['stored' => true]);
    }

    public function validate_confirmation(array $data, WP_Error $errors): void
    {
        if (! WC()->session) {
            return;
        }

        $analysis = WC()->session->get(self::SESSION_ANALYSIS_KEY);
        $confirmation = WC()->session->get(self::SESSION_CONFIRMATION_KEY);

        if (! is_array($analysis)) {
            return;
        }

        $requires_confirmation = ! empty($analysis['ambiguous']) || (int) ($analysis['confidence'] ?? 0) < 70;
        if ($requires_confirmation && ! is_array($confirmation)) {
            $errors->add('col_ai_confirmation_required', __('Alamat ambigu. Silakan konfirmasi kecamatan/kode pos terlebih dulu.', 'checkout-ongkir-lokal'));
        }
    }

    public function save_order_meta(WC_Order $order): void
    {
        if (! WC()->session) {
            return;
        }

        $analysis = WC()->session->get(self::SESSION_ANALYSIS_KEY);
        if (! is_array($analysis)) {
            return;
        }

        $order->update_meta_data('_col_raw_address', (string) ($analysis['raw'] ?? ''));
        $order->update_meta_data('_col_normalized_address', (string) ($analysis['normalized'] ?? ''));
        $order->update_meta_data('_col_address_confidence', (int) ($analysis['confidence'] ?? 0));

        $confirmation = WC()->session->get(self::SESSION_CONFIRMATION_KEY);
        if (is_array($confirmation)) {
            $order->update_meta_data('_col_address_confirmed_area', $confirmation);
        }
    }

    private function verify_nonce(): void
    {
        check_ajax_referer('col_address_intelligence_nonce', 'nonce');
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_confirmed_area_from_session(): array
    {
        if (! function_exists('WC') || ! WC()->session) {
            return [];
        }

        $data = WC()->session->get(self::SESSION_CONFIRMATION_KEY);
        return is_array($data) ? $data : [];
    }
}
