<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Shipment_Planner
{
    public function __construct(private COL_Origin_Repository $origin_repository)
    {
    }

    public function build_plan(array $cart_lines): array
    {
        $product_ids = array_map(static fn(array $line): int => (int) $line['product_id'], $cart_lines);
        $warehouses = $this->index_warehouses($this->origin_repository->get_active_warehouses());
        $origin_map = $this->origin_repository->get_product_origin_map($product_ids);

        $single = $this->build_single_origin_plan($cart_lines, $origin_map, $warehouses);
        $split = $this->build_split_plan($cart_lines, $origin_map, $warehouses);

        return [
            'single_origin' => $single,
            'split_shipment' => $split,
        ];
    }

    private function build_single_origin_plan(array $cart_lines, array $origin_map, array $warehouses): array
    {
        $candidates = [];

        foreach ($cart_lines as $line) {
            $product_id = (int) $line['product_id'];
            $qty = (int) $line['quantity'];
            $mappings = $origin_map[$product_id] ?? [];

            foreach ($mappings as $mapping) {
                if ((int) $mapping['stock_qty'] < $qty) {
                    continue;
                }

                $warehouse_id = (int) $mapping['warehouse_id'];
                $candidates[$warehouse_id] = ($candidates[$warehouse_id] ?? 0) + (int) $mapping['priority'];
            }
        }

        if (count($candidates) !== 0) {
            asort($candidates);
            foreach ($candidates as $warehouse_id => $score) {
                if ($this->can_fulfill_all_lines($warehouse_id, $cart_lines, $origin_map)) {
                    return [
                        'is_available' => true,
                        'shipments' => [
                            $this->build_shipment($warehouse_id, $cart_lines, $warehouses),
                        ],
                        'score' => $score,
                    ];
                }
            }
        }

        return [
            'is_available' => false,
            'shipments' => [],
            'score' => PHP_INT_MAX,
        ];
    }

    private function build_split_plan(array $cart_lines, array $origin_map, array $warehouses): array
    {
        $shipments = [];
        $score = 0;

        foreach ($cart_lines as $line) {
            $product_id = (int) $line['product_id'];
            $remaining_qty = (int) $line['quantity'];
            $unit_weight = (int) $line['unit_weight_gram'];
            $mappings = $origin_map[$product_id] ?? [];

            usort($mappings, static function (array $a, array $b): int {
                return [$a['is_fallback'] ? 1 : 0, (int) $a['priority']] <=> [$b['is_fallback'] ? 1 : 0, (int) $b['priority']];
            });

            foreach ($mappings as $mapping) {
                if ($remaining_qty <= 0) {
                    break;
                }

                $available = (int) $mapping['stock_qty'];
                if ($available <= 0) {
                    continue;
                }

                $allocated = min($remaining_qty, $available);
                $warehouse_id = (int) $mapping['warehouse_id'];
                if (! isset($shipments[$warehouse_id])) {
                    $shipments[$warehouse_id] = $this->build_shipment($warehouse_id, [], $warehouses);
                }

                $shipments[$warehouse_id]['items'][] = [
                    'product_id' => $product_id,
                    'quantity' => $allocated,
                    'unit_weight_gram' => $unit_weight,
                ];
                $shipments[$warehouse_id]['weight_gram'] += $allocated * $unit_weight;
                $score += ((int) $mapping['priority'] * $allocated);
                $remaining_qty -= $allocated;
            }

            if ($remaining_qty > 0) {
                return [
                    'is_available' => false,
                    'shipments' => [],
                    'score' => PHP_INT_MAX,
                ];
            }
        }

        return [
            'is_available' => true,
            'shipments' => array_values($shipments),
            'score' => $score,
        ];
    }

    private function index_warehouses(array $warehouses): array
    {
        $indexed = [];
        foreach ($warehouses as $warehouse) {
            $indexed[(int) $warehouse['id']] = $warehouse;
        }

        return $indexed;
    }

    private function can_fulfill_all_lines(int $warehouse_id, array $cart_lines, array $origin_map): bool
    {
        foreach ($cart_lines as $line) {
            $product_id = (int) $line['product_id'];
            $qty = (int) $line['quantity'];
            $found = false;

            foreach ($origin_map[$product_id] ?? [] as $mapping) {
                if ((int) $mapping['warehouse_id'] === $warehouse_id && (int) $mapping['stock_qty'] >= $qty) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function build_shipment(int $warehouse_id, array $items, array $warehouses): array
    {
        $warehouse = $warehouses[$warehouse_id] ?? [
            'id' => $warehouse_id,
            'name' => 'Warehouse ' . $warehouse_id,
            'address' => '',
            'region_code' => '',
            'priority' => 999,
        ];

        $weight = 0;
        foreach ($items as $item) {
            $weight += (int) $item['quantity'] * (int) $item['unit_weight_gram'];
        }

        return [
            'warehouse_id' => $warehouse_id,
            'warehouse_name' => $warehouse['name'],
            'origin_region_code' => $warehouse['region_code'],
            'items' => $items,
            'weight_gram' => $weight,
        ];
    }
}
