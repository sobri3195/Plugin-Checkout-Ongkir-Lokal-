<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_COD_Risk_Service
{
    private string $session_key = 'col_cod_risk_latest';

    public function __construct(private COL_Settings $settings, private COL_Rule_Engine $rule_engine, private COL_Logger $logger)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_checkout_update_order_review', [$this, 'capture_checkout_refresh']);
        add_filter('woocommerce_available_payment_gateways', [$this, 'enforce_cod_policy']);
        add_action('woocommerce_checkout_create_order', [$this, 'persist_order_risk_meta'], 30, 2);
        add_action('wc_ajax_col_cod_risk_preview', [$this, 'preview_score_endpoint']);
        add_action('wp_ajax_col_cod_risk_preview', [$this, 'preview_score_endpoint']);
    }

    public function capture_checkout_refresh(string $post_data): void
    {
        if (! WC()->session || ! $this->is_enabled()) {
            return;
        }

        parse_str($post_data, $parsed);
        $evaluation = $this->evaluate_from_checkout_payload(is_array($parsed) ? $parsed : []);
        WC()->session->set($this->session_key, $evaluation);
    }

    public function enforce_cod_policy(array $gateways): array
    {
        if (! isset($gateways['cod']) || ! $this->is_enabled()) {
            return $gateways;
        }

        $evaluation = $this->get_latest_evaluation();
        if (! is_array($evaluation)) {
            return $gateways;
        }

        if (($evaluation['policy'] ?? '') === 'block_cod') {
            unset($gateways['cod']);
            wc_add_notice(__('COD tidak tersedia untuk profil risiko order ini.', 'checkout-ongkir-lokal'), 'notice');
        }

        if (($evaluation['policy'] ?? '') === 'allow_with_conditions') {
            $gateways['cod']->title = $gateways['cod']->title . ' - ' . __('Perlu verifikasi tambahan', 'checkout-ongkir-lokal');
            wc_add_notice(__('COD diizinkan dengan syarat tambahan: verifikasi nomor telepon saat konfirmasi.', 'checkout-ongkir-lokal'), 'notice');
        }

        return $gateways;
    }

    public function persist_order_risk_meta(WC_Order $order, array $data): void
    {
        if (! $this->is_enabled()) {
            return;
        }

        $evaluation = $this->get_latest_evaluation();
        if (! is_array($evaluation)) {
            $evaluation = $this->evaluate_from_checkout_payload($data);
        }

        $order->update_meta_data('_col_cod_risk_score', (int) ($evaluation['score'] ?? 0));
        $order->update_meta_data('_col_cod_risk_policy', (string) ($evaluation['policy'] ?? 'normal'));
        $order->update_meta_data('_col_cod_risk_reasons', (array) ($evaluation['reasons'] ?? []));
        $order->update_meta_data('_col_cod_risk_signals', (array) ($evaluation['signal_scores'] ?? []));

        $this->logger->info('cod_risk_persisted', 'Risk scoring disimpan ke order meta', [
            'order_id' => $order->get_id(),
            'score' => (int) ($evaluation['score'] ?? 0),
            'policy' => (string) ($evaluation['policy'] ?? 'normal'),
            'reasons' => (array) ($evaluation['reasons'] ?? []),
        ]);
    }

    public function preview_score_endpoint(): void
    {
        if (! $this->is_enabled()) {
            wp_send_json_error(['message' => 'COD risk scoring disabled'], 400);
        }

        $payload = [
            'billing_email' => isset($_POST['billing_email']) ? sanitize_email(wp_unslash((string) $_POST['billing_email'])) : '',
            'billing_phone' => isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash((string) $_POST['billing_phone'])) : '',
            'shipping_address_1' => isset($_POST['shipping_address_1']) ? sanitize_text_field(wp_unslash((string) $_POST['shipping_address_1'])) : '',
            'shipping_postcode' => isset($_POST['shipping_postcode']) ? sanitize_text_field(wp_unslash((string) $_POST['shipping_postcode'])) : '',
            'shipping_state' => isset($_POST['shipping_state']) ? sanitize_text_field(wp_unslash((string) $_POST['shipping_state'])) : '',
        ];

        $evaluation = $this->evaluate_from_checkout_payload($payload);

        if (WC()->session) {
            WC()->session->set($this->session_key, $evaluation);
        }

        wp_send_json_success($evaluation);
    }

    private function evaluate_from_checkout_payload(array $payload): array
    {
        $email = (string) ($payload['billing_email'] ?? '');
        $phone = (string) ($payload['billing_phone'] ?? '');
        $history = $this->build_customer_history($email, $phone);

        $origin_list = [];
        if (WC()->session) {
            $plan_payload = WC()->session->get('col_last_plan_payload');
            if (is_array($plan_payload)) {
                $origin_list = array_map('strval', (array) ($plan_payload['origin_list'] ?? []));
            }
        }

        $context = [
            'cart_total' => WC()->cart ? (float) WC()->cart->get_subtotal() : 0,
            'destination_district_code' => (string) ($payload['shipping_state'] ?? ''),
            'destination_postcode' => (string) ($payload['shipping_postcode'] ?? ''),
            'address_line' => (string) ($payload['shipping_address_1'] ?? ''),
            'order_hour' => (int) current_time('G'),
            'origin_list' => $origin_list,
            'cancel_count' => $history['cancel_count'],
            'rto_count' => $history['rto_count'],
            'completed_count' => $history['completed_count'],
        ];

        return $this->rule_engine->evaluate_cod_risk($context);
    }

    private function build_customer_history(string $email, string $phone): array
    {
        $customer_id = get_current_user_id();
        $orders = wc_get_orders([
            'limit' => 20,
            'customer_id' => $customer_id > 0 ? $customer_id : 0,
            'billing_email' => $email !== '' ? $email : null,
            'billing_phone' => $phone !== '' ? $phone : null,
            'return' => 'objects',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $cancel = 0;
        $rto = 0;
        $completed = 0;
        foreach ($orders as $order) {
            $status = $order->get_status();
            if (in_array($status, ['cancelled', 'failed', 'refunded'], true)) {
                $cancel++;
            }

            if ((bool) $order->get_meta('_col_order_rto_flag', true) || $status === 'rto') {
                $rto++;
            }

            if ($status === 'completed') {
                $completed++;
            }
        }

        return [
            'cancel_count' => $cancel,
            'rto_count' => $rto,
            'completed_count' => $completed,
        ];
    }

    private function is_enabled(): bool
    {
        $settings = $this->settings->all();
        return ($settings['cod_risk_enabled'] ?? 'yes') === 'yes';
    }

    private function get_latest_evaluation(): ?array
    {
        if (! WC()->session) {
            return null;
        }

        $evaluation = WC()->session->get($this->session_key);
        return is_array($evaluation) ? $evaluation : null;
    }
}
