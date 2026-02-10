<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Delivery_Promise_Engine
{
    public function __construct(private $wpdb)
    {
    }

    public function build_promise(array $rate, int $warehouse_id, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now', wp_timezone());
        $baseline = $this->parse_eta_label((string) ($rate['eta_label'] ?? '2-4 hari'));
        $min_days = $baseline['min_days'];
        $max_days = $baseline['max_days'];
        $reasons = ['Baseline ETA provider: ' . $baseline['label']];
        $adjustment_days = 0;

        if ($this->is_past_cutoff($warehouse_id, $now)) {
            $min_days += 1;
            $max_days += 1;
            $adjustment_days += 1;
            $reasons[] = 'Order melewati cutoff gudang.';
        }

        $calendar_adjustment = $this->calendar_adjustment($now, $max_days);
        if ($calendar_adjustment > 0) {
            $min_days += $calendar_adjustment;
            $max_days += $calendar_adjustment;
            $adjustment_days += $calendar_adjustment;
            $reasons[] = sprintf('Ditambah %d hari karena kalender operasional/libur.', $calendar_adjustment);
        }

        $sla = $this->historical_sla_adjustment((string) ($rate['courier'] ?? ''), (string) ($rate['service'] ?? ''));
        if ($sla['adjustment_days'] > 0) {
            $min_days += $sla['adjustment_days'];
            $max_days += $sla['adjustment_days'];
            $adjustment_days += $sla['adjustment_days'];
            $reasons[] = sprintf('Koreksi SLA historis +%d hari (n=%d).', $sla['adjustment_days'], $sla['sample_size']);
        }

        $confidence = $this->confidence_label($adjustment_days, $sla['sample_size']);

        return [
            'eta_min_days' => $min_days,
            'eta_max_days' => $max_days,
            'eta_label' => sprintf('%d-%d hari', $min_days, $max_days),
            'confidence' => $confidence,
            'reasons' => $reasons,
            'baseline_eta_label' => $baseline['label'],
        ];
    }

    public function parse_eta_label(string $eta_label): array
    {
        preg_match_all('/\d+/', $eta_label, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        if (count($numbers) >= 2) {
            $min = min($numbers[0], $numbers[1]);
            $max = max($numbers[0], $numbers[1]);
            return ['min_days' => $min, 'max_days' => $max, 'label' => sprintf('%d-%d hari', $min, $max)];
        }

        if (count($numbers) === 1) {
            $day = max(1, (int) $numbers[0]);
            return ['min_days' => $day, 'max_days' => $day, 'label' => sprintf('%d hari', $day)];
        }

        return ['min_days' => 2, 'max_days' => 4, 'label' => '2-4 hari'];
    }

    private function is_past_cutoff(int $warehouse_id, DateTimeImmutable $now): bool
    {
        $table = $this->wpdb->prefix . 'col_warehouses';
        $cutoff = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT cutoff_time FROM {$table} WHERE id = %d LIMIT 1",
            $warehouse_id
        ));

        $cutoff = is_string($cutoff) && preg_match('/^\d{2}:\d{2}$/', $cutoff) ? $cutoff : '15:00';

        [$hour, $minute] = array_map('intval', explode(':', $cutoff));
        $cutoff_at = $now->setTime($hour, $minute, 0);

        return $now > $cutoff_at;
    }

    private function calendar_adjustment(DateTimeImmutable $now, int $max_days): int
    {
        $table = $this->wpdb->prefix . 'col_operational_calendar';
        $end = $now->modify('+' . $max_days . ' day')->format('Y-m-d');
        $start = $now->format('Y-m-d');

        $closed_days = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$table} WHERE date_key BETWEEN %s AND %s AND is_open = 0",
            $start,
            $end
        ));

        return max(0, (int) $closed_days);
    }

    private function historical_sla_adjustment(string $courier, string $service): array
    {
        $table = $this->wpdb->prefix . 'col_delivery_promise_logs';
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT COUNT(1) AS sample_size,
                    AVG(GREATEST(0, DATEDIFF(actual_delivery_at, promised_max_date))) AS avg_delay
             FROM {$table}
             WHERE courier = %s AND service = %s AND actual_delivery_at IS NOT NULL",
            $courier,
            $service
        ), ARRAY_A);

        $sample = (int) ($row['sample_size'] ?? 0);
        $avg_delay = (float) ($row['avg_delay'] ?? 0);
        $adjustment = $sample >= 5 ? (int) ceil($avg_delay) : 0;

        return [
            'sample_size' => $sample,
            'adjustment_days' => max(0, $adjustment),
        ];
    }

    private function confidence_label(int $adjustment_days, int $sample_size): string
    {
        if ($adjustment_days <= 1 && $sample_size >= 10) {
            return 'high';
        }

        if ($adjustment_days <= 2 && $sample_size >= 5) {
            return 'medium';
        }

        return 'low';
    }
}
