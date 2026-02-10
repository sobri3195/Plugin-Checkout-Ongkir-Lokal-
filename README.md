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


## Paket WordPress Theme (ZIP Installable)

Jika platform upload Anda menampilkan pesan **"WordPress Theme is required"**, gunakan folder tema pendamping yang sudah disiapkan di repository ini:

- Lokasi tema: `theme/checkout-ongkir-lokal-theme/`
- File inti tema: `style.css`, `index.php`, `functions.php`

Cara membuat ZIP installable untuk upload Theme:

```bash
cd theme
zip -r checkout-ongkir-lokal-theme.zip checkout-ongkir-lokal-theme
```

Lalu upload file `checkout-ongkir-lokal-theme.zip` pada halaman upload tema WordPress.

> Catatan: fitur ongkir tetap berasal dari plugin **Checkout Ongkir Lokal**. Tema ini disediakan untuk memenuhi kebutuhan paket Theme installable.

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
