# Dokumentasi Notifikasi Hasil Pertandingan DotA 2

Dokumen ini menjelaskan cara kerja sistem notifikasi otomatis untuk hasil pertandingan DotA 2 yang kamu mainkan bersama anggota lain yang terdaftar.

## 📱 Format Pesan Notifikasi

Setiap kali kamu selesai bermain, sistem akan mengirimkan notifikasi otomatis ke grup WhatsApp atau Telegram dengan informasi lengkap tentang pertandingan tersebut.

### Contoh Pesan WhatsApp

```
🕹️ *Turbo* _(24 minutes)_

*Kudog, Kocrol, Bajindoel* won a game as Radiant

*Radiant* 37 ⚔️ 28 *Dire*

*Kudog* _(Queen of Pain)_ - 13/1/9
*Kocrol* _(Lion)_ - 3/12/15
*Bajindoel* _(Phantom Lancer)_ - 0/8/10

🔥 Highlights
- Queen of Pain secured 13 kills
- Phantom Lancer tutorial dulu biar bisa kill 📖

> Match Detail opendota.com/matches/8662122936
```

### Contoh Pesan Telegram

```
🕹️ **Turbo** _(18 minutes)_

**Kudog, Bajindoel** lost a game as Dire

**Radiant** 42 ⚔️ 31 **Dire**

**Kudog** _(Shadow Fiend)_ - 8/14/6
**Bajindoel** _(Axe)_ - 2/12/11

🔥 Highlights
- Quick game finished in 18 minutes ⏱️
- Axe ubur-ubur ikan lele, kontribusi lee 🪼

**Match ID** — 8662122937
```

## 📊 Penjelasan Komponen Pesan

### 1. Header (Baris Pertama)
- **Mode Permainan**: Jenis mode yang dimainkan (Turbo, All Pick, Ranked, dll)
- **Durasi**: Lama pertandingan dalam menit

### 2. Ringkasan Tim
- **Nama Pemain**: Daftar anggota yang terdaftar yang ikut bermain
- **Hasil**: Menang (won) atau kalah (lost)
- **Tim**: Radiant atau Dire

### 3. Skor Akhir
- **Radiant vs Dire**: Total kill kedua tim
- Format: `**Radiant** 37 ⚔️ 28 **Dire**`

### 4. Statistik Pemain
Untuk setiap pemain yang terdaftar:
- **Nama Pemain** dan **Hero** yang digunakan
- **K/D/A**: Kills (bunuh) / Deaths (mati) / Assists (bantu bunuh)
  - Contoh: `13/1/9` artinya 13 kills, 1 death, 9 assists

### 5. Highlights (Pencapaian Menarik)
Sistem secara otomatis memilih 2-3 pencapaian paling menarik dari pertandingan, seperti:
- Kill terbanyak
- KDA tertinggi
- Damage terbesar
- Pertandingan cepat atau lama
- Dan komen lucu untuk performa unik 😄

### 6. Link/ID Pertandingan
- **WhatsApp**: Link lengkap ke OpenDota untuk detail pertandingan
- **Telegram**: Match ID saja (lebih ringkas)

## 🔥 Cara Kerja Sistem Highlights

Sistem highlights dirancang untuk menampilkan pencapaian paling mengesankan atau momen lucu dari pertandinganmu.

### Pencapaian yang Dipertimbangkan

#### 🎯 Pencapaian Utama (Bobot Tinggi)

1. **Kill Terbanyak** (Bobot: 100)
   - Minimal 10 kills untuk masuk highlight
   - Bonus poin jika 15+ kills (epic!) atau 20+ kills (legendary!)
   - Contoh: "_Queen of Pain secured 13 kills_"

2. **Mati Paling Sedikit** (Bobot: 80)
   - Maksimal 3 deaths dan minimal 1 kill
   - Contoh: "_Shadow Fiend had only 2 deaths_"

3. **KDA Tertinggi** (Bobot: 90)
   - KDA = (Kills + Assists) ÷ Deaths
   - Minimal KDA 5.0 untuk masuk highlight
   - Bonus poin jika KDA 7+ (amazing!) atau 10+ (godlike!)
   - Contoh: "_Anti-Mage achieved 12.5 KDA_"

#### 💰 Pencapaian Ekonomi (Bobot Sedang)

4. **Net Worth Tertinggi** (Bobot: 60)
   - Total gold yang dikumpulkan
   - Minimal 15,000 gold
   - Contoh: "_Phantom Assassin reached 25.3k net worth_"

5. **GPM Tercepat** (Bobot: 50)
   - Gold Per Minute (kecepatan farming)
   - Minimal 500 GPM
   - Contoh: "_Alchemist farmed at 687 GPM_"

6. **XPM Tertinggi** (Bobot: 45)
   - Experience Per Minute
   - Minimal 600 XPM
   - Contoh: "_Invoker gained 745 XPM_"

#### ⚔️ Pencapaian Pertempuran (Bobot Menengah-Tinggi)

7. **Hero Damage Terbesar** (Bobot: 70)
   - Total damage ke hero musuh
   - Minimal 15,000 damage
   - Contoh: "_Zeus dealt 32.5k hero damage_"

8. **Tower Damage Terbesar** (Bobot: 65)
   - Damage ke tower/building
   - Minimal 3,000 damage
   - Contoh: "_Terrorblade wrecked 8.2k tower damage_"

9. **Assist Terbanyak** (Bobot: 55-60)
   - Minimal 15 assists
   - Bonus bobot jika support (last hit < 50)
   - Contoh: "_Crystal Maiden contributed 22 assists_"
   - Contoh support: "_Lion supported with 18 assists_"

10. **Healing Terbesar** (Bobot: 40)
    - Total HP yang di-heal ke tim
    - Minimal 5,000 healing
    - Contoh: "_Oracle healed 12.7k HP_"

#### ⏱️ Pencapaian Waktu (Spesial)

11. **First Blood Cepat**
    - First blood dalam 20 detik pertama (sangat cepat untuk Turbo)
    - Contoh: "_First blood secured at 15 seconds 🩸_"

12. **Durasi Pertandingan**
    - Game cepat: < 18 menit
      - "_Quick game finished in 16 minutes ⏱️_"
    - Game epik: > 40 menit
      - "_Epic game lasted 47 minutes 🕰️_"

#### 😂 Highlight Lucu (Otomatis)

13. **Nol Kill**
    - Muncul otomatis jika ada pemain dengan 0 kills
    - Pesan random dipilih dari 8 variasi, seperti:
      - "_[Hero] ubur-ubur ikan lele, kontribusi lee 🪼_"
      - "_[Hero] tutorial dulu biar bisa kill 📖_"
      - "_[Hero] nggak kill, 'tadi di ks' katanya 🐒_"
      - "_[Hero] killnya disave buat next match 🤡_"
      - "_[Hero] jualan telur nih? 🥚_"

14. **Mati Banyak**
    - Muncul otomatis jika ada pemain dengan 12+ deaths
    - Contoh: "_Pudge mati selusin 👻_"

### Algoritma Pemilihan Highlight

Sistem bekerja dengan cara:

1. **Evaluasi Semua Pencapaian**
   - Sistem membandingkan statistik setiap pemain terdaftar dengan semua 10 pemain di pertandingan
   - Hanya pencapaian yang jadi "yang terbaik" di pertandingan yang dipertimbangkan

2. **Penerapan Threshold**
   - Pencapaian harus melewati batas minimum untuk dianggap "mengesankan"
   - Contoh: Kill terbanyak harus minimal 10 kills, tidak cukup hanya 5 kills

3. **Perhitungan Bobot**
   - Setiap pencapaian diberi bobot sesuai dampaknya di game
   - Kill dan KDA punya bobot tertinggi (100 dan 90)
   - Healing punya bobot paling rendah (40)
   - Bonus bobot diberikan untuk pencapaian luar biasa

4. **Seleksi Top Highlight**
   - Sistem memilih 6 highlight dengan bobot tertinggi
   - Dari 6 itu, dipilih secara acak 2 highlight untuk ditampilkan
   - Tujuannya agar tidak monoton dan selalu fresh

5. **Penambahan Highlight Otomatis**
   - First blood cepat ditambahkan di awal (jika ada)
   - Durasi game unik ditambahkan di awal (jika ada)
   - Highlight lucu (0 kill atau mati banyak) ditambahkan di akhir (jika ada)

6. **Hasil Akhir**
   - Maksimal 2 highlight pencapaian + highlight spesial
   - Biasanya total 2-4 baris highlight per pesan

### Contoh Skenario

**Pertandingan dengan Berbagai Pencapaian:**

Statistik:
- Kudog (QoP): 18/3/12, 850 GPM, 45k damage
- Kocrol (Lion): 2/8/25, 8k healing
- Bajindoel (PL): 0/10/5, 200 GPM

Bobot yang dihitung:
- QoP 18 kills → Bobot 120 (100 + bonus 20)
- QoP 12.5 KDA → Bobot 100 (90 + bonus 10)
- QoP 850 GPM → Bobot 50
- QoP 45k damage → Bobot 70
- Lion 25 assists → Bobot 55
- Lion 8k healing → Bobot 40
- PL 0 kills → Highlight lucu otomatis
- PL 10 deaths → "Mati selusin" otomatis

Highlight yang dipilih (top 6 → random 2):
1. QoP 18 kills (120) ✓ **Terpilih random**
2. QoP 12.5 KDA (100)
3. QoP 45k damage (70)
4. Lion 25 assists (55)
5. QoP 850 GPM (50)
6. Lion 8k healing (40) ✓ **Terpilih random**

Hasil akhir:
```
🔥 Highlights
- Queen of Pain secured 18 kills
- Lion healed 8k HP
- Phantom Lancer ubur-ubur ikan lele, kontribusi lee 🪼
```

## 🔄 Proses Parsing Pertandingan

Tidak semua statistik pertandingan tersedia langsung setelah game selesai. Ada dua jenis data:

### 1. Data Dasar (Unparsed)
- **Tersedia**: Segera setelah game selesai
- **Isi**: Informasi dasar seperti:
  - Hero yang digunakan
  - K/D/A (Kills/Deaths/Assists)
  - Win/Loss
  - Tim (Radiant/Dire)
  - Skor akhir
  
- **Notifikasi**: Pesan pertama dikirim dengan data ini
- **Highlights**: Masih bisa menampilkan highlight tapi lebih terbatas (hanya K/D/A based)

### 2. Data Lengkap (Parsed)
- **Tersedia**: 5-10 menit setelah game selesai
- **Isi**: Statistik detail seperti:
  - Hero damage, tower damage, healing
  - GPM (Gold Per Minute)
  - XPM (Experience Per Minute)
  - Net worth (total gold)
  - Last hits
  - Item builds
  - Replay data lengkap

- **Proses**: Sistem otomatis meminta parsing ke OpenDota
- **Status**: Bisa dicek dengan perintah khusus atau melalui link OpenDota
- **Highlights**: Highlight jadi lebih lengkap dan akurat

### Alur Proses Lengkap

```
Game Selesai
    ↓
Sistem deteksi match baru (5 menit sekali)
    ↓
┌─────────────────────────────────┐
│   Kirim Notifikasi Pertama      │
│   (Data Dasar + Highlight)      │
└─────────────────────────────────┘
    ↓
Request parsing ke OpenDota
    ↓
Tunggu 5-10 menit
    ↓
┌─────────────────────────────────┐
│      Data Parsed Tersedia       │
│   (Statistik Detail Lengkap)    │
└─────────────────────────────────┘
    ↓
Disimpan untuk analisis AI
```

**Catatan Penting:**
- Kamu akan tetap dapat notifikasi pertama dengan cepat (dalam 5 menit)
- Data lengkap akan diproses di background untuk analisis AI
- Tidak ada notifikasi kedua, cukup cek link OpenDota untuk detail lengkap

## 🤖 Analisis AI untuk Pertandingan yang Kalah

Sistem dilengkapi dengan AI yang menganalisis pertandingan khusus untuk game yang **kalah**, memberikan insight dan saran perbaikan.

### Kapan AI Analisis Berjalan?

Analisis AI **HANYA** berjalan jika memenuhi syarat:

1. **Pertandingan Sudah Parsed**
   - Data lengkap sudah tersedia (5-10 menit setelah game)
   - Status parsing = "parsed"

2. **Jumlah Anggota Cukup**
   - Minimal **3 orang anggota Telegram** yang main, ATAU
   - Minimal **2 orang anggota WhatsApp** yang main
   - Alasan: Analisis tim lebih berguna untuk party, bukan solo

3. **Pertandingan Kalah**
   - AI fokus pada analisis kekalahan untuk belajar dari kesalahan
   - Win streak tidak perlu dianalisis (sudah bagus!)

### Apa yang Dianalisis?

AI melihat berbagai aspek pertandingan kalahmu:

#### 1. **Performa Individu**
- Efektivitas farming (last hits, GPM, net worth)
- Kontribusi pertempuran (damage, kills, assists)
- Deaths yang tidak perlu (positioning, overextend)
- Item build dan timing
- Performa hero vs rata-rata pemain lain

#### 2. **Kerjasama Tim (Party)**
- Koordinasi teamfight
- Map awareness dan warding
- Rotasi dan response terhadap gank
- Objektif priority (tower vs kill)
- Draft/hero synergy

#### 3. **Strategi Game**
- Pace permainan (early/mid/late game)
- Map control dan vision
- Farming efficiency vs helping team
- Push timing dan throne race decision
- Comeback potential yang terlewat

#### 4. **Perbandingan dengan Tim Lawan**
- Kenapa mereka bisa menang?
- Keunggulan mereka di fase mana?
- Hero composition advantage
- Skill gap atau strategi gap?

### Format Hasil Analisis

Analisis dikirim dalam pesan terpisah ke grup WhatsApp/Telegram dengan format:

```
*Analisis kekalahan (8662122936):*

Pertandingan ini kalah di fase early game karena:

1. **Farming tidak optimal**: 
   Rata-rata last hits tim kalian 30% lebih rendah dari musuh di 10 menit pertama. Kudog dan Bajindoel saling berebut lane, harusnya ada yang jungle.

2. **Deaths yang bisa dihindari**:
   Kocrol mati 12 kali, kebanyakan karena positioning terlalu maju tanpa vision. Ward harus lebih sering dipasang sebelum push.

3. **Tidak fokus objektif**:
   Tim musuh sudah ambil 3 tower tapi tim kalian masih farming. Seharusnya respond push mereka atau counter-push lane lain.

Saran untuk game berikutnya:
- Tentukan lane/jungle dari awal
- Support lebih aktif ward
- Push bersama setelah win teamfight
- Jangan chase kill terlalu jauh
```

### Bahasa Analisis

- **Bahasa**: Bahasa Indonesia (sesuai konteks tim)
- **Tone**: Santai tapi informatif, bukan menggurui
- **Fokus**: Actionable advice, bukan blame
- **Panjang**: 3-5 paragraf singkat

### Teknologi di Balik AI

- **Model**: OpenAI GPT-4 (pintar banget!)
- **Data Input**: 
  - Statistik lengkap semua 10 pemain
  - Timeline events (kills, deaths, objectives)
  - Farming patterns dan economy
  - Draft composition
  
- **Processing**: 
  - Metrics computation (KDA efficiency, gold diff, XP diff, dll)
  - Pattern recognition dari ribuan pertandingan
  - Contextual analysis based on game meta

- **Output**: Analisis dalam bentuk teks natural yang mudah dimengerti

### Privacy dan Data

- Analisis hanya untuk pertandingan yang ada anggota terdaftar
- Tidak di-share ke public atau pihak ketiga
- Tidak ada tracking performa jangka panjang (privacy-first)
- Data cuma diproses untuk analisis immediate

---

## 📝 Catatan Tambahan

### Perbedaan WhatsApp vs Telegram

| Aspek | WhatsApp | Telegram |
|-------|----------|----------|
| **Format teks** | Markdown standar | Markdown standar |
| **Link match** | URL lengkap OpenDota | Match ID saja |
| **Emoji** | Sama | Sama |
| **Panjang pesan** | < 4096 karakter | < 4096 karakter |

### Pertanyaan Umum

**Q: Kenapa kadang highlight cuma ada 1-2?**  
A: Karena tidak semua pertandingan punya pencapaian yang melewati threshold minimum. Kalau game biasa-biasa aja, highlight memang lebih sedikit.

**Q: Kenapa highlight saya tidak muncul padahal saya rasa bagus?**  
A: Highlight dipilih berdasarkan perbandingan dengan **semua 10 pemain** di game tersebut, bukan hanya anggota yang terdaftar. Jadi kalau ada musuh yang lebih bagus, pencapaianmu tidak masuk top highlight.

**Q: Kok ada highlight lucu/roasting?**  
A: Sistem otomatis menambahkan komen lucu untuk performa unik (0 kills atau 12+ deaths). Ini untuk bikin suasana lebih santai dan fun! 😄

**Q: Berapa lama notifikasi muncul setelah game?**  
A: Maksimal 5 menit. Sistem check setiap 5 menit, jadi worst case ya 5 menit setelah game selesai.

**Q: Kenapa tidak ada analisis AI untuk game yang menang?**  
A: AI fokus pada pembelajaran dari kekalahan. Menang sudah bagus, yang perlu diperbaiki adalah kesalahan di game yang kalah.

**Q: Apakah analisis AI bisa salah?**  
A: AI sangat pintar tapi bukan dewa. Analisis based on data, jadi kadang miss context seperti komunikasi voice atau strategi khusus yang tidak terekam di stats.

---

**Dokumentasi ini dibuat untuk membantu kamu memahami cara kerja sistem notifikasi. Semoga bermanfaat! 🎮**
