<?php

use PHPUnit\Framework\TestCase;

final class DeliveryPromiseEngineTest extends TestCase
{
    public function test_build_promise_applies_cutoff_calendar_and_historical_sla(): void
    {
        $wpdb = new FakePromiseWpdb();
        $engine = new COL_Delivery_Promise_Engine($wpdb);

        $now = new DateTimeImmutable('2026-01-01 16:30:00', new DateTimeZone('UTC'));
        $promise = $engine->build_promise([
            'courier' => 'jne',
            'service' => 'REG',
            'eta_label' => '2-3 hari',
        ], 1, $now);

        $this->assertSame(5, $promise['eta_min_days']);
        $this->assertSame(6, $promise['eta_max_days']);
        $this->assertSame('low', $promise['confidence']);
        $this->assertCount(4, $promise['reasons']);
    }

    public function test_parse_eta_label_handles_single_number(): void
    {
        $engine = new COL_Delivery_Promise_Engine(new FakePromiseWpdb());
        $parsed = $engine->parse_eta_label('Estimasi 4 hari');

        $this->assertSame(4, $parsed['min_days']);
        $this->assertSame(4, $parsed['max_days']);
    }
}

final class FakePromiseWpdb
{
    public string $prefix = 'wp_';

    public function prepare(string $query, ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    public function get_var($prepared)
    {
        $query = $prepared['query'] ?? '';

        if (str_contains($query, 'SELECT cutoff_time')) {
            return '15:00';
        }

        if (str_contains($query, 'COUNT(1) FROM wp_col_operational_calendar')) {
            return 1;
        }

        return 0;
    }

    public function get_row($prepared, $output = null): array
    {
        return [
            'sample_size' => 10,
            'avg_delay' => 2.0,
        ];
    }
}
