# Analisis Mendalam — 10 Saran Fitur Baru + Prompt Implementasi

Dokumen ini berisi 10 usulan fitur **di luar fitur inti blueprint saat ini** untuk meningkatkan konversi checkout, stabilitas operasional, dan kontrol biaya logistik.

## 1) Multi-Origin Smart Split Shipment

### Masalah
Saat ini banyak toko punya stok tersebar di beberapa gudang, tetapi checkout sering mengasumsikan satu origin. Hasilnya ongkir tidak akurat, ETA meleset, dan risiko cancel meningkat.

### Nilai Bisnis
- Menurunkan mismatch ongkir vs biaya real fulfillment.
- Meningkatkan akurasi ETA.
- Mengurangi order tertunda karena stok ternyata beda gudang.

### Ruang Lingkup MVP
- Mapping produk → gudang prioritas.
- Kalkulasi shipment tunggal vs split (2+ paket).
- Pilih skenario termurah atau ETA tercepat berdasarkan setting.
- Snapshot pemilihan origin di metadata order.

### Risiko & Mitigasi
- **Risiko:** Kompleksitas UX checkout karena kemungkinan beberapa paket.
- **Mitigasi:** Sediakan mode “gabung estimasi” (single total) atau “detail per paket” (expandable).

### Prompt Implementasi
```text
Anda adalah software engineer WordPress/WooCommerce senior.
Bangun fitur Multi-Origin Smart Split Shipment untuk plugin Checkout Ongkir Lokal.

Tujuan:
1) Menentukan origin terbaik per item berdasarkan stok dan prioritas gudang.
2) Membandingkan 2 strategi: single-origin fallback vs split-shipment.
3) Menghasilkan total ongkir + ETA agregat yang transparan di checkout.

Kebutuhan teknis:
- Tambah konfigurasi gudang (id, nama, alamat, kode wilayah, prioritas).
- Tambah mapping produk->gudang dan fallback jika stok tidak tersedia.
- Tambah modul planner untuk membuat "shipment plan".
- Integrasikan plan ke perhitungan rate (per shipment), lalu agregasikan ke paket checkout.
- Simpan metadata order: plan_id, origin_list, shipment_count, per-shipment cost.
- Beri hooks/filters agar strategi pemilihan (termurah/tercepat/balanced) bisa diubah.

Output yang diinginkan:
- Struktur class baru, migration tabel yang diperlukan, alur data, dan contoh implementasi inti.
- Unit test untuk planner dan aggregation.
```

---

## 2) Pickup Point / PUDO (Pick-Up Drop-Off)

### Masalah
Sebagian pelanggan lebih memilih ambil barang di titik pickup (locker/agen) daripada pengiriman ke rumah untuk biaya lebih murah dan fleksibilitas waktu.

### Nilai Bisnis
- Opsi ongkir lebih murah meningkatkan conversion rate.
- Menurunkan gagal antar last-mile.

### Ruang Lingkup MVP
- Menampilkan daftar pickup point terdekat saat checkout.
- Hitung ongkir ke pickup point.
- Simpan pilihan pickup point ke order.

### Risiko & Mitigasi
- **Risiko:** Data pickup point cepat berubah.
- **Mitigasi:** Cache dengan TTL pendek + validasi ulang saat place order.

### Prompt Implementasi
```text
Implementasikan mode pengiriman Pickup Point/PUDO pada plugin WooCommerce.

Spesifikasi:
- Tambah metode shipping baru: "Ambil di Pickup Point".
- Saat metode dipilih, tampilkan selector pickup point berdasarkan kota/kode pos tujuan.
- Simpan data pickup point (id, nama, alamat, jam operasional, koordinat) di order meta.
- Jika provider tidak menyediakan pickup point real-time, gunakan cache + fallback list statis.
- Tambahkan validasi checkout agar order gagal dibuat jika pickup point kosong.

Tambahkan:
- API adapter interface pickup-point provider.
- Komponen UI checkout yang kompatibel blok/classic checkout.
- Logging event: list_loaded, point_selected, point_invalidated.
```

---

## 3) Prediksi Risiko COD & RTO Score

### Masalah
Order COD rentan Return to Origin (RTO) karena alamat ambigu, nomor tidak aktif, atau histori pelanggan buruk.

### Nilai Bisnis
- Menekan biaya RTO.
- Meningkatkan margin bersih channel COD.

### Ruang Lingkup MVP
- Hitung skor risiko sederhana dari rule-based signals.
- Terapkan aksi otomatis: require DP, disable COD, atau warning CS.

### Risiko & Mitigasi
- **Risiko:** False positive menurunkan conversion.
- **Mitigasi:** Gunakan threshold bertingkat + override manual admin.

### Prompt Implementasi
```text
Bangun modul "COD Risk Scoring" untuk WooCommerce plugin.

Tujuan:
- Menghitung skor 0-100 berdasarkan sinyal:
  - nilai order,
  - jarak area,
  - histori cancel/RTO pelanggan,
  - kualitas alamat,
  - waktu order (jam rawan).
- Menjalankan policy:
  - score >= 80: blok COD,
  - 60-79: izinkan COD dengan syarat tambahan,
  - <60: normal.

Kebutuhan:
- Rule engine risk yang dapat dikonfigurasi via admin.
- Simpan score dan alasan keputusan pada order meta + log.
- Endpoint internal untuk preview score saat checkout refresh.
```

---

## 4) Address Intelligence (Normalisasi + Validasi Alamat)

### Masalah
Alamat free-text sering tidak konsisten sehingga match ke wilayah provider gagal dan ongkir bisa salah.

### Nilai Bisnis
- Menurunkan error kalkulasi ongkir.
- Meningkatkan keberhasilan first-attempt delivery.

### Ruang Lingkup MVP
- Normalisasi penulisan alamat (singkatan umum, typo ringan).
- Auto-suggest kecamatan/kode pos paling mungkin.
- Skor confidence validasi.

### Risiko & Mitigasi
- **Risiko:** Salah normalisasi pada nama jalan lokal.
- **Mitigasi:** Tampilkan saran, bukan overwrite paksa; user tetap bisa manual.

### Prompt Implementasi
```text
Tambahkan fitur Address Intelligence di checkout:
1) Normalisasi teks alamat Indonesia.
2) Suggest kecamatan/kode pos dari input pengguna.
3) Berikan confidence score dan warning jika ambigu.

Detail teknis:
- Buat pipeline preprocess (lowercase, trimming, transliterasi ringan, kamus singkatan).
- Buat matcher berbasis token + fallback fuzzy match ke data wilayah.
- Jika confidence rendah, tampilkan UI konfirmasi area sebelum hitung ongkir final.
- Simpan raw address + normalized address + confidence di order meta.
```

---

## 5) Packaging Optimizer (Berat Volumetrik Otomatis)

### Masalah
Perhitungan ongkir sering hanya berbasis berat aktual, padahal kurir memakai volumetrik untuk paket besar/ringan.

### Nilai Bisnis
- Estimasi ongkir lebih presisi.
- Mengurangi selisih biaya shipping saat manifest.

### Ruang Lingkup MVP
- Definisi box presets.
- Algoritma packing sederhana (first-fit-decreasing).
- Hitung berat chargeable per paket.

### Risiko & Mitigasi
- **Risiko:** Packing optimal NP-hard untuk skenario kompleks.
- **Mitigasi:** Mulai dari heuristic deterministic + benchmark akurasi.

### Prompt Implementasi
```text
Implementasikan Packaging Optimizer untuk menghitung chargeable weight:
- Input: item dimensions/weight, quantity, box presets, divisor volumetrik per kurir.
- Proses: susun item ke boks menggunakan heuristik first-fit-decreasing.
- Output: daftar paket, berat aktual, berat volumetrik, chargeable weight per paket.

Integrasi:
- Gunakan chargeable weight pada request rate provider.
- Simpan breakdown paket pada log dan order meta untuk audit.
- Sediakan fallback jika data dimensi produk tidak lengkap.
```

---

## 6) Delivery Promise Engine (ETA Confidence)

### Masalah
ETA dari provider bersifat kasar; pelanggan butuh kepastian lebih baik terutama menjelang campaign besar.

### Nilai Bisnis
- Meningkatkan trust dan conversion.
- Menurunkan komplain keterlambatan.

### Ruang Lingkup MVP
- ETA range + confidence label (tinggi/sedang/rendah).
- Penyesuaian ETA berdasarkan hari libur/cutoff jam operasional.

### Risiko & Mitigasi
- **Risiko:** Data historis belum cukup.
- **Mitigasi:** Mulai rule-based adjustment, lalu evolusi ke model statistik.

### Prompt Implementasi
```text
Bangun Delivery Promise Engine:
- Ambil ETA provider sebagai baseline.
- Koreksi ETA menggunakan kalender libur nasional, cutoff jam gudang, dan SLA historis sederhana.
- Output akhir: ETA min/max + confidence label + alasan.

Tambahkan:
- Konfigurasi cutoff time per gudang.
- Tabel kalender operasional.
- Logger untuk membandingkan ETA promise vs real delivery (jika data tracking tersedia).
```

---

## 7) Post-Checkout Cost Reconciliation

### Masalah
Biaya real dari kurir (saat manifest/invoice) bisa berbeda dari estimasi checkout, namun tidak selalu terlacak sistematis.

### Nilai Bisnis
- Visibilitas margin per order lebih akurat.
- Dasar optimasi rule surcharge/override.

### Ruang Lingkup MVP
- Import biaya aktual (CSV/API).
- Bandingkan estimasi vs aktual per order.
- Dashboard selisih biaya dan top penyebab.

### Risiko & Mitigasi
- **Risiko:** Mapping order ID antar sistem tidak konsisten.
- **Mitigasi:** Gunakan external reference standar + fallback matching rules.

### Prompt Implementasi
```text
Implement fitur Cost Reconciliation setelah checkout:
- Ingest data biaya aktual shipping dari file CSV/API provider.
- Cocokkan ke order WooCommerce.
- Hitung variance: actual_cost - estimated_cost.
- Kelompokkan variance berdasarkan kurir, service, area, dan rule yang aktif saat checkout.

Keluaran:
- Laporan periodik variance.
- Notifikasi jika variance melebihi threshold.
- Rekomendasi otomatis rule tuning (misal surcharge area tertentu).
```

---

## 8) Rule Simulation Sandbox

### Masalah
Perubahan rule COD/surcharge/override berisiko tinggi karena langsung berdampak ke checkout live.

### Nilai Bisnis
- Mengurangi human error konfigurasi.
- Mempercepat iterasi tim operasional.

### Ruang Lingkup MVP
- Simulasi rule terhadap data order historis.
- Bandingkan dampak before vs after.
- Approval workflow sederhana sebelum publish.

### Risiko & Mitigasi
- **Risiko:** Data historis sensitif.
- **Mitigasi:** Masking data personal + role-based access.

### Prompt Implementasi
```text
Buat Rule Simulation Sandbox untuk plugin:
- User admin dapat membuat "draft ruleset".
- Jalankan draft terhadap sampel order historis (mis. 30 hari terakhir).
- Tampilkan metrik dampak: COD approval rate, rata-rata ongkir, estimasi margin, potensi RTO.
- Sediakan diff viewer rule lama vs baru.
- Publish ruleset hanya jika status approved.

Implementasi wajib:
- Versioning ruleset.
- Audit trail siapa mengubah apa.
- Eksekusi simulasi async (background job) dengan progress indicator.
```

---

## 9) Smart Recommendation Shipping Method (Checkout Nudging)

### Masalah
Terlalu banyak opsi layanan kirim bisa membuat user bingung dan meningkatkan drop-off.

### Nilai Bisnis
- Meningkatkan checkout completion.
- Mendorong pilihan metode yang lebih efisien untuk margin.

### Ruang Lingkup MVP
- Rekomendasi default shipping method dengan scoring.
- Label “Best Value” / “Fastest” / “Cheapest”.
- Eksperimen A/B untuk mengevaluasi dampak.

### Risiko & Mitigasi
- **Risiko:** Bias rekomendasi dianggap manipulatif.
- **Mitigasi:** Transparansi label + user tetap bebas memilih opsi lain.

### Prompt Implementasi
```text
Tambahkan Smart Shipping Recommendation pada checkout:
- Buat scoring untuk setiap service berdasarkan harga, ETA, reliability, dan margin impact.
- Tandai satu opsi sebagai default recommendation.
- Tampilkan badge: Best Value / Fastest / Cheapest.
- Jalankan A/B testing sederhana antara mode "tanpa rekomendasi" vs "dengan rekomendasi".

Kebutuhan:
- Event tracking untuk pilihan user.
- Dashboard dampak ke conversion rate dan average shipping cost.
- Fallback aman jika data scoring belum tersedia.
```

---

## 10) Observability Dashboard & Anomali Ongkir

### Masalah
Gangguan provider, cache bermasalah, atau spike ongkir sering baru ketahuan setelah ada komplain.

### Nilai Bisnis
- Deteksi dini insiden checkout.
- Menurunkan revenue loss akibat gangguan tersembunyi.

### Ruang Lingkup MVP
- Dashboard metrik utama: success rate API, fallback rate, cache hit rate, median response time.
- Alert anomali (mis. ongkir naik >X% mendadak di area tertentu).

### Risiko & Mitigasi
- **Risiko:** Alert fatigue.
- **Mitigasi:** threshold adaptif + deduplikasi alert.

### Prompt Implementasi
```text
Bangun Observability Dashboard untuk modul ongkir:
- Kumpulkan metrik teknis dan bisnis:
  - API success/error rate,
  - timeout rate,
  - cache hit/miss,
  - fallback usage,
  - distribusi ongkir per area/kurir.
- Deteksi anomali berbasis baseline harian/mingguan.
- Kirim notifikasi ke email/Slack jika anomali melewati threshold.

Deliverable:
- Skema event metric.
- Job agregasi periodik.
- Halaman admin dashboard + filter waktu/provider/area.
```

---

## Prioritas Implementasi yang Disarankan

### Gelombang 1 (Quick Wins)
1. Address Intelligence
2. Packaging Optimizer
3. Observability Dashboard

### Gelombang 2 (Impact Tinggi)
4. Multi-Origin Smart Split Shipment
5. COD Risk & RTO Score
6. Smart Recommendation Shipping Method

### Gelombang 3 (Scale & Governance)
7. Rule Simulation Sandbox
8. Post-Checkout Cost Reconciliation
9. Pickup Point / PUDO
10. Delivery Promise Engine

## KPI Evaluasi
- Checkout conversion rate.
- Persentase fallback usage.
- Selisih ongkir estimasi vs aktual.
- COD RTO rate.
- Komplain terkait keterlambatan/pengiriman gagal.
