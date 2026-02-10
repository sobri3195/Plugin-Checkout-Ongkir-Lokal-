# Blueprint Produk — Checkout Ongkir Lokal

## 1) Ringkasan Produk

### Value Proposition
- **Satu plugin ongkir lokal + COD rules yang benar-benar operasional untuk toko Indonesia**: bukan sekadar tarik tarif API, tapi juga ada anti-down mode, fallback rate, surcharge, override per kecamatan, cache, dan log audit.
- **Checkout tetap jalan saat API provider error** karena plugin punya mode fallback ke tarif terakhir yang masih valid.
- **Kontrol margin lebih rapi** melalui surcharge daerah terpencil dan override tarif granular sampai level kecamatan/kode pos.

### Persona Toko
- **UMKM berkembang**: trafik menengah, butuh stabilitas checkout tanpa tim teknis penuh.
- **Brand menengah D2C**: volume order lebih besar, butuh log lengkap untuk audit CS/finance/ops.
- **Seller multi-kurir**: butuh komparasi servis (REG/YES/ECO dsb) dari beberapa integrator lokal.

### Problem/Solution Fit
- Masalah umum:
  - API ongkir timeout/down saat jam sibuk.
  - COD sering bocor ke area/pesanan yang tidak eligible.
  - Biaya kirim ke area remote tidak menutup biaya real.
  - Sulit debugging karena minim log.
- Solusi plugin:
  - **Anti-down mode** dengan fallback + cadangan flat rate.
  - **Rule engine COD/surcharge** berbasis wilayah, subtotal, kategori, produk, metode kirim.
  - **Cache + override + logging** end-to-end.

---

## 2) Fitur Inti (Backlog-Ready)

### A. Auto Ongkir Multi-Kurir
- Pilih provider: RajaOngkir / Komship / connector native Anteraja/JNE/J&T.
- Pilih kurir aktif per store: `jne`, `jnt`, `anteraja`, dst.
- Priority kurir (contoh): JNE > J&T > Anteraja.
- Opsi tampilan checkout:
  - grouped per kurir,
  - sort by harga termurah,
  - sort by ETA tercepat.
- Opsi label custom: “JNE REG (Estimasi 2–3 hari)”.

### B. Estimasi Real-Time (ETA) + SLA
- Ambil ETA/SLA jika provider mengembalikan field tersebut.
- Normalisasi ETA ke format standar (`min_day`, `max_day`, `text_label`).
- Tampilkan badge SLA di checkout (mis. “On-time SLA 98%”).

### C. COD Rules Engine
- Rule support:
  - wilayah: provinsi/kota/kecamatan/kode pos,
  - minimum belanja,
  - include/exclude produk tertentu,
  - include/exclude kategori,
  - include/exclude shipping service.
- Action: `allow_cod` / `deny_cod`.
- Contoh aturan konkret:
  1. Deny COD untuk `Kepulauan Mentawai`.
  2. Deny COD jika subtotal < Rp75.000.
  3. Deny COD untuk kategori `barang_pecah_belah`.
  4. Allow COD khusus service `JNE REG` untuk kota Bandung.

### D. Surcharge Daerah Terpencil
- Match scope: provinsi/kota/kecamatan/kode pos.
- Jenis surcharge:
  - fixed amount (contoh +Rp7.000),
  - multiplier (contoh x1.12).
- Dapat digabung (stacked) atau pilih highest-only.

### E. Fallback Rate / Anti-Down Mode
- Simpan tarif terakhir per kombinasi:
  `origin-destination-weight-courier-service`.
- Saat timeout/error/provider down:
  1) pakai fallback tarif terbaru yang belum stale,
  2) jika tidak ada → pakai flat-rate cadangan.

### F. Cache Ongkir
- TTL cache global (default 15 menit).
- Invalidation rule:
  - jika ada perubahan item/qty/berat/alamat/kurir/provider,
  - manual purge dari admin.
- Mode cache:
  - session cache (user-level, cepat di checkout),
  - persistent global cache (cross-session).

### G. Rate Override per Kecamatan
- Override fixed (set harga final) atau multiplier.
- Prioritas evaluasi:
  - override before surcharge, atau
  - override after surcharge (configurable).
- CSV import opsional kolom: `province_code,city_code,district_code,postal_code,mode,value,priority`.

### H. Log Ongkir Lengkap
- Simpan ringkas request/response (tanpa data sensitif).
- Simpan event:
  - cache hit/miss,
  - fallback reason,
  - override/surcharge applied,
  - COD rule decision.
- Filter log: tanggal, provider, event type, request_id.

### I. Flow Checkout
1. Cart update / checkout refresh.
2. Hitung berat aktual + volumetrik (opsional).
3. Resolve origin warehouse + destination mapping kecamatan.
4. Cek cache (session lalu global).
5. Jika miss → call API provider.
6. Normalisasi rate + ETA.
7. Apply rule engine (override, surcharge, COD eligibility).
8. Sort + tampilkan opsi shipping.
9. Saat order dibuat, snapshot rule result & chosen shipping.

---

## 3) Arsitektur Teknis (High Level)

### Modul/Kelas Utama
- `COL_Plugin`: bootstrap, registrasi hooks, lifecycle.
- `COL_Settings`: config provider, API key, cache TTL, anti-down.
- `COL_Shipping_Service`: orchestration hitung rate & inject shipping methods.
- `COL_Provider_*`: adapter per provider (RajaOngkir/Komship/dll).
- `COL_Rule_Engine`: evaluasi COD + surcharge + override.
- `COL_Cache_Repository`: transient + custom table cache.
- `COL_Logger`: write log ke custom table.
- `COL_Admin_*`: UI settings, builder rule, log viewer, import CSV.

### Hook WooCommerce
- `woocommerce_shipping_init`
- `woocommerce_shipping_methods`
- `woocommerce_package_rates`
- `woocommerce_checkout_create_order`
- `woocommerce_after_calculate_totals`

### Storage Strategy
- `wp_options`: setting global plugin.
- Custom tables:
  - cache rate,
  - log,
  - mapping wilayah,
  - override kecamatan,
  - COD rules.

### Caching Strategy
- **L1**: session/transient cache per request user.
- **L2**: database cache persistent untuk fallback anti-down.
- Key hash berdasarkan origin, destination, berat, kurir, service, provider.

### Error Handling
- Timeout provider + retry terbatas.
- Circuit breaker sederhana per provider.
- Graceful degradation: live rates → stale fallback → flat rate cadangan.
- Semua fallback dicatat di log.

---

## 4) Skema Data (Custom Table)

### `wp_col_rate_cache`
- `cache_key` (unique)
- `provider`
- `origin_key`, `destination_key`
- `courier`, `service`
- `weight_gram`, `volumetric_weight_gram`
- `price`, `eta_label`, `payload_json`
- `fetched_at`, `expires_at`

### `wp_col_logs`
- `request_id`, `level`, `event_type`
- `provider`, `cache_status`, `fallback_used`
- `message`, `context_json`, `created_at`

### `wp_col_area_mappings`
- `provider`
- `province_name`, `city_name`, `district_name`, `postal_code`
- `provider_area_id`, `normalized_hash`, `updated_at`

### `wp_col_district_overrides`
- `province_code`, `city_code`, `district_code`, `postal_code`
- `override_mode` (`fixed`/`multiplier`)
- `override_value`, `priority`, `is_active`

### `wp_col_cod_rules`
- `rule_name`, `action_type` (`allow`/`deny`)
- `match_scope`, `operator_type`, `value_json`
- `priority`, `stop_on_match`, `is_active`

---

## 5) Rule Engine COD & Surcharge

### Prioritas Aturan
1. Rule `deny` prioritas tertinggi.
2. Rule `allow` prioritas berikutnya.
3. Default policy (`allow` atau `deny`) dari setting.
4. Override kecamatan (fixed/multiplier) diaplikasikan sesuai mode prioritas.
5. Surcharge dievaluasi setelah override (default) agar biaya remote tetap konsisten.

### Konflik Rule
- Jika dua rule match bertentangan, gunakan `priority` terkecil sebagai pemenang.
- Jika priority sama: `deny` menang atas `allow`.
- `stop_on_match=true` menghentikan evaluasi berikutnya.

### Contoh Rule Set
- R1 (priority 10): deny COD jika `district_code in [MTW001, MTW002]`.
- R2 (priority 20): deny COD jika `subtotal < 75000`.
- R3 (priority 30): allow COD jika `city=Bandung` dan `service=JNE_REG`.
- R4 (priority 40): surcharge +7000 untuk `postal_code` remote list.
- R5 (priority 50): override multiplier x1.15 untuk kecamatan `3173040`.

### Pseudocode Evaluasi
```text
rates = get_live_or_cached_rates(context)
cod_allowed = default_cod_policy

for rule in cod_rules_sorted_by_priority:
  if match(rule, context):
    if rule.action == "deny": cod_allowed = false
    if rule.action == "allow": cod_allowed = true
    if rule.stop_on_match: break

for rate in rates:
  if match_override(rate, context):
    rate = apply_override(rate)
  if match_surcharge(rate, context):
    rate = apply_surcharge(rate)

return {rates, cod_allowed}
```

---

## 6) Mode Anti-Down

### Definisi Provider “Down”
- HTTP timeout > nilai `request_timeout_seconds`.
- HTTP status 5xx berulang.
- Response schema invalid (field rate kosong semua).
- Error DNS / SSL / network connection.

### Timeout/Retry
- Timeout default 7 detik.
- Retry maksimal 1x dengan backoff 300–500ms.
- Jika tetap gagal, tandai provider degraded selama window 60 detik.

### Urutan Fallback
1. Ambil **stale cache terbaru** yang belum melewati `stale_max_age_minutes`.
2. Jika tidak ada, pakai `flat_rate_backup`.
3. Tampilkan notifikasi internal (admin log), jangan ganggu UX checkout.

### Menghindari Tarif Basi
- Ada batas stale max age (mis. 12 jam).
- Tandai rate fallback di order meta (`_col_fallback_used=1`).
- Jika stale terlalu lama, skip fallback dan pakai flat rate untuk keamanan margin.

---

## 7) UI Admin WordPress

### Struktur Menu
- Ongkir Lokal
  - Dashboard
  - Provider & Origin
  - Cache & Anti-Down
  - COD Rule Builder
  - Surcharge Builder
  - Override Kecamatan
  - Logs

### UX Minimum
- **Provider & Origin**: pilih provider, API key, origin gudang (province/city/district).
- **Cache**: TTL global, stale max age, purge cache.
- **COD Builder**: UI kondisi + aksi + priority + stop on match.
- **Surcharge Builder**: area selector + fixed/multiplier.
- **Override Kecamatan**: CRUD tabel + import CSV.
- **Log Page**: filter tanggal/provider/event/request_id, export CSV.

---

## 8) Non-Functional Requirements

### Performance
- Target tambahan waktu kalkulasi checkout: **< 400ms** saat cache hit.
- Saat cache miss + API normal: **< 2.5s** median.
- TTFB checkout page tetap < 1.5s (tanpa blocking call berantai).

### Security
- `sanitize_text_field`, `absint`, `wc_clean` untuk input.
- Nonce + capability `manage_woocommerce` untuk admin action.
- Jangan simpan API key di log plain text.

### Compatibility
- WooCommerce HPOS-compatible (order meta via CRUD API).
- Kompatibel classic checkout.
- Checkout Blocks: support bertahap (minimal fallback ke classic shipping package API).

### i18n
- Semua string via `__()` / `_e()` textdomain `checkout-ongkir-lokal`.
- Format mata uang mengikuti WooCommerce currency settings.

### Observability
- Log level `info/warning/error`.
- Request correlation `request_id`.
- Endpoint health summary di dashboard admin.

---

## 9) Roadmap

### MVP
- Single provider (RajaOngkir atau Komship), multi-kurir dasar.
- Cache TTL + anti-down fallback + flat backup.
- COD rules dasar (wilayah + minimum belanja).
- Log dasar (cache/fallback/API error).

### V1
- Multi-provider adapter.
- Rule engine lengkap (produk, kategori, shipping service).
- Surcharge + override kecamatan + CSV import.
- Halaman log dengan filter lanjutan.

### V2
- Smart routing antar provider (auto failover).
- ML-based fallback tuning / anomali ongkir.
- Checkout Blocks native extension + SLA analytics.

---

## 10) Acceptance Criteria + Test Plan

### Acceptance Criteria Kunci
1. Jika API timeout, checkout tetap menampilkan tarif (fallback/flat backup).
2. Rule COD berjalan konsisten sesuai prioritas dan conflict policy.
3. Override kecamatan mengubah tarif sesuai mode fixed/multiplier.
4. Log mencatat cache hit/miss, fallback reason, dan rule decision.

### Test Plan (Prioritas Tinggi)
- **Unit**
  - evaluator priority COD,
  - apply surcharge/override,
  - stale fallback policy.
- **Integration**
  - simulasi API success vs timeout,
  - cache hit/miss lifecycle,
  - order meta fallback snapshot.
- **Manual QA**
  - checkout dengan alamat remote,
  - subtotal < minimum COD,
  - produk fragile excluded,
  - impor CSV override lalu verifikasi di checkout.

---

## 11) Risiko & Mitigasi
- **API limit/rate limiting** → cache agresif + exponential backoff + provider rotation.
- **Perbedaan format wilayah antar provider** → table mapping normalisasi + tool sinkronisasi.
- **Ongkir tidak tersedia** → fallback stale, lalu flat backup.
- **Tarif basi terlalu lama** → stale max age + alert admin.
- **Salah konfigurasi rule** → preview evaluator + mode dry-run di admin.

---

## 12) Batasan & Asumsi
- Fokus wilayah Indonesia: provinsi/kota/kecamatan/kode pos.
- Plugin mengandalkan data berat produk WooCommerce; jika berat kosong akan pakai minimum 1 gram.
- ETA/SLA tergantung ketersediaan data dari provider.
- Fitur wajib tercakup: **anti-down mode**, **log ongkir lengkap**, **override kecamatan**.
