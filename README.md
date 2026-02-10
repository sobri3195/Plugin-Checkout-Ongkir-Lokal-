# Checkout Ongkir Lokal

Plugin WooCommerce untuk menghitung ongkir lokal Indonesia dengan fitur reliability dan kontrol risiko COD.

## Fitur Utama

- Integrasi ongkir multi-kurir (default: JNE, J&T, AnterAja).
- Anti-down mode: fallback ke cache/stale rate lalu flat-rate cadangan saat provider error.
- Rule engine untuk evaluasi COD (allow/deny berdasarkan konteks order).
- Perencanaan shipment multi-origin + agregasi rate antar shipment.
- Packaging optimizer dengan box preset dan dukungan berat volumetrik.
- Pickup point checkout support.
- Address intelligence untuk normalisasi dan validasi konteks alamat.
- Cost reconciliation untuk membandingkan estimasi ongkir vs biaya aktual.
- Logging event ongkir untuk audit dan troubleshooting.

## Persyaratan

- WordPress aktif.
- WooCommerce aktif.
- PHP 8.0+ (direkomendasikan agar kompatibel dengan typed properties/union modern yang dipakai plugin).

## Instalasi

1. Copy folder plugin ini ke `wp-content/plugins/`.
2. Aktifkan plugin **Checkout Ongkir Lokal** dari halaman Plugins di WordPress.
3. Pastikan WooCommerce aktif sebelum plugin diinisialisasi.
4. Buka menu admin **Ongkir Lokal** untuk konfigurasi awal.

## Konfigurasi Dasar

Pengaturan disimpan di opsi `col_settings` dan otomatis memiliki default, termasuk:

- Provider ongkir (`rajaongkir`).
- Cache TTL (`900` detik).
- Timeout request (`7` detik), retry (`1`).
- Anti-down mode (`fallback_then_flat`).
- Flat-rate backup (`18000`).
- COD risk scoring (threshold block/review, bobot sinyal, jam rawan).
- Strategi shipment (`balanced`), box presets, dan divisor volumetrik.

## Struktur Komponen Penting

- `checkout-ongkir-lokal.php`: bootstrap plugin.
- `includes/class-col-plugin.php`: inisialisasi service + registrasi hook WooCommerce/WordPress.
- `includes/class-col-settings.php`: default settings + UI admin COD risk.
- `includes/class-col-shipping-service.php`: orkestrasi kalkulasi ongkir.
- `includes/class-col-rule-engine.php`: evaluasi rule COD/rate.
- `includes/class-col-logger.php`: logging ke database.

## Database Tables yang Dibuat Saat Aktivasi

Plugin membuat custom table (prefix `wp_col_` mengikuti prefix database WordPress):

- `rate_cache`
- `logs`
- `district_overrides`
- `cod_rules`
- `area_mappings`
- `warehouses`
- `product_origins`
- `cost_variances`

## Hook yang Dipakai

- `woocommerce_shipping_init`
- `woocommerce_shipping_methods`
- `admin_menu`


## Demo

Demo interaktif tersedia dalam bentuk halaman PHP sederhana yang menjalankan simulasi komponen utama plugin.

- Jalankan dari root repo: `php -S 127.0.0.1:8090`
- Buka link demo: `http://127.0.0.1:8090/demo/index.php`

Halaman demo menampilkan output real-time untuk:

- Shipment planner (single-origin & split shipment).
- Packaging optimizer (pemilihan box + berat volumetrik).
- Shipment rate aggregator.
- COD risk rule engine.
- Address intelligence analyzer.

## Pengujian

Repository sudah menyiapkan unit test berbasis PHPUnit di folder `tests/`.

Contoh menjalankan test:

```bash
phpunit -c phpunit.xml.dist
```

> Catatan: pastikan dependensi PHPUnit tersedia di environment Anda.

## Catatan Pengembangan

- Fokus plugin: stabilitas checkout saat API ongkir sedang tidak stabil.
- Bila menambah fitur baru, disarankan mempertahankan fallback dan logging agar operasional toko tetap aman.
