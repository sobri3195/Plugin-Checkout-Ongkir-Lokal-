<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Origin_Repository
{
    public function __construct(private $wpdb)
    {
    }

    public function get_active_warehouses(): array
    {
        $table = $this->wpdb->prefix . 'col_warehouses';
        $rows = $this->wpdb->get_results("SELECT id, name, address, region_code, priority FROM {$table} WHERE is_active = 1 ORDER BY priority ASC, id ASC", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function get_product_origin_map(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        $table = $this->wpdb->prefix . 'col_product_origins';
        $ids = array_map('intval', array_values(array_unique($product_ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $query = $this->wpdb->prepare(
            "SELECT product_id, warehouse_id, stock_qty, priority, is_fallback
            FROM {$table}
            WHERE product_id IN ({$placeholders})
            ORDER BY product_id ASC, priority ASC, warehouse_id ASC",
            ...$ids
        );

        $rows = $this->wpdb->get_results($query, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $product_id = (int) $row['product_id'];
            if (! isset($map[$product_id])) {
                $map[$product_id] = [];
            }

            $map[$product_id][] = [
                'warehouse_id' => (int) $row['warehouse_id'],
                'stock_qty' => (int) $row['stock_qty'],
                'priority' => (int) $row['priority'],
                'is_fallback' => (bool) $row['is_fallback'],
            ];
        }

        return $map;
    }
}
