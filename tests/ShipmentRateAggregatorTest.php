<?php

use PHPUnit\Framework\TestCase;

final class ShipmentRateAggregatorTest extends TestCase
{
    public function test_aggregate_sums_cost_and_uses_max_eta(): void
    {
        $aggregator = new COL_Shipment_Rate_Aggregator();

        $rates = $aggregator->aggregate([
            [
                ['courier' => 'jne', 'service' => 'REG', 'eta_label' => '1-2 hari', 'price' => 15000],
                ['courier' => 'jnt', 'service' => 'REG', 'eta_label' => '2-3 hari', 'price' => 14000],
            ],
            [
                ['courier' => 'jne', 'service' => 'REG', 'eta_label' => '2-4 hari', 'price' => 17000],
                ['courier' => 'jnt', 'service' => 'REG', 'eta_label' => '3-4 hari', 'price' => 16000],
            ],
        ]);

        $byCourier = [];
        foreach ($rates as $rate) {
            $byCourier[$rate['courier']] = $rate;
        }

        $this->assertSame(32000, $byCourier['jne']['price']);
        $this->assertSame('4 hari', $byCourier['jne']['eta_label']);
        $this->assertSame(2, $byCourier['jne']['shipment_count']);
    }
}
