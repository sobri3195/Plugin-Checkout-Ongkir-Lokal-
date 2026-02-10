<?php

use PHPUnit\Framework\TestCase;

class Fake_Reconciliation_Settings extends COL_Settings
{
    public function __construct(private array $config)
    {
    }

    public function all(): array
    {
        return $this->config;
    }
}

class Fake_Reconciliation_Logger extends COL_Logger
{
    public array $warnings = [];

    public function warning(string $event_type, string $message, array $context = []): void
    {
        $this->warnings[] = compact('event_type', 'message', 'context');
    }
}

final class CostReconciliationServiceTest extends TestCase
{
    public function test_build_variance_report_groups_by_courier_service_area_rule(): void
    {
        $service = new COL_Cost_Reconciliation_Service(
            new Fake_Reconciliation_Settings([]),
            new Fake_Reconciliation_Logger()
        );

        $rows = [
            ['courier' => 'jne', 'service' => 'REG', 'area' => '3173040', 'active_rule' => 'district_multiplier_1_15', 'variance' => 3000],
            ['courier' => 'jne', 'service' => 'REG', 'area' => '3173040', 'active_rule' => 'district_multiplier_1_15', 'variance' => 1000],
            ['courier' => 'jnt', 'service' => 'EZ', 'area' => '3273010', 'active_rule' => '', 'variance' => -1500],
        ];

        $report = $service->build_variance_report_from_rows($rows);

        $this->assertCount(2, $report);
        $jne = current(array_values(array_filter($report, static fn(array $row): bool => $row['courier'] === 'jne')));
        $this->assertSame('jne', $jne['courier']);
        $this->assertSame('REG', $jne['service']);
        $this->assertSame('3173040', $jne['area']);
        $this->assertSame(2, $jne['sample_count']);
        $this->assertSame(2000.0, $jne['average_variance']);
    }

    public function test_recommend_rule_tuning_returns_area_surcharge_recommendation(): void
    {
        $service = new COL_Cost_Reconciliation_Service(
            new Fake_Reconciliation_Settings([]),
            new Fake_Reconciliation_Logger()
        );

        $recommendations = $service->recommend_rule_tuning([
            [
                'courier' => 'jne',
                'service' => 'REG',
                'area' => '3173040',
                'active_rule' => 'district_multiplier_1_15',
                'average_variance' => 6200,
                'sample_count' => 4,
            ],
        ], 5000, 3);

        $this->assertCount(1, $recommendations);
        $this->assertSame('add_surcharge', $recommendations[0]['recommended_action']);
        $this->assertSame(6000, $recommendations[0]['suggested_adjustment']);
    }

    public function test_check_threshold_and_notify_collects_alerts(): void
    {
        $logger = new Fake_Reconciliation_Logger();
        $service = new COL_Cost_Reconciliation_Service(
            new Fake_Reconciliation_Settings([]),
            $logger
        );

        $alerts = $service->check_threshold_and_notify([
            [
                'courier' => 'jne',
                'service' => 'REG',
                'area' => '3173040',
                'active_rule' => 'district_multiplier_1_15',
                'average_variance' => 5500,
            ],
        ], 5000);

        $this->assertCount(1, $alerts);
        $this->assertCount(1, $logger->warnings);
        $this->assertSame('cost_variance_threshold', $logger->warnings[0]['event_type']);
    }
}
