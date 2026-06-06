# Implement Step 8: Evaluator Expansion & Metric Challenge Framework

Read `PLAN.md` first for project architecture, coding standards, and existing challenge system implementation.

Important:

* Implement **ONLY Step 8**.
* Do not modify behavior from Step 1–7 unless explicitly required by this step.
* Do not add analytics, leaderboards, AI challenge generation, admin panels, APIs, dashboards, or future roadmap items.
* Preserve backwards compatibility with existing challenge codes and data.
* Follow existing Laravel 12 project conventions.
* Follow existing testing style (Pest).
* Prefer small focused classes over large service classes.

---

# Step 8 Goal

Expand the challenge system from custom evaluator-per-challenge into a reusable metric-based framework.

Current implemented evaluators:

* HeroWinEvaluator
* ItemWinEvaluator

Keep them working exactly as-is.

Add generic evaluator patterns so future challenge types can be added mostly through catalog configuration.

---

# Existing Constraints

Use only metrics already available in the stored unparsed OpenDota payload.

Confirmed available metrics:

* kills
* deaths
* assists
* last_hits
* denies
* hero_healing
* hero_damage
* tower_damage
* net_worth
* gold_per_min
* xp_per_min

Do not introduce parsed-match-only metrics.

---

# Deliverable 1: Metric Registry

Create:

```php
app/Support/DailyChallenge/MetricRegistry.php
```

Example responsibility:

```php
MetricRegistry::all();
MetricRegistry::exists('kills');
```

Registry should contain:

* kills
* deaths
* assists
* last_hits
* denies
* hero_healing
* hero_damage
* tower_damage
* net_worth
* gold_per_min
* xp_per_min

This becomes the authoritative metric list.

---

# Deliverable 2: Progress Data Normalization

Standardize challenge progress_data structure.

All evaluators should understand the following structure:

```php
[
    'contributors' => [],
    'matches' => [],
    'best_attempt' => null,
    'best_member_id' => null,
    'best_match_id' => null,
    'metadata' => [],
]
```

Not every evaluator must populate every field.

Requirements:

* Backwards compatible with existing stored data
* Never assume keys exist
* Safely merge existing progress_data

---

# Deliverable 3: Generic Evaluator Pattern A

Create:

```php
AccumulativeTeamMetricEvaluator
```

Location:

```php
app/Services/ChallengeEvaluators/
```

Purpose:

Sum a metric across all registered members in the destination for a match.

Configuration:

```php
[
    'metric' => 'kills'
]
```

Example:

Members:

* Alice = 12 kills
* Bob = 8 kills
* Charlie = 10 kills

Progress delta:

```php
30
```

Behavior:

* Supports contributor breakdown
* Updates matches list
* Accumulates across many matches
* Completion determined by current_progress >= requirement

---

# Deliverable 4: Generic Evaluator Pattern B

Create:

```php
SingleMatchTeamMetricEvaluator
```

Purpose:

Evaluate a team total achieved within a single match.

Example:

```text
Get total 150 last hits in a match
```

Behavior:

* Calculate team total for that match
* Track best attempt
* Track best match
* Store contributors
* Completion occurs if team total >= requirement in one match

Progress display should use:

```php
best_attempt
```

not accumulated totals.

---

# Deliverable 5: Generic Evaluator Pattern C

Create:

```php
SingleMatchIndividualMetricEvaluator
```

Purpose:

Evaluate the best single player performance within a match.

Example:

```text
A player must get 150 last hits in a match
```

Behavior:

* Find highest player value among destination members
* Track:

  * best_attempt
  * best_member_id
  * best_match_id
* Completion occurs when best player value >= requirement

Example:

```text
Alice = 120
Bob = 160
Charlie = 80
```

Progress:

```php
best_attempt = 160
best_member_id = Bob
```

---

# Deliverable 6: Special Evaluators

Implement:

```php
FastWinEvaluator
```

Rules:

* Match must be won
* Duration <= requirement

Example:

```text
Win within 23 minutes
```

Completion:

```php
duration <= current_requirement
```

---

Implement:

```php
ZeroDeathWinEvaluator
```

Rules:

* Member belongs to destination
* Win match
* deaths == 0

Completion:

same behavior as existing hero_win/item_win style evaluator.

---

# Deliverable 7: Evaluator Registry Integration

Update existing evaluator registry.

New evaluators must be resolvable through:

```php
config('dota.daily_challenge.evaluators')
```

No switch statements.

Keep current registry architecture.

---

# Deliverable 8: Challenge Catalog Expansion

Refactor catalog definitions to support metric-based challenges.

Support configuration like:

```php
[
    'metric' => 'kills'
]
```

Examples:

```php
metric_team_total
metric_team_single_match
metric_player_single_match
```

Do not remove existing challenge codes.

Maintain compatibility with:

* hero_win
* item_win
* total_kills
* total_denies
* total_heal
* last_hits
* fast_win
* zero_death_win

Use configuration-driven behavior where possible.

---

# Deliverable 9: Catalog Validation

Extend existing ChallengeCatalogValidator.

Validate:

```php
configuration.metric
```

must exist in MetricRegistry when present.

Reject invalid metrics.

Add tests.

---

# Deliverable 10: Tests

Create comprehensive Pest coverage.

Required coverage:

## Metric Registry

* registry returns metrics
* exists() works
* invalid metric returns false

## AccumulativeTeamMetricEvaluator

* sums contributors
* accumulates correctly
* completion detection
* contributor tracking

## SingleMatchTeamMetricEvaluator

* team total calculation
* best_attempt update
* completion detection
* contributor storage

## SingleMatchIndividualMetricEvaluator

* highest player selected
* best member stored
* best match stored
* completion detection

## FastWinEvaluator

* winning fast match passes
* losing fast match fails
* winning slow match fails

## ZeroDeathWinEvaluator

* win + 0 death passes
* win + death fails
* loss + 0 death fails

## Catalog Validation

* valid metric accepted
* invalid metric rejected

Maintain all existing Step 1–7 tests.

No regressions allowed.

---

# Expected Output

Provide:

1. Architecture summary
2. Files created
3. Files modified
4. Migration summary (if any)
5. Test summary
6. Important implementation decisions
7. Any discovered edge cases

Do not implement anything beyond Step 8.
