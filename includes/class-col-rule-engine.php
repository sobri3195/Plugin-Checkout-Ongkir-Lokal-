<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Rule_Engine
{
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
}
