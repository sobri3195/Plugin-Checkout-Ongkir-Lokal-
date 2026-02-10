<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Shipping_Recommendation_Engine
{
    public function score_rates(array $rates, float $cart_total, array $settings = []): array
    {
        if (empty($rates)) {
            return [
                'is_available' => false,
                'scores' => [],
                'recommended_rate_id' => '',
                'badges' => [],
            ];
        }

        $weights = wp_parse_args($settings['smart_shipping_weights'] ?? [], [
            'price' => 35,
            'eta' => 25,
            'reliability' => 20,
            'margin_impact' => 20,
        ]);

        $reliability_map = wp_parse_args($settings['courier_reliability'] ?? [], [
            'jne' => 85,
            'jnt' => 78,
            'anteraja' => 80,
            'backup' => 60,
            'default' => 70,
        ]);

        $prices = array_column($rates, 'price');
        $etas = array_map(fn(array $rate): int => $this->extract_eta_days_max((string) ($rate['eta_label'] ?? '')), $rates);

        if (empty($prices) || min($prices) <= 0 || min($etas) <= 0 || $cart_total <= 0) {
            return [
                'is_available' => false,
                'scores' => [],
                'recommended_rate_id' => '',
                'badges' => $this->build_fallback_badges($rates, $prices, $etas),
            ];
        }

        $min_price = (int) min($prices);
        $max_price = (int) max($prices);
        $min_eta = (int) min($etas);
        $max_eta = (int) max($etas);

        $scores = [];
        foreach ($rates as $index => $rate) {
            $price = (int) ($rate['price'] ?? 0);
            $eta = $etas[$index] ?? 0;
            $courier = strtolower((string) ($rate['courier'] ?? ''));
            $reliability = (int) ($reliability_map[$courier] ?? $reliability_map['default']);
            $margin_ratio = min(1, max(0, $price / max(1, $cart_total)));

            $price_score = $this->normalize_inverse($price, $min_price, $max_price);
            $eta_score = $this->normalize_inverse($eta, $min_eta, $max_eta);
            $reliability_score = max(0, min(100, $reliability));
            $margin_score = (int) round((1 - $margin_ratio) * 100);

            $total = (
                $price_score * ($weights['price'] / 100)
                + $eta_score * ($weights['eta'] / 100)
                + $reliability_score * ($weights['reliability'] / 100)
                + $margin_score * ($weights['margin_impact'] / 100)
            );

            $scores[$rate['rate_id']] = [
                'score' => round($total, 2),
                'price_score' => $price_score,
                'eta_score' => $eta_score,
                'reliability_score' => $reliability_score,
                'margin_score' => $margin_score,
                'price' => $price,
                'eta_days_max' => $eta,
            ];
        }

        uasort($scores, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $recommended_rate_id = (string) array_key_first($scores);

        return [
            'is_available' => $recommended_rate_id !== '',
            'scores' => $scores,
            'recommended_rate_id' => $recommended_rate_id,
            'badges' => $this->build_badges($rates, $scores),
        ];
    }

    private function build_badges(array $rates, array $scores): array
    {
        $badges = [];
        if (empty($rates)) {
            return $badges;
        }

        $prices = array_column($rates, 'price', 'rate_id');
        $etas = [];
        foreach ($rates as $rate) {
            $etas[$rate['rate_id']] = $this->extract_eta_days_max((string) ($rate['eta_label'] ?? ''));
        }

        $cheapest = (string) array_key_first(array_filter($prices, static fn($v): bool => $v === min($prices)));
        $fastest = (string) array_key_first(array_filter($etas, static fn($v): bool => $v === min($etas)));
        $bestValue = (string) array_key_first($scores);

        if ($bestValue !== '') {
            $badges[$bestValue][] = 'Best Value';
        }
        if ($fastest !== '') {
            $badges[$fastest][] = 'Fastest';
        }
        if ($cheapest !== '') {
            $badges[$cheapest][] = 'Cheapest';
        }

        return $badges;
    }

    private function build_fallback_badges(array $rates, array $prices, array $etas): array
    {
        if (empty($rates)) {
            return [];
        }

        $priceMap = array_column($rates, 'price', 'rate_id');
        $etaMap = [];
        foreach ($rates as $index => $rate) {
            $etaMap[$rate['rate_id']] = (int) ($etas[$index] ?? 0);
        }

        $badges = [];
        if (! empty($priceMap)) {
            $cheapest = (string) array_key_first(array_filter($priceMap, static fn($v): bool => $v === min($priceMap)));
            if ($cheapest !== '') {
                $badges[$cheapest][] = 'Cheapest';
            }
        }

        if (! empty($etaMap)) {
            $fastest = (string) array_key_first(array_filter($etaMap, static fn($v): bool => $v === min($etaMap)));
            if ($fastest !== '') {
                $badges[$fastest][] = 'Fastest';
            }
        }

        return $badges;
    }

    private function normalize_inverse(int $value, int $min, int $max): int
    {
        if ($max <= $min) {
            return 100;
        }

        $normalized = (($max - $value) / ($max - $min)) * 100;
        return (int) round(max(0, min(100, $normalized)));
    }

    private function extract_eta_days_max(string $eta_label): int
    {
        preg_match_all('/\d+/', $eta_label, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);
        if (empty($numbers)) {
            return 3;
        }

        return max(1, max($numbers));
    }
}
