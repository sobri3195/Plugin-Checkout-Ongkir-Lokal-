<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Shipment_Rate_Aggregator
{
    public function aggregate(array $shipment_rates): array
    {
        $combined = [];

        foreach ($shipment_rates as $shipment_rate) {
            foreach ($shipment_rate as $rate) {
                $key = sanitize_title($rate['courier'] . '_' . $rate['service']);
                if (! isset($combined[$key])) {
                    $combined[$key] = [
                        'courier' => $rate['courier'],
                        'service' => $rate['service'],
                        'eta_days_max' => 0,
                        'shipment_count' => 0,
                        'price' => 0,
                    ];
                }

                $combined[$key]['shipment_count']++;
                $combined[$key]['price'] += (int) $rate['price'];
                $combined[$key]['eta_days_max'] = max($combined[$key]['eta_days_max'], $this->extract_max_eta_days((string) $rate['eta_label']));
            }
        }

        $normalized = [];
        foreach ($combined as $rate) {
            $rate['eta_label'] = sprintf('%d hari', max(1, $rate['eta_days_max']));
            $normalized[] = $rate;
        }

        return $normalized;
    }

    private function extract_max_eta_days(string $eta_label): int
    {
        preg_match_all('/\d+/', $eta_label, $matches);
        $digits = array_map('intval', $matches[0] ?? []);
        return ! empty($digits) ? max($digits) : 1;
    }
}
