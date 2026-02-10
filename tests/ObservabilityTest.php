<?php

use PHPUnit\Framework\TestCase;

final class ObservabilityTest extends TestCase
{
    public function test_calculate_deviation_pct_handles_zero_baseline(): void
    {
        $this->assertSame(0.0, COL_Observability::calculate_deviation_pct(0.0, 0.0));
        $this->assertSame(100.0, COL_Observability::calculate_deviation_pct(10.0, 0.0));
    }

    public function test_calculate_deviation_pct_returns_absolute_percentage(): void
    {
        $this->assertSame(50.0, COL_Observability::calculate_deviation_pct(15.0, 10.0));
        $this->assertSame(50.0, COL_Observability::calculate_deviation_pct(5.0, 10.0));
    }
}
