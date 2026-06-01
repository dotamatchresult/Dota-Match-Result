Now I have a complete picture. Here is the full architectural report:

---

## Architecture Report: DotA 2 Match Analysis System

---

### 1. Pipeline Architecture

The analysis is a **linear 6-agent pipeline**, triggered by `AnalyzeMatchWithAI` job after `parse_status = 'parsed'`.

```
AnalyzeMatchWithAI (Job)
    ↓  match_data (parsed OpenDota JSON) + memberSteamIds[]
MatchAnalysisPipeline::analyze()
    │
    ├─ Agent 1: MatchGatekeeperAgent::validate()
    │       → Returns GatekeeperResult (pass/reject)
    │       → Abort if rejected
    │
    ├─ Agent 2: NormalizerAgent::normalize()
    │       → Returns NormalizedMatch DTO
    │
    ├─ Agent 3: TeamfightAggregatorAgent::aggregate()
    │       → Returns TeamfightSummary[]
    │
    ├─ Agent 4: ObjectiveFlowAgent::analyze()
    │       → Returns ObjectiveFlow DTO
    │
    ├─ Agent 5: PlayerImpactAgent::calculate()
    │       → Returns PlayerImpact[]
    │
    ├─ Builds AnalysisContext DTO
    │
    └─ Agent 6: LLMAnalysisAgent::analyzeDefeat()
            → Returns LLMAnalysis DTO
            → Stores to DB + sends to Telegram/WhatsApp
```

**Job entry conditions** (in `AnalyzeMatchWithAI::handle()`):
- `parse_status === 'parsed'` (required)
- Telegram members ≥ 3 OR WhatsApp members ≥ 2 (hardcoded thresholds)

---

### 2. Agent Details & Metric Computations

#### Agent 1: `MatchGatekeeperAgent`
Reject codes and conditions:

| Code | Condition |
|---|---|
| `duration_too_short` | `duration < 600` (10 min) |
| `missing_teamfights` | `teamfights` is empty/missing |
| `outcome_unknown` | `radiant_win` not set |
| `team_unknown` | No member found in players array |
| `victory_not_defeat` | Members won — **analysis is defeat-only** |

#### Agent 2: `NormalizerAgent`
Reads from `match_data`:
- `players[].account_id` → converted via `bcadd($id, '76561197960265728')` to steam64
- `players[].player_slot` (<128 = radiant, ≥128 = dire)
- `players[].hero_id` → resolved to `Hero::localized_name` from DB
- Fields extracted per player: `kills, deaths, assists, hero_damage, tower_damage, gold_per_min, xp_per_min, net_worth`
- Also passes through raw: `teamfights[]`, `objectives[]`, `radiant_gold_adv[]`, `radiant_xp_adv[]`
- Game modes mapped: 23=Turbo, 22=All Draft, 2=Captains Mode, etc.
- **Skips players with no `account_id`** (anonymous players) — this affects the player index mapping used by TeamfightAggregator

#### Agent 3: `TeamfightAggregatorAgent`
For each fight in `teamfights[]`:
- `teamfights[i].players[]` — index 0–4 = radiant, 5–9 = dire
- `deaths`, `gold_delta`, `damage` per player-fight slot

Computed:
- **`outcome`**: `dire_win` if `direKills > radiantKills+1 && direGold > 500`; `radiant_win` symmetric; else `even`
- **`swing`**: `big` if `|direGold - radiantGold| > 2000`; `medium` if `> 1000`; else `small`
- **`keyHeroes`**: top 3 damage dealers with `damage > 1000` mapped to hero names via position index
- **`isMajorSwing()`**: `swing === 'big'`

#### Agent 4: `ObjectiveFlowAgent`
Reads `objectives[]` from the parsed match. Each objective type identified by:

| `type`/`key` field | Mapped to |
|---|---|
| contains `"tower"` or `type=building_kill` | `tower` |
| contains `"barracks"` / `"rax"` | `barracks` |
| `CHAT_MESSAGE_ROSHAN_KILL` | `roshan` |
| `CHAT_MESSAGE_FIRSTBLOOD` | `firstblood` |

Team from objective:
- `key` contains `"goodguys"` → radiant, `"badguys"` → dire
- `player_slot` fallback: <128 = radiant

Computed:
- **Momentum shifts**: 3+ consecutive objectives by the same team (ignoring firstblood) → string like `"Dire gained momentum around 21:58"`
- **Critical objective**: First enemy Roshan, or first barracks lost by members' team
- **`linkedFightIndex`**: nearest fight within 90 seconds

#### Agent 5: `PlayerImpactAgent`
Per member-team player:

$$\text{impactScore} = \text{kills} \times 2 + \text{assists} + \frac{\text{heroDamage}}{500} + \frac{\text{towerDamage}}{1000} - \text{deaths} \times 1.5 - \text{deathsInLosingFights} \times 0.5$$

- `deathsInLosingFights` = `round(deaths × losingFightCount / totalFights)` — **proportional estimate only**
- `fightParticipation` = `min(1.0, deathsInLosingFights / losingFightCount)`
- Labels: `high` ≥ 20, `medium` ≥ 10, `low` otherwise
- Sorted descending by `impactScore`

#### Agent 6: `LLMAnalysisAgent`
- Model: `gpt-4o-mini`
- Temperature: `0.7`
- Max tokens: `500`
- **Input is structured text** (not raw JSON), built from `AnalysisContext`

---

### 3. LLM Prompt Templates

#### System Prompt (Indonesian, casual gaming tone)
```
Kamu adalah analis DotA 2 yang berpengalaman dan objektif.
Fokus analisismu adalah TIM YANG KALAH.
Gunakan HANYA data terstruktur yang diberikan.
...
Output WAJIB: 4–6 bullet point dengan "-"
Setiap poin 6–12 kata
Gaya: Bahasa Indonesia casual, santai, empati
Dilarang: kalimat pembuka/penutup, saran build, blame personal
```

#### User Prompt Structure (built from `AnalysisContext`)
```
Match ID: {matchId}
Game Mode: {gameMode}
Duration: {N} minutes
Our Team: {radiant|dire}
Result: Lost

Our Heroes: {Hero1, Hero2, dan Hero3}

=== TEAM COMPOSITIONS ===
Radiant: Hero, Hero, ...
Dire: Hero, Hero, ...

=== PLAYER PERFORMANCES ===
{Hero} - KDA: X.X, Impact Score: X.X (high|medium|low)
...

=== KEY TEAMFIGHTS ===      ← only if isMajorSwing() fights exist
Fight at MM:SS: {outcome} ({swing} swing) - Key heroes: ...
...

=== MOMENTUM SHIFTS ===     ← only if any detected
- {description}
...

CRITICAL: {criticalObjective}  ← only if found

Analyze WHY we lost. Focus ONLY on our heroes: {heroList}
```

**Note**: `AnalysisContext.keyFights` is filtered to `isMajorSwing()` only (swing === `'big'`).

**Contrast with legacy `OpenAiService`**: The old service dumps the **entire raw match JSON** into the user message alongside a hero mapping table. It has a `@deprecated` `buildAnalysisPrompt()` method that was the first-gen approach. The new pipeline never calls `OpenAiService` — it uses `LLMAnalysisAgent` directly.

---

### 4. DTO Shapes

#### `GatekeeperResult`
```php
readonly class GatekeeperResult {
    bool $passed
    ?string $reason
    ?string $rejectCode
}
```

#### `NormalizedMatch`
```php
readonly class NormalizedMatch {
    string $matchId
    string $memberTeam       // 'radiant' | 'dire'
    bool $memberWon
    int $duration            // seconds
    string $gameMode
    NormalizedPlayer[] $radiantPlayers
    NormalizedPlayer[] $direPlayers
    array $teamfights        // raw from parsed JSON
    array $objectives        // raw from parsed JSON
    array $goldAdvantage     // radiant_gold_adv[] time-series
    array $xpAdvantage       // radiant_xp_adv[] time-series
}
```

#### `NormalizedPlayer`
```php
readonly class NormalizedPlayer {
    int $heroId, string $heroName, string $team
    int $kills, $deaths, $assists
    int $heroDamage, $towerDamage
    int $goldPerMin, $xpPerMin, $netWorth
    // Missing: last_hits, hero_healing, items, level, benchmarks
}
```

#### `TeamfightSummary`
```php
readonly class TeamfightSummary {
    int $index, $startTime, $endTime, $duration
    string $outcome          // 'dire_win' | 'radiant_win' | 'even'
    string $swing            // 'big' | 'medium' | 'small'
    int $direKills, $radiantKills, $direGoldDelta, $radiantGoldDelta
    string[] $keyHeroes
}
```

#### `ObjectiveEvent`
```php
readonly class ObjectiveEvent {
    int $time
    string $type             // 'tower' | 'barracks' | 'roshan' | 'firstblood'
    string $team             // 'radiant' | 'dire'
    ?int $linkedFightIndex
}
```

#### `ObjectiveFlow`
```php
readonly class ObjectiveFlow {
    ObjectiveEvent[] $events
    string[] $momentumShifts
    ?string $criticalObjective
}
```

#### `PlayerImpact`
```php
readonly class PlayerImpact {
    string $heroName
    int $kills, $deaths, $assists, $heroDamage, $towerDamage
    float $impactScore, $fightParticipation
    string $impactLabel      // 'high' | 'medium' | 'low'
    int $deathsInLosingFights
}
```

#### `LLMAnalysis`
```php
readonly class LLMAnalysis {
    string $text
    int $tokensUsed
    string $model
    float $temperature
}
```

#### `AnalysisContext`
```php
readonly class AnalysisContext {
    string $matchId, $outcome, $memberTeam, $gameMode
    int $durationMinutes
    TeamfightSummary[] $keyFights     // pre-filtered: isMajorSwing() only
    PlayerImpact[] $playerImpacts
    string[] $momentumShifts
    string[] $radiantHeroes, $direHeroes
    ?string $criticalObjective
}
```

---

### 5. Database Schema: `dota_matches`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `match_id` | string UNIQUE | DotA 2 match identifier |
| `match_timestamp` | timestamp nullable | |
| `match_data` | json | Full OpenDota match JSON (cast to array) |
| `members` | json | Array of `Member.id` integers (cast to array) |
| `notified_at` | timestamp nullable | When initial notification was sent |
| `resend_count` | integer default 0 | |
| `last_resent_at` | timestamp nullable | |
| `parse_status` | enum nullable | `pending\|parsing\|parsed\|failed` |
| `parse_job_id` | string nullable | OpenDota job ID |
| `parse_requested_at` | timestamp nullable | |
| `parse_completed_at` | timestamp nullable | |
| `parse_retry_count` | integer default 0 | |
| `ai_analysis` | text nullable | Raw LLM output (bullet points) |
| `ai_analyzed_at` | timestamp nullable | |
| `analysis_data` | json nullable | Computed pipeline metrics (cast to array) |

#### `analysis_data` JSON shape (stored output from pipeline):
```json
{
  "teamfight_summaries": [
    { "time": "10:00", "outcome": "dire_win", "swing": "big", "key_heroes": ["Invoker"] }
  ],
  "player_impacts": [
    { "hero": "Pudge", "impact_score": 12.5, "impact_label": "medium", "kda": 2.4 }
  ],
  "objective_flow": {
    "momentum_shifts": ["Dire gained momentum around 21:58"],
    "critical_objective": "Enemy Roshan at 22:10"
  }
}
```

---

### 6. `match_data` JSON Structure (inferred from code + sample)

The stored `match_data` is the full **OpenDota API parsed match response**. Key fields used/available:

```jsonc
{
  "match_id": 8651445707,
  "duration": 1741,             // seconds
  "start_time": 1768571766,
  "game_mode": 23,              // 23=Turbo
  "radiant_win": false,
  "radiant_score": 43,
  "dire_score": 38,
  "players": [                  // 10 players, index 0-4 radiant, 5-9 (slot 128-132) dire
    {
      "account_id": 125753349,  // 32-bit Steam ID (null for anonymous)
      "player_slot": 0,         // 0-4 radiant, 128-132 dire
      "hero_id": 135,
      "kills": 11, "deaths": 8, "assists": 18,
      "last_hits": 120, "denies": 0,
      "gold_per_min": 1085, "xp_per_min": 2153,
      "net_worth": 28331, "level": 29,
      "hero_damage": 31886, "tower_damage": 1755, "hero_healing": 4830,
      "gold_spent": 37605,
      "item_0"…"item_5": ...,   // item IDs
      "aghanims_scepter": 0, "aghanims_shard": 1,
      "ability_upgrades_arr": [...],
      "benchmarks": { "gold_per_min": {"raw":1085,"pct":0.67}, ... },
      "personaname": "...",
      "rank_tier": 41,
      "radiant_win": false,     // duplicated at player level
      "isRadiant": true
    }
  ],
  // Only present after OpenDota parse:
  "teamfights": [
    {
      "start": 100, "end": 150,
      "players": [              // 10 entries, positional (radiant 0–4, dire 5–9)
        { "deaths": 1, "gold_delta": -500, "damage": 2000, ... }
      ]
    }
  ],
  "objectives": [
    { "time": 620, "type": "building_kill", "key": "npc_dota_goodguys_tower1_mid" },
    { "time": 1120, "type": "CHAT_MESSAGE_ROSHAN_KILL", "player_slot": 128 }
  ],
  "radiant_gold_adv": [0, 100, -200, ...],  // sampled every minute
  "radiant_xp_adv": [0, 50, -100, ...],
  "picks_bans": [...],
  "od_data": { "has_parsed": true },
  "patch": 59, "region": 5, "cluster": 151
}
```

---

### 7. Token Usage Patterns

| Aspect | Value |
|---|---|
| Model | `gpt-4o-mini` |
| Temperature | `0.7` |
| Max output tokens | `500` |
| Input tokens | Uncontrolled — depends on fight count, objective count, player count |
| Estimated input | ~400–800 tokens (structured text, not raw JSON) |
| Total per analysis | ~600–1300 tokens typically |
| Legacy `OpenAiService` | Sends **entire raw match JSON** — could easily be 5000–15000+ tokens |

---

### 8. Current Test Coverage

| Test file | Scope |
|---|---|
| [tests/Unit/Services/Analysis/Agents/MatchGatekeeperAgentTest.php](tests/Unit/Services/Analysis/Agents/MatchGatekeeperAgentTest.php) | Unit — all 6 reject codes + pass case |
| [tests/Unit/Services/Analysis/Agents/NormalizerAgentTest.php](tests/Unit/Services/Analysis/Agents/NormalizerAgentTest.php) | Unit — DTO shape, hero resolution, unknown heroes |
| [tests/Unit/Services/Analysis/Agents/TeamfightAggregatorAgentTest.php](tests/Unit/Services/Analysis/Agents/TeamfightAggregatorAgentTest.php) | Unit — summaries, swing classification, key heroes |
| [tests/Feature/Analysis/Agents/MatchGatekeeperAgentTest.php](tests/Feature/Analysis/Agents/MatchGatekeeperAgentTest.php) | Feature (DB) — duplicate of unit tests |
| [tests/Feature/MatchAnalysisPipelineTest.php](tests/Feature/MatchAnalysisPipelineTest.php) | Integration — full pipeline with OpenAI mock |
| [tests/Feature/OpenAiServiceTest.php](tests/Feature/OpenAiServiceTest.php) | Tests **legacy** `OpenAiService`, not pipeline agents |
| [tests/Unit/OpenAiServiceTest.php](tests/Unit/OpenAiServiceTest.php) | Tests **legacy** `OpenAiService` |
| [tests/Unit/MvpScoringTest.php](tests/Unit/MvpScoringTest.php) | Tests `ProcessMatchNotification` scoring (notification job, unrelated to analysis pipeline) |

**No tests for**: `PlayerImpactAgent`, `ObjectiveFlowAgent`, `LLMAnalysisAgent`, `AnalyzeMatchWithAI` job.

---

### 9. Gaps, Inconsistencies, and Technical Debt

#### Critical Issues
1. **`PlayerImpactAgent.countDeathsInLosingFights` is fake math** — the code comment explicitly says `"This is a simplified calculation"`. Deaths in losing fights are *estimated proportionally* from total deaths, not tracked per fight. The parsed JSON does have per-fight per-player `deaths` data but it's not used here.

2. **`TeamfightAggregatorAgent.getHeroNameByIndex` is fragile** — it uses `array_merge(radiantPlayers, direPlayers)[$index]`. `NormalizerAgent` skips players with no `account_id`, so if any anonymous player exists, all indices after that slot are off by one. In real Turbo matches, all 10 players are generally tracked, but this is brittle.

3. **Dual analysis paths co-exist**: `OpenAiService` (legacy, raw JSON dump) and `LLMAnalysisAgent` (new pipeline agent, structured context). The `AnalyzeMatchWithAI` job uses only the new pipeline. The old `OpenAiService::buildAnalysisPrompt()` is `@deprecated` but the class itself is still used by `ProcessMatchNotification` (the initial notification job), so both are alive.

#### Design Issues
4. **`NormalizedPlayer` is missing key fields** present in the raw JSON that would improve analysis quality: `last_hits`, `hero_healing`, `level`, `benchmarks` (percentile rankings), `net_worth`.

5. **`radiant_gold_adv`/`radiant_xp_adv` time-series are captured in `NormalizedMatch` but never consumed** by any agent. They would be the best signal for momentum analysis, but `ObjectiveFlowAgent` uses objective events instead.

6. **`AnalysisContext.outcome` field is always `'lost'`** — the gatekeeper hard-rejects all victories before `AnalysisContext` is built. The field is set correctly but `LLMAnalysisAgent` hardcodes `"Result: Lost"` in the user prompt regardless, making the field redundant and misleading for potential future expansion to win analysis.

7. **`keyFights` in `AnalysisContext` filters to `isMajorSwing()` only** — fights with `swing === 'big'` (gold delta > 2000). Medium and small swings are entirely invisible to the LLM. In a close game with many medium fights, the context may contain zero fight data.

8. **Hardcoded member thresholds** (`memberTelegramMin = 3`, `memberWhatsAppMin = 2`) live as magic numbers inside `AnalyzeMatchWithAI::handle()`. No config or setting for this.

9. **`analysis_data` and `ai_analysis` are independent stores** — `ai_analysis` is the raw text, `analysis_data` contains computed metrics. `hasAnalysis()` requires both to be non-empty. But if the LLM fails after metrics are computed, neither is saved (the job returns null before any DB write).

10. **`MatchGatekeeperAgentTest` exists in both** `tests/Feature/Analysis/Agents/` and `tests/Unit/Services/Analysis/Agents/` with slightly different test data — the Feature version has an inconsistency (tests a Radiant member losing with `radiant_win=true`, which means radiant won and member on Radiant — so member won, but the test expects `passed=true`; this test data may be wrong).

11. **No `PlayerImpactAgent`, `ObjectiveFlowAgent`, or `LLMAnalysisAgent` unit tests** exist at all.

12. **`pipeline_version` is hardcoded `'1.0'`** in the pipeline output metadata — no versioning strategy.