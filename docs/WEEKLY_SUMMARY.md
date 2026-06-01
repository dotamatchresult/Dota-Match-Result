# Dokumentasi Ringkasan Mingguan DotA 2

Dokumen ini menjelaskan fitur **ringkasan mingguan** (`matches:weekly-summary`) yang mengirimkan rekap pertandingan setiap akhir pekan ke WhatsApp dan Telegram.

---

## 1) Cara Kerja Umum

Setiap akhir pekan, sistem menjalankan command `matches:weekly-summary` yang:

1. Mengambil semua match dari **Senin–Minggu minggu lalu** yang sudah ternotifikasi.
2. Memisahkan member berdasarkan platform tujuan (WhatsApp vs Telegram).
3. Mengirimkan **3 pesan** untuk WhatsApp dan **2 pesan** untuk Telegram.

Rentang waktu yang diambil adalah **minggu kalender sebelumnya** (Senin 00:00 hingga Minggu 23:59), bukan 7 hari terakhir dari sekarang.

### Jadwal

Command ini dijadwalkan sesuai setting `weekly_summary_enabled`. Bila setting bernilai `false`, command berhenti tanpa mengirim apapun.

---

## 2) Format Pesan

Tiap platform menerima urutan pesan yang berbeda:

| Urutan | WhatsApp                | Telegram                |
|--------|-------------------------|-------------------------|
| 1      | Ringkasan Umum Tim      | Ringkasan Umum Tim      |
| 2      | Penghargaan Mingguan    | Penghargaan Mingguan    |
| 3      | Ringkasan Individu      | _(tidak dikirim)_       |

---

## 3) Pesan 1 — Ringkasan Umum Tim

Berisi statistik agregat semua match minggu itu untuk kelompok member platform tersebut.

### Contoh Pesan

```
📊 **RINGKASAN MINGGUAN**
_(17-23 Februari 2025)_

📈 Statistik Tim
- Total Pertandingan: 12
- Win / Lose: 7 / 5 (58.3% WR)
- Rata-rata Durasi: 34 menit

⚔️ Statistik Pertempuran
- Avg. K/D/A: 8.2 / 6.1 / 14.3
- Total Hero Damage: 1.8M
- Total Tower Damage: 92.4k
- Hero Paling Sering: Queen of Pain (4x, 75% WR)
```

### Penjelasan Tiap Bagian

- **Rentang tanggal** — format `{tanggal-awal}-{tanggal-akhir} {bulan} {tahun}` dalam Bahasa Indonesia.
- **Total Pertandingan** — jumlah match yang sudah ternotifikasi di minggu tersebut.
- **Win / Lose** — jumlah menang dan kalah beserta win rate.
- **Rata-rata Durasi** — rata-rata durasi semua match dalam menit.
- **Avg. K/D/A** — rata-rata kills/deaths/assists **per match** dari seluruh member yang terdaftar (bukan per pemain).
- **Total Hero Damage / Tower Damage** — total akumulasi damage seluruh member di semua match. Angka besar ditampilkan dengan notasi `k` (ribuan) misalnya `92.4k`.
- **Hero Paling Sering** — hero yang paling sering dipilih oleh member manapun di kelompok itu, beserta jumlah game dan win rate hero tersebut.

---

## 4) Pesan 2 — Penghargaan Mingguan

Berisi penghargaan individual untuk berbagai kategori performa.

> **Syarat minimum**: member hanya masuk perhitungan bila bermain **≥ 2 match** dalam minggu itu.

### Contoh Pesan

```
🏆 **PENGHARGAAN MINGGUAN**
_(Minimum 2 pertandingan)_

👑 **MVP Minggu Ini** — Kudog
Fantasy Score: 72.4 (KDA 8.5, GPM 650, Hero Damage 28k)

🏅 **Hoki Menang Terus** — Kocrol
Win Rate: 85.0%

💰 **Paling Sugih** — Bajindoel
Avg. GPM: 734

🏗️ **Rajin Nyicil Tower** — Kudog
Avg. Tower Damage: 4.1k

⚔️ **Pejuang Barbar** — Bajindoel
Avg. Hero Damage: 31k

🛡️ **Paling Konsisten** — Kocrol
Avg. KDA: 6.2

☠️ **Tumbal Favorit** — Meong
Avg. Death: 9.3 per game
```

### Kategori Penghargaan

| Ikon | Kategori           | Dasar Penilaian                        |
|------|--------------------|----------------------------------------|
| 👑   | MVP Minggu Ini     | Fantasy Score tertinggi (lihat § 5)    |
| 🏅   | Hoki Menang Terus  | Win Rate tertinggi                     |
| 💰   | Paling Sugih       | Avg. GPM tertinggi                     |
| 🏗️   | Rajin Nyicil Tower | Avg. Tower Damage tertinggi            |
| ⚔️   | Pejuang Barbar     | Avg. Hero Damage tertinggi             |
| 🛡️   | Paling Konsisten   | Avg. KDA tertinggi                     |
| ☠️   | Tumbal Favorit     | Avg. Death per game tertinggi (anti-award) |

Setiap kategori bisa dimenangkan oleh member yang berbeda.

---

## 5) Cara Perhitungan Fantasy Score (MVP)

Fantasy Score dipakai untuk menentukan **MVP Minggu Ini**. Algoritma ini dirancang agar **support player memiliki peluang sama dengan carry player** — bukan hanya mengukur siapa yang punya kill atau damage terbesar.

### Filosofi

Fantasy Score dihitung **per match** lalu dirata-rata, bukan dari statistik agregat mingguan. Ini mencegah carry dengan GPM tinggi mendominasi hanya karena bermain lebih banyak game.

Setiap komponen bersifat **team-relative** (dibandingkan terhadap tim sendiri, bukan 10 pemain) atau **normalised** (dibagi nilai maksimum di match itu), sehingga role apapun bisa berkontribusi.

### Komponen (total = 100%)

| Komponen              | Bobot | Rumus                                                               |
|-----------------------|-------|---------------------------------------------------------------------|
| Kill Participation    | 20%   | `(kills + assists) / total_kills_tim` (max 1.0)                     |
| Hero Damage Share     | 20%   | `hero_damage_pemain / total_hero_damage_tim` (max 1.0)              |
| Healing Impact        | 15%   | Hybrid: rata-rata dari team share dan benchmark persentil healing   |
| KDA Normalised        | 15%   | `min((kills + assists) / (deaths + 1), 10) / 10`                   |
| Tower Damage Share    | 10%   | `tower_damage_pemain / total_tower_damage_tim` (max 1.0)            |
| Efficiency Score      | 10%   | `(hero_damage + hero_healing × 2) / net_worth`, normalised per match |
| Economy Percentile    | 10%   | Rata-rata persentil GPM dan XPM dari benchmark OpenDota            |

**Skor akhir** = rata-rata skor (× 100) dari semua match yang dimainkan member itu.

### Kenapa Support Tidak Dirugikan

- **Kill Participation** menghitung assists sama pentingnya dengan kills — support dengan banyak assists tetap bernilai tinggi.
- **Healing Impact** memberi nilai penuh untuk support yang menyembuhkan tim.
- **Efficiency Score** menggunakan `healing × 2` agar support yang punya net worth rendah tetap bisa bersaing dengan carry.
- **Economy Percentile** mengukur GPM/XPM relatif terhadap semua hero yang sama (per role), bukan angka mutlak.

---

## 6) Pesan 3 — Ringkasan Individu _(WhatsApp saja)_

Berisi kartu performa per member. Dikirim ke WhatsApp saja (Telegram melewati pesan ini).

### Contoh Pesan

```
👥 **RINGKASAN INDIVIDU**

**Kudog**
- Total 8 pertandingan (62.5% WR)
- Avg. K/D/A: 11.2 / 4.3 / 9.8
- Hero favorit: Queen of Pain

**Kocrol**
- Total 10 pertandingan (70.0% WR)
- Avg. K/D/A: 3.1 / 7.2 / 18.4
- Hero favorit: Lion

**Bajindoel**
- Total 6 pertandingan (50.0% WR)
- Avg. K/D/A: 9.5 / 6.7 / 11.1
- Hero favorit: Phantom Lancer
```

### Penjelasan Tiap Bagian

- **Total pertandingan** — jumlah match dalam minggu itu di mana member tersebut ikut.
- **Win Rate** — dalam persen.
- **Avg. K/D/A** — rata-rata kills / deaths / assists per game.
- **Hero favorit** — hero yang paling sering dimainkan member itu minggu ini.

Member yang tidak ikut match manapun minggu itu tidak ditampilkan.

---

## 7) Perbedaan WhatsApp vs Telegram

| Aspek                  | WhatsApp              | Telegram              |
|------------------------|-----------------------|-----------------------|
| Jumlah pesan           | 3                     | 2                     |
| Pesan Ringkasan Umum   | ✓                     | ✓                     |
| Pesan Penghargaan      | ✓                     | ✓                     |
| Pesan Ringkasan Individu | ✓                   | ✗                     |
| Delay antar pesan      | 2 detik               | 1 detik               |
| Nomor/grup tujuan      | `fonnte_phone_number` | `telegram_group_id`   |

---

## 8) Pengaturan (Settings)

| Key                      | Tipe    | Deskripsi                                                      |
|--------------------------|---------|----------------------------------------------------------------|
| `weekly_summary_enabled` | boolean | Aktifkan / nonaktifkan fitur ini. Default: `true`.             |
| `fonnte_phone_number`    | string  | Nomor WhatsApp tujuan (format: `628xxx`).                      |
| `telegram_group_id`      | string  | ID grup Telegram tujuan (format: `-100xxxx`).                  |

Setting dikelola lewat Filament admin panel di `/admin/settings`.

---

## 9) Error Handling

- Bila `fonnte_phone_number` tidak dikonfigurasi → penutup WhatsApp diskip dengan log warning.
- Bila `telegram_group_id` tidak dikonfigurasi → penutup Telegram diskip dengan log warning.
- Bila tidak ada match ditemukan → command berhenti lebih awal tanpa mengirim apapun.
- Tiap pesan yang gagal dikirim dicatat ke log aplikasi, pesan berikutnya tetap diproses.

---

## 10) Pertanyaan yang Sering Muncul

**Kenapa member saya tidak muncul di Penghargaan?**
Karena syarat minimum adalah **2 match** dalam satu minggu.

**Kenapa support saya tidak pernah jadi MVP padahal perform bagus?**
Pastikan data match sudah ter-*parse* oleh OpenDota (benchmarks tersedia), karena komponen Economy Percentile dan Healing Benchmark membutuhkan data parsed. Kalau match belum parsed, komponen itu bernilai 0.

**Kenapa kelompok WhatsApp dan Telegram mendapat angka berbeda?**
Karena match difilter per platform — hanya match di mana setidaknya satu member platform itu ikut yang dihitung. Jadi bila member WhatsApp dan Telegram bermain di match terpisah, statistiknya tidak akan sama.

**Kapan ringkasan dikirim?**
Sesuai jadwal cron di server. Periksa `routes/console.php` atau konfigurasi cron untuk jadwal pastinya.
