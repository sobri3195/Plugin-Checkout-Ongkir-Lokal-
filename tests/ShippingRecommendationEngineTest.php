<?php

use PHPUnit\Framework\TestCase;

final class ShippingRecommendationEngineTest extends TestCase
{
    public function test_score_rates_returns_recommendation_and_badges(): void
    {
        $engine = new COL_Shipping_Recommendation_Engine();
        $result = $engine->score_rates([
            [
                'rate_id' => 'col:jne_reg',
                'courier' => 'jne',
                'service' => 'REG',
                'price' => 18000,
                'eta_label' => '2-4 hari',
            ],
            [
                'rate_id' => 'col:jnt_reg',
                'courier' => 'jnt',
                'service' => 'REG',
                'price' => 15000,
                'eta_label' => '3-5 hari',
            ],
            [
                'rate_id' => 'col:anteraja_reg',
                'courier' => 'anteraja',
                'service' => 'REG',
                'price' => 17000,
                'eta_label' => '1-2 hari',
            ],
        ], 250000, []);

        $this->assertTrue($result['is_available']);
        $this->assertNotSame('', $result['recommended_rate_id']);
        $this->assertArrayHasKey('col:anteraja_reg', $result['badges']);
        $this->assertContains('Fastest', $result['badges']['col:anteraja_reg']);
        $this->assertArrayHasKey('col:jnt_reg', $result['badges']);
        $this->assertContains('Cheapest', $result['badges']['col:jnt_reg']);
    }

    public function test_score_rates_falls_back_when_cart_total_is_invalid(): void
    {
        $engine = new COL_Shipping_Recommendation_Engine();
        $result = $engine->score_rates([
            [
                'rate_id' => 'col:jne_reg',
                'courier' => 'jne',
                'service' => 'REG',
                'price' => 18000,
                'eta_label' => '2-4 hari',
            ],
        ], 0, []);

        $this->assertFalse($result['is_available']);
        $this->assertSame('', $result['recommended_rate_id']);
        $this->assertArrayHasKey('col:jne_reg', $result['badges']);
        $this->assertContains('Cheapest', $result['badges']['col:jne_reg']);
    }
}
