# Fantasy Score — Dokumentasi Teknis

Dokumen ini menjelaskan secara teknis cara sistem menghitung **Fantasy Score** untuk anggota yang bermain di match DotA 2, termasuk formula tiap komponen, bobot, dan lokasi kode yang relevan.

---

## 1) Gambaran Umum

Fantasy Score adalah angka agregat (0–100 pts) yang mengukur performa seorang pemain dalam satu match. Skor ini dirancang role-neutral — pemain support dengan healing dan assist tinggi bisa memiliki skor setara dengan carry yang deal damage besar.

Skor dihitung dalam dua konteks berbeda:

| Konteks | File | Fungsi | Output |
|---|---|---|---|
| **Per-match** | `app/Jobs/ProcessMatchNotification.php` | `calculateFantasyRanking()` | Ranking inline di notifikasi match |
| **Weekly** | `app/Console/Commands/WeeklySummaryCommand.php` | `calculateFantasyScore()` | Rata-rata skor per member untuk weekly MVP |

Definisi bobot disimpan secara terpusat di:

```
app/DataObjects/FantasyWeights.php
```

---

## 2) Bobot Komponen

Skor final = jumlah dari 7 komponen, masing-masing dikalikan bobotnya, lalu dikali 100.

```
score = (Σ component_value × weight) × 100
```

| # | Komponen | Bobot | Kategori |
|---|---|---|---|
| 1 | Kill Participation | **25%** | Team-relative |
| 2 | Hero Damage Share | **20%** | Team-relative |
| 3 | KDA Normalized | **15%** | Individual |
| 4 | Tower Damage Share | **12%** | Team-relative |
| 5 | Economy Percentile | **10%** | Benchmark |
| 6 | Efficiency Score | **10%** | Individual |
| 7 | Healing Impact | **8%** | Team-relative |
| | **Total** | **100%** | |

> Filosofi pembagian bobot:
> - **Team-relative** (73%): kill participation + damage/tower/healing share — dibandingkan vs. setim, bukan seluruh 10 pemain.
> - **Individual** (25%): KDA + efficiency — murni performa sendiri.
> - **Benchmark** (10%): GPM/XPM percentile dari data benchmark OpenDota.
>
> Catatan: Healing Impact kini sepenuhnya **team-relative** (tidak lagi hybrid dengan benchmark).

---

## 3) Formula Tiap Komponen

Semua nilai komponen berada di rentang **0.0 – 1.0** sebelum dikalikan bobot dan faktor 100.

### 3.1 Kill Participation (25%)

Mengukur seberapa besar pemain terlibat dalam kill tim sendiri.

```
kill_participation = min((kills + assists) / team_kills, 1.0)
```

- `team_kills` = `radiant_score` untuk pemain Radiant, `dire_score` untuk Dire.
- Di-cap 1.0 untuk menghindari nilai > 100%.
- Jika `team_kills = 0`, nilainya 0.

### 3.2 Hero Damage Share (20%)

Proporsi hero damage pemain dibanding total damage seluruh tim.

```
hero_damage_share = min(player_hero_damage / team_hero_damage, 1.0)
```

- `team_hero_damage` = jumlah `hero_damage` seluruh 5 pemain setim.
- Jika `team_hero_damage = 0`, nilainya 0.

### 3.3 KDA Normalized (15%)

KDA individual yang di-cap agar tidak mendominasi skor hanya karena satu match luar biasa.

```
kda_normalized = min((kills + assists) / (deaths + 1), 10) / 10
```

- `deaths + 1` agar tidak terjadi division by zero.
- Nilai KDA di-cap 10 sebelum dinormalisasi ke rentang 0.0–1.0.

### 3.4 Tower Damage Share (12%)

Proporsi tower damage pemain dibanding total tower damage tim.

```
tower_damage_share = min(player_tower_damage / team_tower_damage, 1.0)
```

- `team_tower_damage` = jumlah `tower_damage` seluruh 5 pemain setim.
- Jika `team_tower_damage = 0`, nilainya 0.

### 3.5 Economy Percentile (10%)

Efisiensi ekonomi pemain berdasarkan percentile dari benchmark OpenDota (data global), bukan relatif terhadap match itu saja.

```
economy_percentile = (gpm_percentile + xpm_percentile) / 2
```

- `gpm_percentile` = `benchmarks['gold_per_min']['pct']` dari data OpenDota (0.0–1.0).
- `xpm_percentile` = `benchmarks['xp_per_min']['pct']` dari data OpenDota (0.0–1.0).
- Jika hanya salah satu yang tersedia, gunakan nilai yang ada.
- Jika keduanya tidak tersedia (data unparsed), nilainya 0.

### 3.6 Efficiency Score (10%)

Mengukur seberapa efisien pemain menghasilkan output (damage + healing) dari gold yang dimiliki. Healing ikut diperhitungkan agar support tidak dirugikan.

```
efficiency = (hero_damage + hero_healing) / net_worth
efficiency_score = efficiency / team_max_efficiency
```

- `team_max_efficiency` = nilai efisiensi tertinggi di antara **seluruh 5 pemain setim** (bukan hanya anggota terdaftar).
- Jika `net_worth = 0`, digunakan nilai minimum 1 untuk menghindari division by zero.
- Hasil akhir di-cap implisit karena pembagi adalah nilai maksimum satu tim.

> **Implementasi `calculatePlayerEfficiency()`** (identik di kedua file):
> ```
> efficiency = (hero_damage + (hero_healing × 1)) / max(net_worth, 1)
> ```
> Catatan: healing dikalikan 1 (tidak ada multiplier khusus, meski komentar kode menyebut "weighted 2×" — nilai aktual tetap 1× sesuai implementasi saat ini).

### 3.7 Healing Impact (8%)

Mengukur seberapa signifikan kontribusi healing pemain relatif terhadap tekanan damage yang diberikan tim lawan.

```
team_heal_ratio = team_healing / max(enemy_damage_to_team, 1)
player_share    = player_healing / team_healing
healing_impact  = min(player_share × team_heal_ratio, 1.0)
```

- `team_healing` = jumlah `hero_healing` seluruh 5 pemain setim.
- `enemy_damage_to_team` = jumlah `hero_damage` seluruh 5 pemain tim lawan.
- `team_heal_ratio` mengukur seberapa besar healing tim menutup damage yang diterima dari lawan — semakin tinggi rasio ini, semakin berharga setiap unit healing.
- `player_share` = proporsi healing pemain dalam total healing tim.
- Di-cap 1.0 untuk menghindari nilai > 100%.
- Jika `team_healing = 0`, nilainya 0.

---

## 4) Kalkulasi Team Stats

Sebelum komponen dapat dihitung, sistem perlu mengetahui total hero_damage, tower_damage, dan hero_healing per tim. Ini dihitung dari seluruh 10 pemain dalam match.

**Cara menentukan tim pemain:**

```
is_radiant = player_slot < 128
```

Pemain Radiant: `player_slot` 0–127. Pemain Dire: `player_slot` 128–255.

**Implementasi (digunakan di dua file dengan nama berbeda):**

| File | Method |
|---|---|
| `ProcessMatchNotification.php` | `calculateTeamStats(array $allPlayers, array $matchData): array` |
| `WeeklySummaryCommand.php` | `calculateTeamStatsForMatch(array $allPlayers): array` |

Kedua method menghasilkan struktur yang identik:

```php
[
    'radiant' => ['hero_damage' => ..., 'tower_damage' => ..., 'hero_healing' => ...],
    'dire'    => ['hero_damage' => ..., 'tower_damage' => ..., 'hero_healing' => ...],
]
```

---

## 5) Konteks 1: Per-Match (ProcessMatchNotification)

**File:** `app/Jobs/ProcessMatchNotification.php`

### Alur Pemanggilan

```
generateFantasyMVP()
    └── calculateFantasyRanking()
            ├── calculateTeamStats()
            └── calculatePlayerEfficiency()
```

### Eligibilitas

Fantasy Score per-match hanya dihitung jika:
- Ada **≥ 2 anggota terdaftar dari platform yang sama** (WhatsApp atau Telegram) yang bermain di match tersebut.
- Perbandingan skor hanya dilakukan antar anggota dari platform yang sama.

### Method: `calculateFantasyRanking()`

Menerima `$destinationType` (`'whatsapp'` atau `'telegram'`), memfilter `$memberPlayers` hanya yang platform-nya sesuai, lalu menghitung 7 komponen untuk tiap pemain yang eligible.

Mengembalikan koleksi diurutkan descending by `score`, dengan struktur tiap item:

```php
[
    'member'     => Member,   // Model anggota
    'hero_name'  => string,
    'is_radiant' => bool,
    'score'      => float,    // 0–100
    'components' => [
        'kill_participation'  => float,
        'hero_damage_share'   => float,
        'tower_damage_share'  => float,
        'healing_impact'      => float,
        'kda_normalized'      => float,
        'efficiency_score'    => float,
        'economy_percentile'  => float,
    ],
]
```

### Method: `generateFantasyMVP()`

Menggunakan hasil `calculateFantasyRanking()` untuk memilih label MVP dan memformat bagian ranking di pesan notifikasi. Menentukan apakah match dimenangkan atau dikalahkan oleh best player untuk memilih set label yang sesuai.

---

## 6) Konteks 2: Weekly (WeeklySummaryCommand)

**File:** `app/Console/Commands/WeeklySummaryCommand.php`

### Alur Pemanggilan

```
formatWeeklyAwards()
    └── calculateFantasyScore()
            ├── calculateTeamStatsForMatch()
            └── calculatePlayerEfficiency()
```

### Method: `calculateFantasyScore()`

Menghitung rata-rata Fantasy Score seorang member di **seluruh match** yang relevan dalam periode satu minggu.

```
weekly_fantasy_score = (Σ match_scores) / total_scored_matches
```

- Setiap match yang memuat member tersebut di `match.members` akan dihitung.
- Skor tiap match dihitung dengan formula yang identik dengan per-match.
- Jika member tidak ditemukan di data player suatu match, match itu dilewati.
- Mengembalikan `0.0` jika tidak ada match yang valid.

### Syarat Weekly Awards

Member harus memiliki **minimum 5 match** dalam periode untuk masuk dalam ranking weekly awards. Cek ini dilakukan di `formatWeeklyAwards()` sebelum memanggil `calculateFantasyScore()`.

---

## 7) Radar Chart (Perbandingan Visual)

**File:** `app/Jobs/ProcessMatchNotification.php`

### Method: `shouldSendChart()`

Menentukan apakah grafik radar harus dibuat dan dikirim.

```
Syarat (semua harus terpenuhi):
1. Match dimenangkan oleh tim anggota
2. Ranking memiliki ≥ 2 pemain
3. Skor pemain #1 ≥ 50 pts
4. Skor pemain #2 ≥ 50 pts
5. Selisih skor (#1 - #2) ≤ 5 pts
```

### Method: `generateChartImage()`

Jika syarat terpenuhi, membuat grafik radar PNG menggunakan Node.js.

- **Script:** `resources/node/mvp-comparison.js`
- **Output sementara:** `storage/app/private/mvp-radars/{match_id}_{destination}.png`
- File dihapus otomatis setelah berhasil dikirim.
- Grafik membandingkan 7 komponen skor antara dua pemain teratas secara visual.
- Urutan label sumbu grafik menggunakan `FantasyWeights::labels()`.

---

## 8) Sumber Data Komponen

| Komponen | Sumber Data | Field OpenDota |
|---|---|---|
| Kill Participation | Match data | `players[].kills`, `players[].assists`, `radiant_score`/`dire_score` |
| Hero Damage Share | Match data | `players[].hero_damage` |
| KDA Normalized | Match data | `players[].kills`, `players[].assists`, `players[].deaths` |
| Tower Damage Share | Match data | `players[].tower_damage` |
| Economy Percentile | Benchmark OpenDota | `players[].benchmarks.gold_per_min.pct`, `players[].benchmarks.xp_per_min.pct` |
| Efficiency Score | Match data | `players[].hero_damage`, `players[].hero_healing`, `players[].net_worth` |
| Healing Impact | Match data | `players[].hero_healing` (tim sendiri), `players[].hero_damage` (tim lawan) |

Benchmark OpenDota hanya tersedia jika match sudah melalui proses parsing. Pada match yang belum parsed, kolom benchmark bisa bernilai `null`, dan komponen terkait akan fallback ke `0`.

---

## 9) Lokasi Kode Lengkap

| File | Method | Deskripsi |
|---|---|---|
| `app/DataObjects/FantasyWeights.php` | `weights()` | Definisi 7 bobot komponen (single source of truth) |
| `app/DataObjects/FantasyWeights.php` | `labels()` | Label display tiap komponen (untuk radar chart) |
| `app/Jobs/ProcessMatchNotification.php` | `generateFantasyMVP()` | Orkestrasi MVP per-match + format output |
| `app/Jobs/ProcessMatchNotification.php` | `calculateFantasyRanking()` | Hitung skor 7 komponen per pemain, return ranking |
| `app/Jobs/ProcessMatchNotification.php` | `calculateTeamStats()` | Hitung total damage/healing per tim dari 10 pemain |
| `app/Jobs/ProcessMatchNotification.php` | `calculatePlayerEfficiency()` | Formula efisiensi: (damage + healing) / net_worth |
| `app/Jobs/ProcessMatchNotification.php` | `shouldSendChart()` | Cek kondisi pengiriman radar chart |
| `app/Jobs/ProcessMatchNotification.php` | `generateChartImage()` | Buat gambar radar chart via Node.js |
| `app/Console/Commands/WeeklySummaryCommand.php` | `calculateFantasyScore()` | Rata-rata skor per member lintas banyak match |
| `app/Console/Commands/WeeklySummaryCommand.php` | `calculateTeamStatsForMatch()` | Identik dengan `calculateTeamStats()` di atas |
| `app/Console/Commands/WeeklySummaryCommand.php` | `calculatePlayerEfficiency()` | Identik dengan implementasi di ProcessMatchNotification |
| `app/Console/Commands/WeeklySummaryCommand.php` | `formatWeeklyAwards()` | Gunakan `calculateFantasyScore()` untuk weekly MVP |
| `resources/node/mvp-comparison.js` | — | Script Node.js renderer radar chart PNG |
