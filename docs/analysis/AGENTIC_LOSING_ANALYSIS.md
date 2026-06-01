# Agentic Losing Analysis — Pipeline v2.1

## 0. Prinsip besar

**LLM = analis & narator**
**Laravel = akuntan & operator**

- Laravel **menghitung** (semua metrik adalah PHP murni, deterministic)
- LLM **menyimpulkan** dari hasil hitungan Laravel, bukan dari raw JSON
- LLM **tidak pernah** menerima data mentah OpenDota

---

## 1. High-level pipeline (8 agent)

```
Raw Match JSON
   ↓
Agent 1 — Match Gatekeeper (Laravel)
   ↓
Agent 2 — Normalizer (Laravel)
   ↓
Agent 3 — Teamfight Aggregator (Laravel)
   ↓
Agent 4 — Objective Flow (Laravel)
   ↓
Agent 5 — Player Impact Calculator (Laravel)
   ↓
Agent 6 — Derived Metrics (Laravel) ← MetricEngine + SemanticTagger + LossClassifier + HeroContribution
   ↓
Agent 6b — Context Enrichment (Laravel) ← EnemyThreat + ObjectiveFlowSummary + ScalingSummary
   ↓
Agent 7 — Diagnosis (LLM Stage 1 — JSON, temp 0.2)
   ↓
Agent 8 — Bullet Compressor (LLM Stage 2 — Indonesian bullets, temp 0.7)
   ↓
Formatter → Send to WhatsApp / Telegram
```

Alur konseptual:

> data → struktur → angka → klasifikasi → konteks makro → makna → bahasa → kirim

---

## 2. Agent-by-agent breakdown

---

### Agent 1 — Match Gatekeeper (`MatchGatekeeperAgent`)

**Tujuan**: Filter match yang tidak layak dianalisis.

**Logic**:

```php
if ($match['duration'] < 600)       → reject: duration_too_short
if (empty($match['teamfights']))    → reject: missing_teamfights
if (!isset($match['radiant_win'])) → reject: outcome_unknown
if ($memberTeam === null)           → reject: team_unknown
if ($memberWon)                     → reject: victory_not_defeat
```

**Output**: `GatekeeperResult { passed, reason, rejectCode }`

---

### Agent 2 — Normalizer (`NormalizerAgent`)

**Tujuan**: Ubah OpenDota chaos → struktur stabil. LLM tidak boleh menyentuh `player_slot`, hero ID, atau raw JSON.

**Proses**:
- Pass 1: Bangun `heroIndexMap[0..9]` → hero name untuk semua 10 slot (termasuk anonymous player). Formula: `fightIndex = slot < 128 ? slot : (slot - 128 + 5)`
- Pass 2: Bangun `NormalizedPlayer` hanya untuk player dengan `account_id` (member dan musuh yang diketahui)
- Hero ID di-resolve ke nama via `HeroService`
- Team tagging (`radiant` / `dire`) dilakukan di sini

**Output**: `NormalizedMatch { memberTeam, memberPlayers[], opposingPlayers[], heroIndexMap[], teamfights[], goldAdvantage[], ... }`

---

### Agent 3 — Teamfight Aggregator (`TeamfightAggregatorAgent`)

**Tujuan**: Turunkan *makna* dari teamfights mentah.

**Untuk setiap teamfight, hitung**:
- `direPlayers` = indeks 5–9, `radiantPlayers` = indeks 0–4
- `dire_kills`, `radiant_kills`, `dire_deaths`, `radiant_deaths`
- `dire_gold_delta`, `radiant_gold_delta`

**Outcome rule**:

```php
if (dire_gold_delta > 1000 && dire_deaths < radiant_deaths) → "dire_win"
elseif (radiant_gold_delta > 1000 && radiant_deaths < dire_deaths) → "radiant_win"
else → "even"
```

**Swing magnitude**: `|radiantGoldDelta - direGoldDelta| > 2000` → `"big"`, else `"medium"`

**Key heroes**: Ambil dari `heroIndexMap` berdasarkan player dengan `gold_delta` terbesar di sisi pemenang.

**Output**: `TeamfightSummary[] { index, time, outcome, swing, keyHeroes[] }`

---

### Agent 4 — Objective Flow (`ObjectiveFlowAgent`)

**Tujuan**: Jawab pertanyaan *fight itu jadi apa?*

**Dari `objectives[]`**:
- Kelompokkan: towers, barracks, Roshan, Aegis
- Hubungkan ke teamfight terdekat jika `|objective_time - fight_end_time| < 90 detik`
- Tandai `linkedFightIndex` pada setiap objective event

**Momentum shift**: Deteksi sequence fight win → objective → perubahan gold advantage.

**Output**: `ObjectiveFlow { events[], momentumShifts[], criticalObjective }`

---

### Agent 5 — Player Impact Calculator (`PlayerImpactAgent`)

**Tujuan**: Skor objektif per hero, murni dari data, tanpa opini LLM.

**Impact score formula**:

```text
impact_score = kills * 2 + assists + (net_worth / 500) - deaths * 1.5
```

**Sinyal tambahan**:
- `fightParticipation` = `deathsInAllFights / max(1, totalFights)` (dari data fight aktual, bukan estimasi)
- `deathsInLosingFights` = death aktual dari `fight['players'][playerIndex]['deaths']` di fight yang kalah
- `deathsInAllFights` = total death aktual di semua fight windows

**Label**: `"dominant"` | `"solid"` | `"average"` | `"struggling"` berdasarkan percentile impact score.

**Output**: `PlayerImpact[] { heroName, impactScore, impactLabel, kills, deaths, assists, deathsInLosingFights, deathsInAllFights, netWorth }`

---

### Agent 6 — Derived Metrics (`DerivedMetricsAgent`)

**Tujuan**: Orkestrasi 4 service deterministic sebelum LLM berjalan. Ini adalah "otak" analisis.

Terdiri dari 4 sub-service:

#### 6a. MetricEngineService

Menghitung 8 metrik float deterministic:

| Metrik | Deskripsi |
|---|---|
| `fightControlRatio` | Rasio fight yang dimenangkan member team (0–1) |
| `objectiveConversionRate` | Seberapa sering fight win dikonversi ke objective |
| `enemyPickoffRate` | Fraksi kematian member yang terjadi di luar fight window |
| `protectionIndex` | Seberapa baik core (player netWorth tertinggi) dilindungi di early game |
| `damageConcentrationRatio` | Konsentrasi damage ke satu hero (0 = merata, 1 = satu hero semua) |
| `scalingEarlyGold` | Gold advantage rata-rata menit 0–14 (dari member perspective) |
| `scalingMidGold` | Gold advantage rata-rata menit 15–29 |
| `scalingLateGold` | Gold advantage rata-rata menit 30+ |

#### 6b. SemanticTaggerService

Menghasilkan tag boolean dari threshold metrik:

| Tag | Kondisi |
|---|---|
| `high_pickoff_rate` | `enemyPickoffRate > 0.35` |
| `low_conversion_efficiency` | `objectiveConversionRate < 0.30` |
| `damage_dependency_core` | `damageConcentrationRatio > 0.50` |
| `late_game_outscaled` | `scalingLateGold < -2000 && scalingMidGold > 0` |
| `weak_protection_core` | `protectionIndex < 0.35` |
| `strong_early_game` | `scalingEarlyGold > 2000` |
| `fight_dominant` | `fightControlRatio > 0.65` |
| `lost_after_roshan` | Member team ambil Roshan → gold advantage jadi negatif dalam 10 menit |

#### 6c. LossClassifierService

Priority-ordered rule chain → satu `LossType`:

| Prioritas | `LossType` | Kondisi |
|---|---|---|
| 1 | `PROTECTION_FAILURE` | `protectionIndex < 0.30 && damageConcentrationRatio > 0.50` |
| 2 | `PICKOFF_COLLAPSE` | `enemyPickoffRate > 0.40` |
| 3 | `OUTSCALED` | `scalingMidGold > 0 && scalingLateGold < -2000` |
| 4 | `POOR_CONVERSION` | `fightControlRatio > 0.50 && objectiveConversionRate < 0.30` |
| 5 | `EXECUTION_LOSS` | `fightControlRatio < 0.40` |
| 6 | `OUTDRAFTED` | Fallback — tidak ada sinyal dominan |

#### 6d. HeroContributionService

Membangun **Hero Contribution Matrix (HCM)** — representasi per-hero yang digunakan LLM sebagai fondasi reasoning. Semua kalkulasi deterministik, LLM hanya menerima tag string, bukan angka mentah.

**Input**: `NormalizedMatch`, `PlayerImpact[]`, `TeamfightSummary[]`

**Per-hero fields yang dihitung**:

| Field | Deskripsi |
|---|---|
| `damageShare` | `heroDamage / totalTeamHeroDamage` |
| `killParticipation` | `(kills + assists) / max(1, totalTeamKills)` |
| `fightParticipation` | Diambil dari `PlayerImpact` |
| `preFightDeathRate` | `deathsOutsideFights / max(1, totalDeaths)` |
| `objectiveParticipation` | Composite weighted score (lihat tabel di bawah) |
| `inferredRole` | `array<string>` — semua role yang berlaku, minimal 1 (lihat tabel di bawah) |

**Objective participation weights**:

| Sinyal | Role yang berlaku | Weight |
|---|---|---|
| Tower/building damage | Semua role | 0.60 (non-core) / 0.50 (core) |
| Roshan kills | Core (`primary_damage`, `carry_farming`) saja, hanya jika Roshan terbunuh | 0.30 |
| Bounty rune pickups | Semua role | 0.40 (non-core) / 0.20 (core) |

> Jika tidak ada Roshan kill dalam match, weight core otomatis redistribusi ke tower (0.60) + bounty (0.40).

**Role inference rules** (semua rule dievaluasi independen — satu hero bisa memiliki beberapa role):

| Role | Kondisi |
|---|---|
| `primary_damage` | `damageShare > 0.30` |
| `carry_farming` | Highest `netWorth` dalam tim |
| `support_control` | Bottom-2 `netWorth` AND `assistRatio > 0.60` |
| `initiator` | `fightParticipation > 0.75` |
| `utility` | Fallback — hanya jika tidak ada rule lain yang terpenuhi |

> Seorang hero bisa memiliki beberapa role secara bersamaan (contoh: `[carry_farming, initiator]`). `utility` tidak pernah muncul bersamaan dengan role lain.

**Hero tag derivation rules**:

| Tag | Kondisi |
|---|---|
| `damage_dependency_core` | `damageShare > 0.40` |
| `frequent_first_death` | `preFightDeathRate > 0.45` |
| `core_under_protected` | Memiliki role `primary_damage` atau `carry_farming` AND `deathsInLosingFights / deathsInAllFights > 0.60` |
| `ineffective_initiation` | Memiliki role `initiator` AND `fightParticipation < 0.50` |
| `frequent_pickoff_victim` | `preFightDeathRate > 0.35` AND `deaths > 3` |
| `low_objective_presence` | Tidak memiliki role `support_control` AND `objectiveParticipation < 0.20` |

**Output**: `HeroContributionMatrix { entries: HeroContributionEntry[] }` — sorted by `damageShare` desc, capped at 5 member heroes.

Dua serialization method:
- `toPayload()` — compact `[hero, role, tags[]]` untuk LLM (tidak ada raw float → token-efficient)
- `toStorable()` — full metrics untuk disimpan di `analysis_data`

**Output**: `DerivedMetricsResult { metrics, lossClassification, tags, playerImpacts, fights, objectiveFlow, match, heroContributionMatrix }`

---

### Agent 6b — Context Enrichment

**Tujuan**: Sebelum LLM berjalan, bangun tiga lapisan konteks makro deterministik yang memperkaya `DiagnosisContext`. Ketiga service ini berjalan setelah Agent 6 selesai, menggunakan output dari Agents 2–4.

#### EnemyThreatService

Membangun **Enemy Threat Matrix (ETM)** — representasi per-hero musuh sebagai ancaman terklasifikasi.

**Input**: `NormalizedMatch` (via `getEnemyTeamPlayers()`)

**Klasifikasi ancaman** (priority-ordered, first match wins):

| `ThreatType` | Kondisi |
|---|---|
| `PickoffHunter` | `KDA > 3.0 && killContrib > 0.40` |
| `TeamfightCarry` | `damageShare > 0.35` |
| `InitiationThreat` | `killContrib > 0.35 && damageShare < 0.25` |
| `SplitPusher` | `towerDamage > avgTowerDamage * 2.0` |
| `SustainDamage` | Fallback |

`killContrib = (kills + assists) / (totalEnemyKills * 2)` — dinormalisasi ke 0–1.

**Klasifikasi dampak** (1–2 max per hero):

| `ImpactOnUs` | Kondisi |
|---|---|
| `PickoffPressure` | ThreatType adalah `PickoffHunter` |
| `TeamfightDominance` | ThreatType adalah `TeamfightCarry` atau `InitiationThreat` |
| `ObjectiveThreat` | `towerDamage > 2000` |
| `MapPressure` | ThreatType adalah `SplitPusher` |

**Output**: `EnemyThreatMatrix { entries[] }` — diurutkan `damageShareEnemy` desc, maks 5 entri.

Dua serialization method:
- `toPayload()` — compact `[{hero, threat_type, impact_on_us[]}]` untuk LLM (tanpa raw float)
- `primaryThreat(): string` — nama hero dengan damageShare tertinggi

#### ObjectiveFlowSummaryBuilder

Mengkondensasi `ObjectiveFlow` (output Agent 4) menjadi ringkasan kompak untuk LLM.

**Input**: `ObjectiveFlow`, `NormalizedMatch`, `TeamfightSummary[]`

**Logic per field**:
- `roshanControl`: hitung event `type==='roshan'` per team; return `'us'` | `'enemy'` | `'contested'` | `'none'`
- `mapControlShift`: `flow->momentumShifts[0] ?? null`
- `postPickoffObjectives`: event musuh non-firstblood dengan `linkedFightIndex === null`
- `missedObjectivesAfterWin`: fight dengan outcome `{memberTeam}_win` tanpa objective member dalam +120 detik setelah `endTime`
- `criticalObjective`: dari `flow->criticalObjective`

**Output**: `ObjectiveFlowSummary { roshanControl, mapControlShift, postPickoffObjectives, missedObjectivesAfterWin, criticalObjective }`

`toPayload()` mengembalikan semua field sebagai flat key-value string; `null` → `'none'`.

#### ScalingSummaryBuilder

Mengubah array raw `goldAdvantage` menjadi narasi scaling terstruktur.

**Input**: `NormalizedMatch` (goldAdvantage[], memberTeam)

**Logic**:
- Negasikan `goldAdvantage` untuk tim Dire (konversi ke perspektif member team)
- Segmentasi: early = menit 0–14, mid = 15–29, late = 30+
- Klasifikasi `ScalingPhase` per segmen: threshold ±1500 gold
- `collapseMinute`: menit pertama ketika gold silang dari positif ke negatif setelah periode positif

**Pattern detection** (priority-ordered):

| `ScalingTrendPattern` | Kondisi |
|---|---|
| `ComebackAttempt` | early Behind + mid Ahead + late Behind |
| `NeverAhead` | early Behind (tanpa comeback) |
| `EarlyThrow` | early Ahead + late Behind |
| `SteadyLead` | late tidak Behind |
| `BleedOut` | Fallback |

**Output**: `ScalingSummaryDescriptor { earlyPhase, midPhase, latePhase, collapseMinute, trendPattern }`

`toPayload()` mengembalikan `{early, mid, late, collapse_at, pattern}` sebagai string.

---

### Agent 7 — Diagnosis Agent (`DiagnosisAgent`) — LLM Stage 1

**Tujuan**: Interpretasi terstruktur dari kenapa tim kalah. Output adalah JSON ketat, **disimpan internal**, tidak dikirim ke user secara langsung.

**Config LLM**: `gpt-4o-mini`, temperature `0.2`, max_tokens `550`

**Input** (`DiagnosisContext`): 8 metrik float + loss type + semantic tags + Hero Contribution Matrix + Enemy Threat Matrix + Objective Flow Summary + Scaling Summary Descriptor + daftar hero member

> v2.1: Tiga lapisan konteks makro baru ditambahkan — ETM, OFS, dan Scaling — sehingga LLM kini harus melakukan reasoning di 4 layer terpisah bukan hanya 1.

**System prompt rules** (6 strict rules):
1. `HERO layer` — every structural weakness MUST reference a specific hero from the HCM
2. `ENEMY layer` — every enemy pressure claim MUST reference a specific hero from the ETM
3. `OBJECTIVE layer` — every consequence claim MUST reference a value from the OFS
4. `SCALING layer` — trend conclusion MUST match the provided `ScalingTrendPattern` exactly
5. No team-level statement allowed without naming the responsible hero(es)
6. No invented hero names or facts outside the provided context

**Konten user prompt** (urutan):
1. Loss classification + semantic signals
2. 8 float metrics (sebagai persentase siap baca)
3. `Our team (memberTeam): hero1, hero2, ...` + duration + game mode
4. **Hero Contribution Matrix** — compact tag lines (multiple roles digabung dengan `+`):
   ```
   - Sniper [primary_damage]: damage_dependency_core, core_under_protected
   - Juggernaut [carry_farming+initiator]: core_under_protected
   - Tusk [initiator]: ineffective_initiation
   ```
5. **Enemy Threat Matrix** (primary: `primaryThreat()`):
   ```
   - Slark [pickoff_hunter]: pickoff_pressure, teamfight_dominance
   - Zeus [teamfight_carry]: teamfight_dominance
   ```
6. **Objective Flow** — 5 flat key-value lines
7. **Scaling** — pattern | early/mid/late phases | collapse_at

**Output JSON yang diharapkan**:
```json
{
  "loss_type_confirmed": "PICKOFF_COLLAPSE",
  "root_cause": "one sentence — must name the primary hero responsible",
  "key_factors": ["factor mentioning hero and role", "..."],
  "momentum": "when and how momentum shifted, referencing hero(es) or objectives",
  "hero_failures": [
    {
      "hero": "Sniper",
      "role": "primary_damage",
      "issue": "core_under_protected",
      "team_impact": "damage output lost early each fight"
    }
  ],
  "enemy_pressure_sources": [
    {"hero": "Slark", "threat_type": "pickoff_hunter", "how_it_hurt_us": "isolated our support repeatedly"}
  ],
  "objective_consequences": {
    "post_pickoff_lost": 2,
    "missed_after_wins": 1,
    "roshan": "enemy",
    "critical_event": "Roshan at 28 min"
  },
  "scaling_assessment": {
    "pattern": "EarlyThrow",
    "summary": "held gold lead until 22 min then collapsed"
  }
}
```

> **Backward compatibility**: Parser reads `hero_failures` dengan fallback ke `hero_specific_findings` jika kunci baru tidak ada.

**Failsafe**: Jika LLM return non-JSON → `buildFallback()` mengisi field dari `context` dengan semua array kosong (tidak pernah return null kecuali exception).

**Output**: `DiagnosisResult { lossTypeConfirmed, rootCause, keyFactors[], momentum, tokensUsed, heroFindings[], enemyPressureSources[], objectiveConsequences[], scalingAssessment[] }`

---

### Agent 8 — Bullet Compressor (`BulletCompressorAgent`) — LLM Stage 2

**Tujuan**: Ubah diagnosis menjadi 4–6 bullet point dalam Bahasa Indonesia casual. Ini satu-satunya output yang dikirim ke user.

**Config LLM**: `gpt-4o-mini`, temperature `0.7`, max_tokens `280`

**Input** (`CompressionContext`): gameMode, memberTeam, durationMinutes, lossTypeConfirmed, rootCause, keyFactors[], momentum, memberHeroes[], **heroFindings[]**

> `heroFindings[]` diteruskan langsung dari `DiagnosisResult` sehingga Stage 2 memiliki akses nama hero tanpa harus re-reason dari raw data.

**System prompt rules**:
- Bahasa Indonesia casual, boleh pakai istilah gaming: "ke-pickoff", "keburu kalah"
- Format: hanya bullet point dengan tanda `-`, **6–12 kata per poin**
- Tidak ada saran build/item, tidak ada kalimat pembuka/penutup
- **Jika hero disebut di diagnosis, WAJIB tetap muncul di minimal satu bullet**
- **Tidak boleh menghilangkan nama hero yang ada di `hero_findings`**
- Setiap bullet yang menyebut hero harus menjelaskan dampak ke tim

**Failsafe**: Return `null` jika LLM gagal (pipeline log error dan stop).

**Output**: `AnalysisBullets { bulletText, tokensUsed, model }`

---

## 3. Output pipeline (stored in `analysis_data`)

```json
{
  "pipeline_version": "2.0",
  "loss_type": "PICKOFF_COLLAPSE",
  "loss_type_confidence": 0.812,
  "semantic_tags": ["high_pickoff_rate", "damage_dependency_core"],
  "derived_metrics": {
    "fight_control_ratio": 0.4,
    "objective_conversion_rate": 0.25,
    "enemy_pickoff_rate": 0.55,
    "protection_index": 0.6,
    "damage_concentration_ratio": 0.62,
    "scaling_early_gold": 1200.0,
    "scaling_mid_gold": -300.0,
    "scaling_late_gold": -4200.0
  },
  "diagnosis": {
    "loss_type_confirmed": "PICKOFF_COLLAPSE",
    "root_cause": "...",
    "key_factors": ["...", "..."],
    "momentum": "...",
    "hero_failures": [
      {"hero": "Sniper", "role": "primary_damage", "issue": "core_under_protected", "team_impact": "..."}
    ],
    "enemy_pressure_sources": [
      {"hero": "Slark", "threat_type": "pickoff_hunter", "how_it_hurt_us": "..."}
    ],
    "objective_consequences": {
      "post_pickoff_lost": 2,
      "missed_after_wins": 1,
      "roshan": "enemy",
      "critical_event": "Roshan at 28 min"
    },
    "scaling_assessment": {
      "pattern": "EarlyThrow",
      "summary": "held lead until 22 min then collapsed"
    },
    "stage1_tokens": 310
  },
  "teamfight_summaries": [...],
  "player_impacts": [...],
  "objective_flow": {
    "momentum_shifts": [...],
    "critical_objective": "..."
  },
  "hero_contribution_matrix": [
    {
      "hero": "Sniper",
      "role": ["primary_damage"],
      "damage_share": 0.52,
      "fight_participation": 0.6,
      "kill_participation": 0.71,
      "pre_fight_death_rate": 0.5,
      "objective_participation": 0.15,
      "tags": ["damage_dependency_core", "core_under_protected", "low_objective_presence"]
    }
  ],
  "metadata": {
    "analyzed_at": "2025-...",
    "pipeline_version": "2.0",
    "stage1_tokens": 280,
    "stage2_tokens": 210,
    "tokens_used": 490,
    "model": "gpt-4o-mini"
  }
}
```

---

## 4. DTO contracts

| DTO | Digunakan oleh |
|---|---|
| `GatekeeperResult` | Agent 1 output |
| `NormalizedMatch` | Agent 2 output |
| `NormalizedPlayer` | Di dalam `NormalizedMatch` |
| `TeamfightSummary` | Agent 3 output |
| `ObjectiveFlow` | Agent 4 output |
| `PlayerImpact` | Agent 5 output |
| `DerivedMetrics` | MetricEngine output |
| `SemanticTagSet` | SemanticTagger output; `has(string $tag): bool` |
| `LossClassification` | LossClassifier output |
| `DerivedMetricsResult` | Agent 6 output — agregasi semua deterministic results |
| `HeroContributionEntry` | Satu hero di dalam HCM; berisi `inferredRole: array<string>`, semua raw metrics, dan `heroTags[]` |
| `HeroContributionMatrix` | Agent 6 output (sub-service 6d); `entries[]`, `getByHero()`, `toPayload()`, `toStorable()` |
| `EnemyThreatEntry` | Satu hero musuh di dalam ETM; `heroName`, `threatType`, `impactOnUs[]`, `damageShareEnemy`, `killContribution` |
| `EnemyThreatMatrix` | Agent 6b output; `entries[]`, `primaryThreat(): string`, `toPayload()` |
| `ObjectiveFlowSummary` | Agent 6b output; ringkasan kompak untuk LLM (berbeda dari `ObjectiveFlow` yang untuk DB) |
| `ScalingSummaryDescriptor` | Agent 6b output; `earlyPhase`, `midPhase`, `latePhase`, `collapseMinute`, `trendPattern`, `toPayload()` |
| `DiagnosisContext` | Agent 7 input — berisi HCM + ETM + OFS + ScalingSummary + 8 metrik |
| `DiagnosisResult` | Agent 7 output — berisi `heroFindings[]`, `enemyPressureSources[]`, `objectiveConsequences[]`, `scalingAssessment[]` |
| `CompressionContext` | Agent 8 input — berisi `heroFindings[]` dari DiagnosisResult |
| `AnalysisBullets` | Agent 8 output (teks yang dikirim ke user) |

### Enums baru (v2.1)

| Enum | Values |
|---|---|
| `ThreatType` | `PickoffHunter`, `TeamfightCarry`, `InitiationThreat`, `SplitPusher`, `SustainDamage` |
| `ImpactOnUs` | `PickoffPressure`, `TeamfightDominance`, `ObjectiveThreat`, `MapPressure` |
| `ScalingPhase` | `Ahead`, `Even`, `Behind` (threshold ±1500 gold) |
| `ScalingTrendPattern` | `SteadyLead`, `EarlyThrow`, `ComebackAttempt`, `BleedOut`, `NeverAhead` |

---

## 5. Kenapa arsitektur ini aman & scalable

- **LLM tidak pernah lihat raw JSON** — hanya menerima structured metrics dan tags yang sudah dihitung
- **HCM dikirim sebagai tag string, bukan float** — `toPayload()` strip semua angka mentah sebelum masuk ke LLM prompt, menjaga token budget dan mencegah LLM arithmetic error
- **Four-layer hero grounding** — Stage 1 wajib produce `hero_failures[]` (HCM layer), `enemy_pressure_sources[]` (ETM layer), `objective_consequences` (OFS layer), dan `scaling_assessment` (Scaling layer); setiap klaim LLM harus memiliki anchor ke data deterministik
- **ETM/OFS/Scaling sepenuhnya deterministik** — ketiga service baru (Agent 6b) adalah pure PHP tanpa LLM; hasil bisa di-unit-test dan di-cache secara independen
- **Agent 1–6b sepenuhnya deterministic** — bisa di-cache, di-unit-test, dan di-debug tanpa LLM
- **Two-stage LLM** — Stage 1 (diagnosis) dipisah dari Stage 2 (narasi) sehingga bisa A/B test prompt masing-masing secara independen
- **Fallback di setiap layer** — Gatekeeper (reject early), DiagnosisAgent (fallback JSON dengan semua array kosong), BulletCompressor (nullable, log error)
- **Backward-compatible parser** — `DiagnosisAgent` membaca `hero_failures` dengan fallback ke `hero_specific_findings` sehingga data lama masih terbaca
- **`pipeline_version: '2.0'`** — memungkinkan data lama (v1) dan baru hidup berdampingan di kolom `analysis_data`
- **Swap LLM kapan aja** — ganti `gpt-4o-mini` ke model lain hanya butuh ubah satu konstanta di masing-masing agent
