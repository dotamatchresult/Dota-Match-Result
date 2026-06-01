Nice. Kalau kamu sudah **fix pakai agentic flow**, sekarang kita turunin itu jadi **alur yang bisa benar-benar kamu implement di Laravel 12**, bukan cuma konsep LLM doang.

Di bawah ini aku susun **end-to-end flow**: dari JSON masuk → agent jalan → output siap dikirim ke WhatsApp / Telegram.

---

## 0. Prinsip besar (biar nggak nyasar)

**LLM = analis & narator**
**Laravel = akuntan & operator**

Artinya:

* Laravel **menghitung**
* LLM **menyimpulkan**
* Jangan kebalik

---

## 1. High-level pipeline (1 match)

```
Raw Match JSON
   ↓
Normalizer Agent (Laravel)
   ↓
Derived Metrics Agent (Laravel)
   ↓
Analysis Agent (LLM)
   ↓
Narrative Agent (LLM)
   ↓
Formatter (Laravel)
   ↓
Send to WhatsApp / Telegram
```

Kalau digambar di kepala:

> data → struktur → angka → makna → bahasa → kirim

---

## 2. Agent-by-agent breakdown (IMPLEMENTABLE)

---

## Agent 0 — Match Gatekeeper (Laravel)

**Tujuan**

* Pastikan match ini layak dianalisis
* Cegah spam / match rusak

**Logic**

```php
if ($match['duration'] < 600) skip; // remake
if (!isset($match['teamfights'])) mark_unparsed;
if ($match['radiant_win'] === null) skip;
```

Output:

```php
MatchContext {
  match_id
  dire_win: bool
  parsed_level: full | partial
}
```

---

## Agent 1 — Normalizer (Laravel, WAJIB)

**Tujuan**

* Ubah OpenDota chaos → struktur stabil
* LLM **tidak boleh** mikir soal slot, team, ID

### Input

* `players[]`
* `teamfights[]`
* `objectives[]`

### Output (contoh)

```json
{
  "teams": {
    "dire": [
      {
        "slot": 128,
        "hero": "Luna",
        "player_index": 3
      }
    ],
    "radiant": [...]
  },
  "teamfights": [...],
  "objectives": [...]
}
```

Catatan penting:

* Hero ID → Hero name **DI SINI**
* Team tagging **DI SINI**
* Jangan lempar `player_slot` mentah ke LLM

---

## Agent 2 — Fight Aggregator (Laravel)

**Tujuan**

* Menurunkan *makna* dari teamfights mentah

### Untuk setiap teamfight:

Hitung:

```text
dire_kills
radiant_kills
dire_deaths
radiant_deaths
dire_gold_delta
radiant_gold_delta
dire_xp_delta
```

### Tentukan outcome:

```php
if (dire_gold_delta > 1000 && dire_deaths < radiant_deaths)
  outcome = "dire_win";
elseif (...)
  outcome = "radiant_win";
else
  outcome = "even";
```

### Output

```json
{
  "fight_index": 5,
  "time": 1420,
  "outcome": "dire_win",
  "swing": "big",
  "key_heroes": ["Luna", "Earthshaker"]
}
```

Ini **emas** buat analisis.

---

## Agent 3 — Macro / Objective Agent (Laravel)

**Tujuan**

* Jawab pertanyaan: *fight itu jadi apa?*

### Dari `objectives[]`:

Kelompokkan:

* Towers
* Rax
* Roshan
* Aegis

### Hubungkan ke teamfight terdekat

```php
objective_time - fight_time < 90s
```

### Output

```json
{
  "after_fight": 12,
  "dire_objectives": ["T2 mid", "Roshan"],
  "momentum_shift": true
}
```

---

## Agent 4 — Player Impact Calculator (Laravel)

**Tujuan**

* Skor objektif, bukan opini LLM

### Rumus sederhana (boleh kamu tweak)

```text
impact_score =
  kills * 2
  + assists
  + (total_gold_delta / 500)
  - deaths * 1.5
```

Tambahan sinyal:

* Fight participation %
* Deaths in losing fights (penalty)

### Output

```json
{
  "hero": "Luna",
  "impact": 18.4,
  "label": "carry",
  "note": "high damage, low deaths"
}
```

---

## Agent 5 — Analysis Agent (LLM, SINGLE CALL)

⚠️ **JANGAN** kasih raw JSON OpenDota
Kasih **hasil agent 1–4**

### System Prompt

```text
You are a Dota 2 match analyst.
Focus on Dire team performance.
Use only provided structured data.
Do not invent events.
```

### User Payload (ringkas & tajam)

```json
{
  "match_result": "dire_win",
  "fight_summary": [...],
  "objective_flow": [...],
  "player_impacts": [...]
}
```

### Task

```text
1. Summarize match flow (early → late).
2. Explain why Dire won or lost fights.
3. Point out momentum shifts.
4. Name top 3 Dire contributors.
```

---

## Agent 6 — Flavor / Trash Talk Agent (OPTIONAL)

**PISAHKAN DARI ANALYSIS**

Kenapa?

* Bisa kamu matikan
* Bisa kamu random
* Bisa kamu ganti bahasa

Prompt contoh:

```text
Buat 2 komentar santai Bahasa Indonesia
untuk grup teman dekat.
Gunakan nama hero.
Sedikit sarkas boleh.
```

---

## Agent 7 — Message Assembler (Laravel)

**Tujuan**

* Kontrol format
* Konsisten di WhatsApp / Telegram

```text
[RESULT]
Dire Victory (42:31)

[MATCH FLOW]
Dire sempat tertahan early, tapi fight menit 18 jadi titik balik.

[KEY FIGHTS]
- Fight 18:40 → wipe + Roshan
- Fight 27:10 → barracks mid

[MVP]
1. Luna – damage stabil, positioning rapi
2. Earthshaker – 2 clutch echo
```

---

## 3. Kenapa flow ini aman & scalable

* Bisa fallback ke **unparsed JSON**
* Bisa cache agent 1–4 (hemat token)
* Bisa A/B test prompt tanpa ubah logic
* Bisa swap LLM kapan aja

---

## 4. Kalau kamu mau lanjut

Aku bisa bantu:

1. **Exact PHP class design** per agent
2. **Fallback flow untuk unparsed match**
3. **Randomized sarcastic templates**
4. **Score tuning biar nggak bias core**
5. **Multi-language output**

Tinggal bilang mau lanjut ke yang mana.
