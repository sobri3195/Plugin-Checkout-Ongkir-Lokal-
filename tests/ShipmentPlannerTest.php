<?php

use PHPUnit\Framework\TestCase;

class Fake_Origin_Repository extends COL_Origin_Repository
{
    public function __construct(private array $warehouses, private array $map)
    {
    }

    public function get_active_warehouses(): array
    {
        return $this->warehouses;
    }

    public function get_product_origin_map(array $product_ids): array
    {
        return $this->map;
    }
}

final class ShipmentPlannerTest extends TestCase
{
    public function test_single_origin_selected_when_one_warehouse_can_fulfill_all_items(): void
    {
        $planner = new COL_Shipment_Planner(new Fake_Origin_Repository(
            [
                ['id' => 1, 'name' => 'Gudang A', 'region_code' => 'JKT', 'priority' => 1],
                ['id' => 2, 'name' => 'Gudang B', 'region_code' => 'BDG', 'priority' => 2],
            ],
            [
                10 => [
                    ['warehouse_id' => 1, 'stock_qty' => 5, 'priority' => 1, 'is_fallback' => false],
                    ['warehouse_id' => 2, 'stock_qty' => 5, 'priority' => 2, 'is_fallback' => false],
                ],
                11 => [
                    ['warehouse_id' => 1, 'stock_qty' => 3, 'priority' => 1, 'is_fallback' => false],
                ],
            ]
        ));

        $plan = $planner->build_plan([
            ['product_id' => 10, 'quantity' => 2, 'unit_weight_gram' => 500],
            ['product_id' => 11, 'quantity' => 1, 'unit_weight_gram' => 1000],
        ]);

        $this->assertTrue($plan['single_origin']['is_available']);
        $this->assertSame(1, $plan['single_origin']['shipments'][0]['warehouse_id']);
        $this->assertTrue($plan['split_shipment']['is_available']);
    }

    public function test_split_plan_allocates_from_fallback_when_primary_stock_not_enough(): void
    {
        $planner = new COL_Shipment_Planner(new Fake_Origin_Repository(
            [
                ['id' => 1, 'name' => 'Gudang A', 'region_code' => 'JKT', 'priority' => 1],
                ['id' => 2, 'name' => 'Gudang B', 'region_code' => 'BDG', 'priority' => 2],
            ],
            [
                99 => [
                    ['warehouse_id' => 1, 'stock_qty' => 1, 'priority' => 1, 'is_fallback' => false],
                    ['warehouse_id' => 2, 'stock_qty' => 3, 'priority' => 2, 'is_fallback' => true],
                ],
            ]
        ));

        $plan = $planner->build_plan([
            ['product_id' => 99, 'quantity' => 3, 'unit_weight_gram' => 400],
        ]);

        $this->assertFalse($plan['single_origin']['is_available']);
        $this->assertTrue($plan['split_shipment']['is_available']);
        $this->assertCount(2, $plan['split_shipment']['shipments']);
    }
}
