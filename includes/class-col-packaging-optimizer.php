<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Packaging_Optimizer
{
    public function optimize(array $items, array $box_presets, array $volumetric_divisors, array $fallback_dimension_cm = []): array
    {
        $fallback_length = max(1, (int) ($fallback_dimension_cm['length'] ?? 10));
        $fallback_width = max(1, (int) ($fallback_dimension_cm['width'] ?? 10));
        $fallback_height = max(1, (int) ($fallback_dimension_cm['height'] ?? 10));

        $units = $this->expand_units($items, $fallback_length, $fallback_width, $fallback_height);
        usort($units, static function (array $a, array $b): int {
            return [$b['volume_cm3'], $b['weight_gram']] <=> [$a['volume_cm3'], $a['weight_gram']];
        });

        $boxes = $this->normalize_boxes($box_presets);
        $packages = [];

        foreach ($units as $unit) {
            $placed = false;
            foreach ($packages as $index => $package) {
                if ($this->can_fit_into_package($unit, $package)) {
                    $packages[$index] = $this->place_unit($unit, $package);
                    $placed = true;
                    break;
                }
            }

            if ($placed) {
                continue;
            }

            $box = $this->pick_smallest_box_for_unit($unit, $boxes);
            if ($box === null) {
                $box = [
                    'id' => 'custom-' . $unit['product_id'],
                    'name' => 'Custom package',
                    'inner_length_cm' => $unit['length_cm'],
                    'inner_width_cm' => $unit['width_cm'],
                    'inner_height_cm' => $unit['height_cm'],
                    'max_weight_gram' => max(50000, $unit['weight_gram']),
                    'volume_cm3' => $unit['volume_cm3'],
                ];
            }

            $packages[] = $this->place_unit($unit, [
                'box' => $box,
                'used_volume_cm3' => 0,
                'actual_weight_gram' => 0,
                'items' => [],
            ]);
        }

        return $this->finalize_packages($packages, $volumetric_divisors);
    }

    private function expand_units(array $items, int $fallback_length, int $fallback_width, int $fallback_height): array
    {
        $units = [];
        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $length = max(1, (int) ($item['unit_length_cm'] ?? 0));
            $width = max(1, (int) ($item['unit_width_cm'] ?? 0));
            $height = max(1, (int) ($item['unit_height_cm'] ?? 0));

            $fallback_used = false;
            if (
                empty($item['unit_length_cm'])
                || empty($item['unit_width_cm'])
                || empty($item['unit_height_cm'])
            ) {
                $length = $fallback_length;
                $width = $fallback_width;
                $height = $fallback_height;
                $fallback_used = true;
            }

            $volume = $length * $width * $height;
            for ($i = 0; $i < $quantity; $i++) {
                $units[] = [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'weight_gram' => max(1, (int) ($item['unit_weight_gram'] ?? 1)),
                    'length_cm' => $length,
                    'width_cm' => $width,
                    'height_cm' => $height,
                    'volume_cm3' => $volume,
                    'dimension_fallback_used' => $fallback_used,
                ];
            }
        }

        return $units;
    }

    private function normalize_boxes(array $box_presets): array
    {
        $boxes = [];
        foreach ($box_presets as $preset) {
            $length = max(1, (int) ($preset['inner_length_cm'] ?? 0));
            $width = max(1, (int) ($preset['inner_width_cm'] ?? 0));
            $height = max(1, (int) ($preset['inner_height_cm'] ?? 0));
            $boxes[] = [
                'id' => (string) ($preset['id'] ?? 'box-' . (count($boxes) + 1)),
                'name' => (string) ($preset['name'] ?? 'Box ' . (count($boxes) + 1)),
                'inner_length_cm' => $length,
                'inner_width_cm' => $width,
                'inner_height_cm' => $height,
                'max_weight_gram' => max(1, (int) ($preset['max_weight_gram'] ?? 30000)),
                'volume_cm3' => $length * $width * $height,
            ];
        }

        usort($boxes, static fn(array $a, array $b): int => $a['volume_cm3'] <=> $b['volume_cm3']);
        return $boxes;
    }

    private function pick_smallest_box_for_unit(array $unit, array $boxes): ?array
    {
        foreach ($boxes as $box) {
            if ($this->can_unit_fit_box($unit, $box)) {
                return $box;
            }
        }

        return null;
    }

    private function can_fit_into_package(array $unit, array $package): bool
    {
        $box = $package['box'];
        if (! $this->can_unit_fit_box($unit, $box)) {
            return false;
        }

        if (($package['actual_weight_gram'] + $unit['weight_gram']) > $box['max_weight_gram']) {
            return false;
        }

        return ($package['used_volume_cm3'] + $unit['volume_cm3']) <= $box['volume_cm3'];
    }

    private function can_unit_fit_box(array $unit, array $box): bool
    {
        return $unit['length_cm'] <= $box['inner_length_cm']
            && $unit['width_cm'] <= $box['inner_width_cm']
            && $unit['height_cm'] <= $box['inner_height_cm'];
    }

    private function place_unit(array $unit, array $package): array
    {
        $items = $package['items'];
        $key = (string) $unit['product_id'];

        if (! isset($items[$key])) {
            $items[$key] = [
                'product_id' => $unit['product_id'],
                'quantity' => 0,
                'dimension_fallback_used' => false,
            ];
        }

        $items[$key]['quantity']++;
        $items[$key]['dimension_fallback_used'] = $items[$key]['dimension_fallback_used'] || $unit['dimension_fallback_used'];

        $package['items'] = $items;
        $package['used_volume_cm3'] += $unit['volume_cm3'];
        $package['actual_weight_gram'] += $unit['weight_gram'];

        return $package;
    }

    private function finalize_packages(array $packages, array $volumetric_divisors): array
    {
        $normalized_divisors = [];
        foreach ($volumetric_divisors as $courier => $divisor) {
            $normalized_divisors[(string) $courier] = max(1, (int) $divisor);
        }

        if (empty($normalized_divisors)) {
            $normalized_divisors = ['default' => 6000];
        }

        $result = [];
        $chargeable_total_by_courier = array_fill_keys(array_keys($normalized_divisors), 0);

        foreach ($packages as $index => $package) {
            $volumetric_weight_by_courier = [];
            $chargeable_weight_by_courier = [];

            foreach ($normalized_divisors as $courier => $divisor) {
                $volumetric = (int) ceil(($package['used_volume_cm3'] * 1000) / $divisor);
                $chargeable = max($package['actual_weight_gram'], $volumetric);
                $volumetric_weight_by_courier[$courier] = $volumetric;
                $chargeable_weight_by_courier[$courier] = $chargeable;
                $chargeable_total_by_courier[$courier] += $chargeable;
            }

            $result[] = [
                'package_no' => $index + 1,
                'box' => $package['box'],
                'items' => array_values($package['items']),
                'actual_weight_gram' => $package['actual_weight_gram'],
                'volume_cm3' => $package['used_volume_cm3'],
                'volumetric_weight_gram_by_courier' => $volumetric_weight_by_courier,
                'chargeable_weight_gram_by_courier' => $chargeable_weight_by_courier,
            ];
        }

        return [
            'packages' => $result,
            'chargeable_total_by_courier' => $chargeable_total_by_courier,
            'dimension_fallback_used' => (bool) array_filter($result, static function (array $package): bool {
                foreach ($package['items'] as $item) {
                    if (! empty($item['dimension_fallback_used'])) {
                        return true;
                    }
                }

                return false;
            }),
        ];
    }
}
