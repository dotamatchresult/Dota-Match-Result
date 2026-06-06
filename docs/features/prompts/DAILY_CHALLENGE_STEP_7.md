# Step 7 Implementation — Challenge Catalog System

Read and follow the project's `PLAN.md` for architecture, coding standards, naming conventions, and previously completed steps.

This task is **STRICTLY Step 7 only**.

Do **NOT** implement Step 8 or later steps.

Do **NOT** add new challenge evaluators.

Do **NOT** modify challenge evaluation behavior.

Do **NOT** modify OpenDota delay handling.

Do **NOT** add AI-generated challenge features.

Your goal is only to implement the Challenge Catalog System and improve challenge assignment using catalog metadata.

---

# Context

Current state:

* Step 1: Database schema completed
* Step 2: Models completed
* Step 3: Challenge Assignment Engine completed
* Step 4: Challenge Evaluation Engine completed
* Step 5: Notification Delivery completed
* Step 6: OpenDota Delay Handling completed

Currently challenges are seeded manually through `ChallengeSeeder`.

We want to move to:

```text
Challenge Catalog (source of truth)
↓
Seeder syncs catalog to DB
↓
Assignment Engine uses catalog metadata
```

The catalog must become the canonical definition of all challenge templates.

The database is only a synchronized runtime representation.

---

# Requirements

## 1. Add Challenge Weight

Create a migration:

```php
challenges.weight
```

Requirements:

* unsigned integer
* default 10
* indexed if appropriate

Update:

* Challenge model
* factories
* seeders
* tests

---

# 2. Create Challenge Catalog

Create:

```php
app/Support/DailyChallenge/ChallengeCatalog.php
```

Example structure:

```php
final class ChallengeCatalog
{
    public static function definitions(): array
    {
        return [
            [
                'code' => 'hero_win',
                'name' => 'Win Using Hero',
                'description' => '...',
                'weight' => 15,
                'base_requirement' => 1,
                'increment_value' => 1,
                'max_requirement' => 3,
                'configuration' => [
                    'random_hero' => true,
                    'excluded_heroes' => [
                        82, // Meepo
                    ],
                ],
            ],
        ];
    }
}
```

This file becomes the canonical source of challenge definitions.

---

# 3. Create Challenge Catalog Validator

Create:

```php
app/Support/DailyChallenge/ChallengeCatalogValidator.php
```

Validate every catalog entry before seeding.

Validation rules:

Required:

* code
* name
* description
* weight
* base_requirement
* increment_value
* max_requirement

Weight:

```text
>= 1
```

Requirement:

```text
base_requirement <= max_requirement
```

Code:

Must be unique.

If validation fails:

Throw a clear exception with the offending challenge code.

---

# 4. Refactor Seeder

Refactor existing challenge seeding to use the catalog.

Preferred behavior:

```php
foreach (ChallengeCatalog::definitions() as $definition) {
    Challenge::updateOrCreate(
        ['code' => $definition['code']],
        $definition,
    );
}
```

Requirements:

* idempotent
* safe to run multiple times
* update existing records
* create missing records

Do NOT delete existing rows automatically.

---

# 5. Initial Catalog Contents

Move current challenge definitions from the existing seeder into the catalog.

Include current supported challenge types:

* hero_win
* item_win
* total_kills
* total_denies
* total_heal
* last_hits
* zero_death_win
* fast_win

Add reasonable weights.

Suggested weights:

```text
hero_win        = 15
item_win        = 10
total_kills     = 15
total_denies    = 10
total_heal      = 8
last_hits       = 8
fast_win        = 5
zero_death_win  = 2
```

Use these unless the current project already has a better balancing rationale.

---

# 6. Assignment Engine Improvements

Update ChallengeAssignmentService.

Currently assignment selects randomly.

Replace with weighted random selection.

Requirements:

* Higher weight = higher chance
* Keep existing cooldown logic
* Keep existing active challenge limit logic
* Keep existing backlog logic

Weighted selection should be deterministic and testable.

---

# 7. Exclude Active Challenge Codes

New rule:

A destination cannot receive a challenge if another active challenge with the same code already exists.

Example:

Active:

```text
hero_win
```

Candidate pool must exclude:

```text
hero_win
```

even if the runtime hero would be different.

Implementation should occur during candidate selection.

---

# 8. Hero Restrictions

Current project rule:

Exclude Meepo only.

Implement via catalog configuration:

```php
'excluded_heroes' => [
    82,
]
```

Do NOT add other hero restrictions.

Do NOT implement pick-rate filtering.

Do NOT implement difficulty-based filtering.

---

# 9. Documentation

Create:

```text
docs/challenge-authoring.md
```

Document:

## Allowed Metrics

* kills
* assists
* deaths
* wins
* hero_id
* item_id
* last_hits
* denies
* hero_healing
* duration

## Forbidden Metrics

* roshan
* wards
* sentries
* courier kills
* damage types
* skill usage
* item activations
* stun duration
* silence duration

## Design Rules

* Turbo compatible
* Achievable in 1–3 matches
* No griefing incentives
* No intentional feeding incentives
* No AFK incentives

This document will be used later for LLM-generated challenge validation.

---

# 10. Tests

Add or update tests covering:

## Catalog

* definitions load correctly
* validator passes valid catalog
* validator rejects invalid entries
* validator rejects duplicate codes

## Seeder

* creates missing challenges
* updates existing challenges
* idempotent behavior

## Assignment

* weighted selection works
* active code exclusion works
* cooldown logic still works
* max active limit still works

Follow existing Pest style.

---

# Non-Goals

Do NOT implement:

* new challenge evaluators
* AI challenge generation
* challenge difficulty tiers
* challenge categories beyond current implementation
* admin UI
* challenge editing screens
* challenge import/export
* challenge analytics
* Step 8 or later roadmap items

Only implement Step 7.

---

# Deliverables

Provide:

1. Summary of created files
2. Summary of modified files
3. Migration details
4. Test coverage summary
5. Any architectural decisions made during implementation
6. Any deviations from this specification and justification

Run Pint and ensure tests pass.
