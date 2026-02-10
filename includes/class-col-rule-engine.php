<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Rule_Engine
{
    private array $risk_signals = ['order_value', 'area_distance', 'customer_history', 'address_quality', 'order_time'];

    public function __construct(private COL_Settings $settings, private COL_Logger $logger)
    {
    }

    public function apply_cod_rules(array $context): array
    {
        $allow_cod = true;
        $reason = 'default_allow';

        // Priority policy:
        // 1) Explicit deny rules with highest priority.
        // 2) Explicit allow rules.
        // 3) Global defaults.
        // Rule source table: wp_col_cod_rules.

        if (($context['destination_city'] ?? '') === 'Kab. Kepulauan Mentawai') {
            $allow_cod = false;
            $reason = 'deny_remote_city';
        }

        if (($context['cart_total'] ?? 0) < 75000) {
            $allow_cod = false;
            $reason = 'min_order_not_met';
        }

        if (in_array('fragile', $context['product_tags'] ?? [], true)) {
            $allow_cod = false;
            $reason = 'fragile_product_excluded';
        }

        $this->logger->info('cod_rule_eval', 'Evaluasi COD selesai', [
            'allow_cod' => $allow_cod,
            'reason' => $reason,
        ]);

        return [
            'allow_cod' => $allow_cod,
            'reason' => $reason,
        ];
    }

    public function apply_surcharge_and_override(array $rate, array $context): array
    {
        $original = $rate['price'];

        if (($context['destination_district_code'] ?? '') === '3173040') {
            // Example override: multiplier 1.15 for a district.
            $rate['price'] = (int) round($rate['price'] * 1.15);
            $rate['override_applied'] = 'district_multiplier_1_15';
        }

        if (! empty($context['is_remote_area'])) {
            $rate['price'] += 7000;
            $rate['surcharge_applied'] = 'remote_area_flat_7000';
        }

        $this->logger->info('rate_rule_eval', 'Surcharge/override dievaluasi', [
            'original_price' => $original,
            'new_price' => $rate['price'],
            'override_applied' => $rate['override_applied'] ?? '',
            'surcharge_applied' => $rate['surcharge_applied'] ?? '',
        ]);

        return $rate;
    }

    public function evaluate_cod_risk(array $context): array
    {
        $settings = $this->settings->all();
        $weights = is_array($settings['cod_risk_weights'] ?? null) ? $settings['cod_risk_weights'] : [];

        $signal_scores = [
            'order_value' => $this->score_order_value((float) ($context['cart_total'] ?? 0)),
            'area_distance' => $this->score_area_distance((string) ($context['destination_district_code'] ?? ''), (array) ($context['origin_list'] ?? [])),
            'customer_history' => $this->score_customer_history((int) ($context['cancel_count'] ?? 0), (int) ($context['rto_count'] ?? 0), (int) ($context['completed_count'] ?? 0)),
            'address_quality' => $this->score_address_quality((string) ($context['address_line'] ?? ''), (string) ($context['destination_postcode'] ?? '')),
            'order_time' => $this->score_order_time((int) ($context['order_hour'] ?? (int) current_time('G')), (array) ($settings['cod_risk_risky_hours'] ?? [])),
        ];

        $total_weight = 0;
        $weighted_score = 0.0;
        foreach ($this->risk_signals as $signal) {
            $weight = max(0, (int) ($weights[$signal] ?? 0));
            $total_weight += $weight;
            $weighted_score += $signal_scores[$signal] * $weight;
        }

        $score = $total_weight > 0 ? (int) round($weighted_score / $total_weight) : 0;
        $block_threshold = (int) ($settings['cod_risk_block_threshold'] ?? 80);
        $review_threshold = (int) ($settings['cod_risk_review_threshold'] ?? 60);

        $policy = 'normal';
        if ($score >= $block_threshold) {
            $policy = 'block_cod';
        } elseif ($score >= $review_threshold) {
            $policy = 'allow_with_conditions';
        }

        $reasons = $this->build_reasons($signal_scores);

        $this->logger->info('cod_risk_eval', 'Risk scoring COD dievaluasi', [
            'score' => $score,
            'policy' => $policy,
            'signal_scores' => $signal_scores,
            'reasons' => $reasons,
        ]);

        return [
            'score' => max(0, min(100, $score)),
            'policy' => $policy,
            'signal_scores' => $signal_scores,
            'reasons' => $reasons,
        ];
    }

    private function score_order_value(float $cart_total): int
    {
        if ($cart_total >= 1000000) {
            return 90;
        }

        if ($cart_total >= 500000) {
            return 70;
        }

        if ($cart_total >= 250000) {
            return 45;
        }

        return 20;
    }

    private function score_area_distance(string $destination_district_code, array $origin_list): int
    {
        if ($destination_district_code === '') {
            return 50;
        }

        if (in_array($destination_district_code, $origin_list, true)) {
            return 15;
        }

        return 75;
    }

    private function score_customer_history(int $cancel_count, int $rto_count, int $completed_count): int
    {
        $negative = ($cancel_count * 12) + ($rto_count * 20);
        $positive_reduction = min(30, $completed_count * 4);
        return max(10, min(100, 20 + $negative - $positive_reduction));
    }

    private function score_address_quality(string $address_line, string $postcode): int
    {
        $quality = 100;
        $length = strlen(trim($address_line));
        if ($length >= 24) {
            $quality -= 35;
        }

        if (preg_match('/\d+/', $address_line) === 1) {
            $quality -= 20;
        }

        if (strlen($postcode) >= 5) {
            $quality -= 20;
        }

        return max(5, min(100, $quality));
    }

    private function score_order_time(int $hour, array $risky_hours): int
    {
        $hour = max(0, min(23, $hour));
        if (in_array($hour, array_map('intval', $risky_hours), true)) {
            return 85;
        }

        return 25;
    }

    private function build_reasons(array $signal_scores): array
    {
        $reasons = [];
        foreach ($signal_scores as $signal => $score) {
            if ($score >= 70) {
                $reasons[] = $signal . '_high_risk';
            } elseif ($score >= 45) {
                $reasons[] = $signal . '_medium_risk';
            }
        }

        return empty($reasons) ? ['low_risk_profile'] : $reasons;
    }
}
