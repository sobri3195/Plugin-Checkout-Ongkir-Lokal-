<?php

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/interface-col-pickup-point-provider.php';

class COL_Pickup_Point_Provider implements COL_Pickup_Point_Provider_Interface
{
    public function __construct(private COL_Settings $settings)
    {
    }

    public function get_pickup_points(string $city, string $postcode): array
    {
        $city = trim($city);
        $postcode = trim($postcode);

        if ($city === '' && $postcode === '') {
            return [];
        }

        $provider_enabled = (bool) apply_filters('col_pickup_point_realtime_enabled', true, $city, $postcode);
        if (! $provider_enabled) {
            return [];
        }

        $normalized_city = strtoupper($city !== '' ? $city : 'KOTA');
        $base_lat = -6.2;
        $base_lng = 106.8;

        return [
            [
                'id' => sanitize_title($normalized_city . '-' . $postcode . '-a'),
                'name' => sprintf('PUDO %s Central Hub', $normalized_city),
                'address' => sprintf('Jl. Utama No. 1, %s %s', $normalized_city, $postcode),
                'operating_hours' => '08:00 - 21:00',
                'coordinates' => ['lat' => $base_lat, 'lng' => $base_lng],
                'source' => 'realtime',
            ],
            [
                'id' => sanitize_title($normalized_city . '-' . $postcode . '-b'),
                'name' => sprintf('PUDO %s Station', $normalized_city),
                'address' => sprintf('Jl. Alternatif No. 22, %s %s', $normalized_city, $postcode),
                'operating_hours' => '09:00 - 20:00',
                'coordinates' => ['lat' => $base_lat + 0.01, 'lng' => $base_lng + 0.01],
                'source' => 'realtime',
            ],
        ];
    }
}
