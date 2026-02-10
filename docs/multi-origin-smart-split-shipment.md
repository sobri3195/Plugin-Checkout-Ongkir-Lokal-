# Multi-Origin Smart Split Shipment

## Struktur class baru
- `COL_Origin_Repository`: akses konfigurasi gudang + mapping produk ke gudang dari tabel DB.
- `COL_Shipment_Planner`: membuat 2 kandidat plan (`single_origin` dan `split_shipment`) berdasarkan stok, prioritas, dan fallback.
- `COL_Shipment_Rate_Aggregator`: menggabungkan rate antar shipment menjadi total ongkir + ETA agregat transparan.
- `COL_Shipping_Service` (update):
  - build cart line normalisasi
  - resolve strategy via filter `col_shipment_strategy`
  - hitung rate per shipment lalu agregasi
  - simpan payload plan ke session dan metadata order

## Migration tabel
- `wp_col_warehouses`
  - `id`, `name`, `address`, `region_code`, `priority`, `is_active`, timestamp.
- `wp_col_product_origins`
  - `product_id`, `warehouse_id`, `stock_qty`, `priority`, `is_fallback`, `updated_at`.

## Alur data
1. Cart package dinormalisasi ke `cart_lines` (`product_id`, `quantity`, `unit_weight_gram`).
2. Planner ambil konfigurasi gudang + mapping origin lalu menghasilkan 2 plan.
3. Strategy dipilih oleh filter:
   - `termurah`
   - `tercepat`
   - `balanced` (default)
4. Untuk tiap shipment di plan terpilih, plugin hitung rate per origin.
5. `COL_Shipment_Rate_Aggregator` menjumlahkan cost dan memilih ETA maksimum sebagai ETA total.
6. Rate ditampilkan di checkout dengan label jumlah shipment.
7. Metadata order disimpan:
   - `_col_plan_id`
   - `_col_origin_list`
   - `_col_shipment_count`
   - `_col_per_shipment_cost`

## Hook/Filter
- `col_shipment_strategy($strategy, $plan_candidates, $cart_lines)`
  - Override strategi pemilihan plan dari plugin/theme lain.

## Contoh override strategi
```php
add_filter('col_shipment_strategy', function ($strategy, $plan_candidates, $cart_lines) {
    if (WC()->cart && WC()->cart->subtotal > 500000) {
        return 'tercepat';
    }

    return 'balanced';
}, 10, 3);
```
