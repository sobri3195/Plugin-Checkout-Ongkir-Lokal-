# Observability Dashboard (Ongkir Lokal)

## 1) Skema Event Metric

Sumber data observability disimpan pada tabel `wp_col_metric_events`:

- `event_name`: tipe event (contoh: `cache_hit`, `cache_miss`, `provider_call`, `fallback_used`, `shipping_distribution`).
- `provider`: provider API ongkir.
- `courier`: kurir (JNE/JNT/dll).
- `area_code`: kode area tujuan (district/state).
- `status`: `success`/`error`.
- `cache_status`: `hit`/`miss`/`stale`.
- `fallback_used`: indikator fallback aktif.
- `is_timeout`: indikator timeout request.
- `response_time_ms`: latensi request provider.
- `shipping_cost`: nominal ongkir (untuk distribusi ongkir).
- `meta_json`: payload raw untuk audit/debug.
- `created_at`: timestamp event.

## 2) Job Agregasi Periodik

Cron WordPress `col_observability_aggregate` dijadwalkan setiap jam:

- Agregasi *hourly* untuk jendela 1 jam terakhir.
- Agregasi *daily* untuk hari berjalan.
- Menyimpan hasil ke `wp_col_metric_rollups` (`metrics_json`).
- Menjalankan deteksi anomali berbasis baseline harian/mingguan.

## 3) Deteksi Anomali + Notifikasi

Deteksi anomali membandingkan metrik observasi vs baseline:

- Baseline harian: rerata 7 rollup harian terbaru.
- Baseline mingguan: rerata 28 rollup harian terbaru.
- Metrik utama: `api_error_rate`, `timeout_rate`, `fallback_rate`, `cache_miss_rate`.
- Trigger anomali jika deviasi `%` melampaui `observability_alert_threshold_pct`.

Ketika anomali terdeteksi:

- Simpan ke `wp_col_metric_anomalies`.
- Kirim notifikasi ke email (`observability_alert_email`) via `wp_mail`.
- Kirim notifikasi ke Slack webhook (`observability_slack_webhook`) via `wp_remote_post`.

## 4) Halaman Admin Dashboard

Menu: **Ongkir Lokal → Observability**.

Fitur dashboard:

- Filter waktu (`24h`, `7d`, `30d`).
- Filter provider.
- Filter area.
- KPI teknis & bisnis:
  - API success/error rate,
  - timeout rate,
  - cache hit/miss,
  - fallback usage.
- Tabel distribusi ongkir per area/kurir/provider.
- Tabel histori anomali + status notifikasi.
