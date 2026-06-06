# Challenge Authoring Guide

This document defines the rules for creating and validating challenge definitions
in the Challenge Catalog (`app/Support/DailyChallenge/ChallengeCatalog.php`).

---

## Allowed Metrics

The following metrics are allowed for challenge evaluation:

| Metric | Description |
|---|---|
| `kills` | Total kills across one or more matches |
| `assists` | Total assists across one or more matches |
| `deaths` | Total deaths across one or more matches |
| `wins` | Number of matches won |
| `hero_id` | Win with a specific hero |
| `item_id` | Win with a specific item purchased |
| `last_hits` | Creep kills in a single match |
| `denies` | Creep denies in a single match |
| `hero_healing` | HP healed across one or more matches |
| `duration` | Match duration in seconds (e.g., for time-based snapshots) |

---

## Forbidden Metrics

The following metrics are explicitly disallowed for challenges:

| Metric | Reason |
|---|---|
| `roshan` | Roshan kills incentivize map control behavior that warps gameplay |
| `wards` | Observer/sentry ward placement promotes unfun play patterns |
| `sentries` | Sentry ward spam is not meaningful gameplay |
| `courier kills` | Too random and match-dependent |
| `damage types` | Physical/magical/pure damage breakdown is overly complex |
| `skill usage` | Cast counts are hero-specific and hard to balance |
| `item activations` | Active item usage is too narrow |
| `stun duration` | Stun time varies wildly by hero and is hard to track |
| `silence duration` | Silence tracking is too niche |

---

## Design Rules

All challenges must follow these design principles:

### Turbo Compatible
- Challenges must be achievable in Turbo (加速) mode games
- Turbo games are shorter (~20 min) and have accelerated XP/gold
- Avoid challenges that require long game durations (>30 min)

### Achievable in 1–3 Matches
- A reasonable team should complete the challenge in 1–3 games
- Avoid grind-heavy requirements that demand 5+ games
- Consider the "average" team skill level, not the top players

### No Griefing Incentives
- Challenges must not encourage behavior that ruins games for others
- Example: "Die 20 times" is forbidden even if technically measurable

### No Intentional Feeding Incentives
- Challenges must not reward dying or losing
- Focus on positive gameplay outcomes (wins, kills, denies, healing)

### No AFK Incentives
- Challenges must not reward passive or idle behavior
- Example: "Stay in base for 10 minutes" is forbidden

---

## Challenge Categories

### Accumulative
Progress accumulates across all matches played during the day. The requirement
may increase on failure (via `increment_value`).

### Snapshot
Must be achieved in a single match. Requirements do not increase on failure
(`increment_value` is always 0, and `base_requirement` equals `max_requirement`).

---

## Catalog Entry Format

Each entry in `ChallengeCatalog::definitions()` must include:

```php
[
    'code'             => '...',       // Machine-readable key, unique
    'name'             => '...',       // Display name
    'description'      => '...',       // Text with {requirement} placeholder
    'category'         => '...',       // 'accumulative' or 'snapshot'
    'weight'           => N,           // Selection probability (>= 1)
    'base_requirement' => N,           // Starting threshold
    'increment_value'  => N,           // Growth on failure (0 for snapshot)
    'max_requirement'  => N,           // Cap to prevent runaway targets
    'configuration'    => [...],       // Type-specific config
    'is_active'        => true|false,  // Enable/disable from pool
]
```

### Validation Rules
- All keys above are required
- `weight` must be >= 1
- `base_requirement` must be <= `max_requirement`
- `code` must be unique across all entries
- Validation is performed automatically when `definitions()` is called

---

## Weight Guidelines

Weights control how often a challenge is randomly selected:

| Weight | Frequency | Example |
|---|---|---|
| 2 | Rare | zero_death_win (very hard to achieve) |
| 5 | Uncommon | fast_win (situational) |
| 8 | Normal | last_hits, total_heal |
| 10 | Common | total_denies, item_win |
| 15 | Frequent | total_kills, hero_win (core gameplay) |

Higher weight = higher probability of being assigned.
