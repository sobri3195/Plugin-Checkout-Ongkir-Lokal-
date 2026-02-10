<?php

use PHPUnit\Framework\TestCase;

class Fake_COD_Risk_Settings extends COL_Settings
{
    public function __construct(private array $fake)
    {
    }

    public function all(): array
    {
        return $this->fake;
    }
}

class Fake_COD_Risk_Logger extends COL_Logger
{
    public array $events = [];

    public function info(string $event_type, string $message, array $context = []): void
    {
        $this->events[] = compact('event_type', 'message', 'context');
    }
}

final class CodRiskRuleEngineTest extends TestCase
{
    public function test_high_risk_context_returns_block_policy(): void
    {
        $settings = new Fake_COD_Risk_Settings([
            'cod_risk_weights' => [
                'order_value' => 25,
                'area_distance' => 20,
                'customer_history' => 25,
                'address_quality' => 15,
                'order_time' => 15,
            ],
            'cod_risk_risky_hours' => [22, 23, 0, 1, 2, 3, 4],
            'cod_risk_block_threshold' => 80,
            'cod_risk_review_threshold' => 60,
        ]);
        $logger = new Fake_COD_Risk_Logger();
        $engine = new COL_Rule_Engine($settings, $logger);

        $result = $engine->evaluate_cod_risk([
            'cart_total' => 1300000,
            'destination_district_code' => 'OUTSIDE',
            'origin_list' => ['JKT'],
            'cancel_count' => 4,
            'rto_count' => 2,
            'completed_count' => 0,
            'address_line' => 'Alamat',
            'destination_postcode' => '',
            'order_hour' => 23,
        ]);

        $this->assertGreaterThanOrEqual(80, $result['score']);
        $this->assertSame('block_cod', $result['policy']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_low_risk_context_returns_normal_policy(): void
    {
        $settings = new Fake_COD_Risk_Settings([
            'cod_risk_weights' => [
                'order_value' => 25,
                'area_distance' => 20,
                'customer_history' => 25,
                'address_quality' => 15,
                'order_time' => 15,
            ],
            'cod_risk_risky_hours' => [1, 2, 3],
            'cod_risk_block_threshold' => 80,
            'cod_risk_review_threshold' => 60,
        ]);
        $logger = new Fake_COD_Risk_Logger();
        $engine = new COL_Rule_Engine($settings, $logger);

        $result = $engine->evaluate_cod_risk([
            'cart_total' => 120000,
            'destination_district_code' => 'JKT',
            'origin_list' => ['JKT'],
            'cancel_count' => 0,
            'rto_count' => 0,
            'completed_count' => 3,
            'address_line' => 'Jalan Sudirman No 10, Jakarta Selatan',
            'destination_postcode' => '12190',
            'order_hour' => 10,
        ]);

        $this->assertLessThan(60, $result['score']);
        $this->assertSame('normal', $result['policy']);
    }
}
