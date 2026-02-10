<?php

use PHPUnit\Framework\TestCase;

final class PackagingOptimizerTest extends TestCase
{
    public function test_optimize_generates_chargeable_weight_per_courier(): void
    {
        $optimizer = new COL_Packaging_Optimizer();

        $result = $optimizer->optimize(
            [
                [
                    'product_id' => 101,
                    'quantity' => 2,
                    'unit_weight_gram' => 1000,
                    'unit_length_cm' => 20,
                    'unit_width_cm' => 10,
                    'unit_height_cm' => 10,
                ],
            ],
            [
                [
                    'id' => 'box-m',
                    'name' => 'Box Medium',
                    'inner_length_cm' => 30,
                    'inner_width_cm' => 20,
                    'inner_height_cm' => 20,
                    'max_weight_gram' => 5000,
                ],
            ],
            [
                'jne' => 6000,
                'anteraja' => 4000,
            ],
            ['length' => 10, 'width' => 10, 'height' => 10]
        );

        $this->assertCount(1, $result['packages']);
        $this->assertSame(2000, $result['packages'][0]['actual_weight_gram']);
        $this->assertSame(6667, $result['packages'][0]['volumetric_weight_gram_by_courier']['jne']);
        $this->assertSame(10000, $result['packages'][0]['volumetric_weight_gram_by_courier']['anteraja']);
        $this->assertSame(6667, $result['packages'][0]['chargeable_weight_gram_by_courier']['jne']);
        $this->assertSame(10000, $result['chargeable_total_by_courier']['anteraja']);
    }

    public function test_optimize_uses_dimension_fallback_for_missing_dimensions(): void
    {
        $optimizer = new COL_Packaging_Optimizer();

        $result = $optimizer->optimize(
            [
                [
                    'product_id' => 202,
                    'quantity' => 1,
                    'unit_weight_gram' => 500,
                    'unit_length_cm' => 0,
                    'unit_width_cm' => 0,
                    'unit_height_cm' => 0,
                ],
            ],
            [],
            ['default' => 6000],
            ['length' => 12, 'width' => 11, 'height' => 10]
        );

        $this->assertTrue($result['dimension_fallback_used']);
        $this->assertSame(1320, $result['packages'][0]['volume_cm3']);
        $this->assertSame('Custom package', $result['packages'][0]['box']['name']);
    }
}
