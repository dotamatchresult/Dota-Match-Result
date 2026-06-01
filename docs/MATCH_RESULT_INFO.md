# Dokumentasi Notifikasi Hasil Pertandingan DotA 2 (Pesan Pertama / Unparsed)

Dokumen ini menjelaskan **pesan hasil match pertama** yang dikirim sistem ke WhatsApp dan Telegram setelah match selesai.

Istilah “**unparsed**” di sini artinya: data pertandingan **belum sepenuhnya diproses oleh OpenDota**, sehingga beberapa statistik detail bisa belum muncul (atau masih 0). Pesan pertama tetap dikirim cepat, lalu sistem bisa melanjutkan proses lanjutan untuk kebutuhan analisis AI.

---

## 1) Format Pesan (WhatsApp & Telegram)

Isi pesan pada dasarnya sama. Perbedaannya hanya pada **bagian penutup**:

- **WhatsApp**: teks tebal memakai `*...*` dan penutup berisi **link detail match**.
- **Telegram**: teks tebal memakai `*...*` dan penutup berisi **Match ID**.

### Contoh Pesan WhatsApp

```
🕹️ *Turbo* _(32 minutes)_

*Alfian*, *Aldi*, *Sindo* won a game as Radiant

*Radiant* 45 ⚔️ 31 *Dire*

*Alfian* _(Chaos Knight | Lv. 22)_ - 11/7/19
*Aldi* _(Witch Doctor | Lv. 18)_ - 6/7/25
*Sindo* _(Luna | Lv. 25)_ - 12/6/22

🔥 Highlights
- Witch Doctor supported with 25 assists
- Luna gained 2410 XPM

👨🏻‍💼 Cocok dadi PNS
- Sindo _(Luna | 53.81 pts)_

👷🏻 Pantese mung Swasta
- Aldi _(Witch Doctor | 53.2 pts)_
- Alfian _(Chaos Knight | 43.93 pts)_

> Match Detail opendota.com/matches/8712544750
```

### Contoh Pesan Telegram

```
🕹️ *Turbo* _(34 minutes)_

*Dodo Singo*, *Kudog*, *Asura*, *Bajindoel*, *Comle* lost a game as Dire

*Radiant* 49 ⚔️ 29 *Dire*

*Dodo Singo* _(Undying)_ - 11/12/13
*Kudog* _(Sniper)_ - 7/7/11
*Asura* _(Necrophos)_ - 6/13/11
*Bajindoel* _(Phantom Lancer)_ - 3/9/10
*Comle* _(Batrider)_ - 2/8/13

🔥 Highlights
- Undying mati selusin 👻
- Necrophos mati selusin 👻

🤒 Sing Gendong
- Dodo Singo _(Undying | 50.47 pts)_

🩼 Sing Digendong
- Bajindoel _(Phantom Lancer | 29.3 pts)_

*Match ID* — 8692331058
```

---

## 2) Arti Tiap Bagian Pesan

### A. Header (baris paling atas)
- **Mode permainan**: misalnya Turbo, All Pick, dll.
- **Durasi**: ditampilkan dalam menit (dibulatkan).

### B. Ringkasan hasil
Format umumnya:

- **Nama anggota yang terdaftar** (yang ikut di match itu)
- **won / lost**
- **sebagai Radiant / Dire**

Catatan: bila beberapa anggota ikut dalam match yang sama, namanya akan digabung dalam satu pesan (party).

### C. Skor akhir
Format:

- Radiant kill total ⚔️ Dire kill total

### D. Statistik tiap anggota
Untuk setiap anggota yang terdaftar:

- Nama
- Hero yang dipakai (beserta level karakter)
- K/D/A: `Kills/Deaths/Assists`

> **Perbedaan platform**: WhatsApp menampilkan nama hero beserta level, contoh: `_(Luna | Lv. 25)_`. Telegram hanya menampilkan nama hero tanpa level, contoh: `_(Luna)_`.

### E. Highlights
Bagian ini berisi ringkasan “yang menonjol” dari match.

Di mode **unparsed**, highlights bisa:
- Tetap muncul (misalnya berdasarkan kills/deaths/assists/KDA).
- Lebih sedikit atau lebih “sepi” bila statistik detail belum tersedia.

### F. Penutup
- **WhatsApp**: menampilkan link “Match Detail” ke OpenDota.
- **Telegram**: menampilkan “Match ID — ...”.
### G. Ranking / Penilaian Performa
Bagian ini muncul **setelah Highlights** dan menampilkan penilaian performa anggota berdasarkan skor kalkulasi. Format tiap entri: `Nama _(Hero | skor pts)_`.

Skor dihitung dari gabungan statistik match (kills, deaths, assists, damage, tower damage, XPM, GPM, dll.) yang dinormalisasi terhadap match tersebut.

Label yang dipakai bervariasi tergantung konteks (menang/kalah, posisi relatif antar anggota). Contoh label:

**Match menang:**
- 👨🏻‍💼 Cocok dadi PNS / 😎 Ternyata Pewaris CEO / 👑 Fantasy MVP — performa terbaik
- 👷🏻 Pantese mung Swasta / Karyawan PT ... / 💬 Ameh MVP — performa menengah

**Match kalah:**
- 🤕 Tulang Punggung / 🤒 Sing Gendong / 🫡 Wani Maju War — performa terbaik di antara yang kalah
- 🪜 Calon MVP — runner-up performa
- 🩼 Sing Digendong / 🪑 MVP (Most Vulnerable Player) / 🙄 Perlu Bimbingan / 🥴 Tulang Tulung — performa terendah

Bagian ini hanya muncul bila ada **lebih dari satu anggota** yang tercatat di match tersebut. Lihat **Section 4** untuk detail cara kalkulasi skor.
---

## 3) Cara Sistem Menentukan Highlights

Highlights dipilih otomatis dari performa match, dengan dua prinsip utama:

1. **Dibandingkan dengan semua 10 pemain di match itu**
   - Jadi highlight hanya keluar bila statistik kalian memang termasuk yang paling menonjol di match tersebut.
2. **Harus melewati batas minimal**
   - Misalnya “kill terbanyak” tidak akan keluar kalau kill-nya kecil.

### 3.1. Jenis Highlights yang Dinilai (beserta batas minimal)

Berikut jenis highlight yang bisa dipertimbangkan:

1. **Kill terbanyak**
   - Syarat: kill termasuk yang tertinggi di match, dan minimal **10 kills**.
   - Jika kill sangat tinggi, peluangnya makin besar untuk terpilih.

2. **Death paling sedikit**
   - Syarat: minimal punya **1 kill**, dan death termasuk yang paling sedikit di match, serta maksimal **3 deaths**.

3. **KDA tertinggi**
   - Rumus: (Kills + Assists) ÷ Deaths.
   - Syarat: KDA termasuk yang tertinggi di match, dan minimal **5.0**.

4. **GPM tertinggi**
   - Syarat: GPM termasuk yang tertinggi di match, dan minimal **500 GPM**.

5. **XPM tertinggi**
   - Syarat: XPM termasuk yang tertinggi di match, dan minimal **600 XPM**.

6. **Net worth tertinggi**
   - Syarat: net worth termasuk yang tertinggi di match, dan minimal **15.000**.

7. **Hero damage tertinggi**
   - Syarat: hero damage termasuk yang tertinggi di match, dan minimal **15.000**.

8. **Tower damage tertinggi**
   - Syarat: tower damage termasuk yang tertinggi di match, dan minimal **3.000**.

9. **Assist terbanyak**
   - Syarat: assist termasuk yang tertinggi di match, dan minimal **15 assists**.
   - Khusus role support: bila assist tinggi (≥ 15) dan last hit rendah (indikasi support), bisa dapat highlight “support assists”.

10. **Healing tertinggi**
   - Syarat: healing termasuk yang tertinggi di match, dan minimal **5.000**.

### 3.2. Prioritas ("mana yang dianggap lebih penting")

Sistem memberi prioritas berbeda untuk tiap jenis highlight.

Secara umum, yang paling “diprioritaskan” untuk tampil:

- Kill terbanyak
- KDA tertinggi
- Hero damage / tower damage
- Net worth
- Assist (termasuk support)
- GPM / XPM
- Healing

### 3.3. Cara Memilih Highlight yang Ditampilkan

Urutan prosesnya:

1. Sistem mengumpulkan semua kandidat highlight yang memenuhi syarat.
2. Sistem mengurutkan kandidat berdasarkan “kekuatan” (prioritas).
3. Sistem mengambil sampai **6 kandidat teratas**, lalu memilih **3 highlight utama** dari sana.
   - Tujuannya agar pesan tetap ringkas, tapi tetap bervariasi dari match ke match.

### 3.4. Highlight Tambahan (Spesial)

Di luar 3 highlight utama, ada beberapa highlight tambahan yang bisa ditambahkan:

1. **First blood sangat cepat**
   - Jika first blood terjadi dalam **≤ 20 detik**, akan ditambahkan di bagian atas highlights.

2. **Catatan tempo match**
   - Match cepat: durasi < **18 menit** → “Quick game …”
   - Match sangat lama: durasi > **40 menit** → “Epic game …”

3. **Highlight lucu / roasting ringan**
   - Bila ada anggota dengan **0 kills**, sistem menambahkan satu kalimat lucu acak.
   - Bila ada anggota dengan **≥ 12 deaths**, sistem bisa menambahkan kalimat seperti “mati selusin”.

Karena highlight tambahan ini “bonus”, total baris highlight bisa lebih dari 2.

---
## 4) Cara Sistem Menghitung Skor Ranking (Fantasy MVP)

Skor ranking dihitung per-platform (WhatsApp dan Telegram dihitung terpisah). Bagian ini hanya muncul bila minimal **2 anggota dari platform yang sama** bermain di match tersebut.

### 4.1. Komponen Skor (7 komponen, total 100%)

Skor akhir = jumlah semua komponen × 100, ditampilkan sebagai `pts`.

| # | Komponen | Bobot | Cara Hitung |
|---|---|---|---|
| 1 | Kill Participation | **25%** | `(kills + assists) / team_kills`, di-cap 1.0. Tim yang sama (Radiant/Dire) |
| 2 | Hero Damage Share | **20%** | `hero_damage / team_hero_damage`, di-cap 1.0. Tim yang sama |
| 3 | KDA Normalized | **15%** | `min((kills + assists) / (deaths + 1), 10) / 10` |
| 4 | Tower Damage Share | **12%** | `tower_damage / team_tower_damage`, di-cap 1.0. Tim yang sama |
| 5 | Economy Percentile | **10%** | Rata-rata persentil GPM dan XPM dari benchmark OpenDota (0.0–1.0) |
| 6 | Efficiency Score | **10%** | `(hero_damage + hero_healing × 1) / net_worth`, dinormalisasi terhadap nilai tertinggi di tim |
| 7 | Healing Impact | **8%** | Hybrid: jika data tim dan benchmark tersedia → `50% team share + 50% benchmark percentile`; jika hanya satu → gunakan itu saja |

> **Catatan:** Semua komponen berbasis tim (1, 2, 4, 5) hanya dibandingkan dengan pemain setim (Radiant vs Radiant, Dire vs Dire), bukan semua 10 pemain.

### 4.2. Label yang Digunakan

Setelah skor dihitung, sistem memilih **satu pasang label secara acak** dari daftar berikut:

**Match menang** — satu label untuk pemain skor tertinggi, satu label untuk runner-up (maks. 2):

| Label Terbaik | Label Runner-up |
|---|---|
| 👑 Fantasy MVP | 💬 Ameh MVP / Honorable Mention / Mlebu TV |
| 🐮 Komisaris/Penggagas/Direktur/Chef MBG | 🐐 Staff Dapur / Isah-isah Piring / Icip-icip MBG |
| 🐖 Dukun Pesugihan / Pemimpin Sekte | 🧛🏻 Pengikut / Tumbal Sekte / Tumbal Pesugihan |
| 👨🏻‍💼 Cocok dadi PNS | 👷🏻 Pantese mung Swasta |
| 😎 Ternyata Pewaris CEO | 👷🏻 Karyawan PT Yu Xian Chuox / Xianxu / ZHANG |
| 💃🏻 Oleh Duwe Bojo 2 _(Telegram only)_ | 🏳️ Bojo Siji Wae _(Telegram only)_ |

**Match kalah** — satu label untuk skor tertinggi, satu untuk skor terendah (hanya jika 3+ anggota):

| Label Terbaik | Label Terburuk |
|---|---|
| 🤕 Tulang Punggung | 🥴 Tulang Tulung |
| 🤒 Sing Gendong | 🩼 Sing Digendong |
| 🪜 Calon MVP | 🪑 MVP — _Most Vulnerable Player_ |
| 🫡 Wani Maju War | 🙄 Perlu Bimbingan |

### 4.3. Grafik Perbandingan (Radar Chart)

Jika suatu match menang dan performa dua anggota teratas cukup kompetitif, sistem akan mengirimkan **gambar grafik radar** (PNG) sebagai pelengkap bagian MVP.

**Syarat agar grafik dikirim** (semua harus terpenuhi):
1. Match **dimenangkan** oleh tim anggota.
2. Minimal **2 pemain** masuk dalam ranking.
3. Skor pemain peringkat 1 **≥ 50 pts**.
4. Skor pemain peringkat 2 **≥ 50 pts**.
5. Selisih skor antara peringkat 1 dan 2 **≤ 5 pts** (kompetitif).

**Isi grafik**: Membandingkan 7 komponen skor (lihat 4.1) antara dua pemain teratas secara visual dalam bentuk radar chart.

**Teknis**: Grafik dibuat via Node.js (`resources/node/mvp-comparison.js`) dan disimpan sementara di `storage/app/private/mvp-radars/`. File gambar dihapus otomatis setelah berhasil dikirim.

---
## 5) Proses Parsing (Kenapa Ada "Unparsed")

Tidak semua data statistik detail langsung tersedia. Karena itu sistem berjalan dalam dua tahap:

### Tahap 1 — Pesan hasil match pertama (cepat)
- Sistem mendeteksi match baru secara berkala.
- Sistem mengambil data match dari OpenDota.
- Sistem mengirim pesan hasil match (format di atas) secepat mungkin.

Pada tahap ini:
- K/D/A dan skor biasanya sudah ada.
- Statistik seperti hero damage, tower damage, net worth, GPM/XPM bisa:
  - Sudah ada, atau
  - Masih belum lengkap (ini yang disebut “unparsed”).

Match yang menang (`outcome != Lost`) tidak akan diproses lebih lanjut; `parse_status`-nya dibiarkan `null`.

### Tahap 2 — Parsing OpenDota (untuk match tertentu)

Hanya match yang **kalah** (`outcome = Lost`) yang masuk antrian parsing. Alur status parse-nya adalah:

1. **`pending`** — Ditetapkan saat match pertama kali dibuat (jika kalah).
2. Setelah **minimal 2 menit**, sistem mengevaluasi apakah match memenuhi syarat:
   - Match harus merupakan kekalahan.
   - Jumlah anggota yang terhubung ke platform harus mencukupi:
     - **WhatsApp**: minimal **2 anggota** (berdasarkan Steam ID unik).
     - **Telegram**: minimal **3 anggota** (berdasarkan Steam ID unik).
   - Syarat WhatsApp dan Telegram dievaluasi secara terpisah — cukup salah satu yang memenuhi.
   - Jika **tidak ada** platform yang memenuhi → status diubah ke **`failed`**, parse tidak dilanjutkan.
3. **`parsing`** — Jika memenuhi syarat, sistem mengirim request parse ke OpenDota dan status diubah ke `parsing`.
4. Setelah **minimal 2 menit** sejak request dikirim, sistem mengecek apakah parse sudah selesai:
   - Jika berhasil → data parsed tersedia, analisis AI bisa dilanjutkan.
   - Jika gagal setelah beberapa kali percobaan → status diubah ke **`failed`**.

Jika parsing gagal akhirnya, sistem bisa mengirim pesan kegagalan analisis (misalnya "Gagal memuat analisis kekalahan …").

---

## 6) Analisis AI Kekalahan

Selain pesan hasil match, ada juga **pesan terpisah** berupa analisis AI — khusus untuk match yang kalah.

### 6.1. Kapan analisis AI dikirim?
Analisis AI hanya dikirim bila:

- Match sudah punya data parsed yang cukup (termasuk data teamfight/objektif).
- Match berdurasi cukup (bukan remake).
- Tim kalian **kalah**.
- Jumlah anggota yang ikut match dan terhubung ke platform mencukupi:
  - **WhatsApp**: minimal **2 anggota** (Steam ID unik).
  - **Telegram**: minimal **3 anggota** (Steam ID unik).
  - Cukup salah satu platform yang memenuhi syarat.

### 6.2. Apa yang dianalisis?
AI menganalisis match yang kalah dengan fokus pada hero yang dimainkan anggota kalian, misalnya:

- Gambaran alur match (awal → tengah → akhir).
- Teamfight penting yang bikin keadaan memburuk.
- Perubahan momentum (misalnya rangkaian objektif beruntun).
- Kontribusi tiap hero (berdasarkan indikator seperti KDA, damage, dan impact score).

AI tidak bertujuan menyalahkan pemain. Outputnya dibuat singkat dan langsung ke inti.

### 6.3. Format pesan analisis
Pesan analisis dikirim dengan judul:

- `Analisis kekalahan (MATCH_ID):`

Lalu isi utamanya berupa **4–6 poin** dalam bentuk bullet.
Setiap poin dibuat singkat (langsung ke inti) dan berbasis data match.

---

## 7) Ringkas: WhatsApp vs Telegram

Konten pada dasarnya sama, dengan beberapa perbedaan berikut:

| Aspek | WhatsApp | Telegram |
|---|---|---|
| **Nama hero** | `_(Hero \| Lv. N)_` (dengan level) | `_(Hero)_` (tanpa level) |
| **Penutup pesan** | Link `> Match Detail opendota.com/matches/…` | `**Match ID** — …` |
| **Label MVP** | Semua pasang label berlaku | + Extra: `💃🏻 Oleh Duwe Bojo 2` / `🏳️ Bojo Siji Wae` |
| **Radar Chart** | ✅ Dikirim jika syarat terpenuhi | ✅ Dikirim jika syarat terpenuhi |

---

## 8) Pertanyaan yang Sering Muncul

**Kenapa highlights kadang sedikit?**
Karena highlight butuh “menonjol” dibanding semua 10 pemain, dan harus melewati batas minimal.

**Kenapa ada highlight lucu?**
Untuk bikin suasana party lebih santai. Ini otomatis, terutama kalau ada 0 kill atau death tinggi.

**Kenapa analisis AI tidak selalu muncul?**
Biasanya karena match belum berhasil diparse penuh, bukan match kalah, atau tidak memenuhi syarat jumlah anggota.
