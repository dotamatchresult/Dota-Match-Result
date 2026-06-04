# Task: Implement Step 4 Only - Challenge Evaluation Engine

Read `PLAN.md` first and follow the existing architecture decisions. Do NOT implement Step 5 or later phases.

## Scope

Implement only the Challenge Evaluation Engine (Step 4).

The goal is to support this flow:

```text
OpenDota Match Result Saved
↓
Dispatch EvaluateChallengesJob
↓
Find active destination challenges
↓
Evaluate match against challenge
↓
Update progress
↓
Detect completion
↓
Create ChallengeEvent
↓
Queue ChallengeNotification
```

Do NOT implement:

* 23:00 review scheduler
* failed_days increment
* increment_value logic
* sarcastic notifications
* OpenDota delay handling
* challenge requirement escalation
* notification delivery
* AI challenge generation

Those belong to future steps.

---

# Important Architectural Decisions

## OpenDota is the single source of truth

Challenges must ONLY be evaluated after OpenDota match data has been successfully stored.

Never evaluate from Steam API data.

---

## Evaluation must be asynchronous

Challenge evaluation must never block match processing.

Required flow:

```text
Store Match
↓
Dispatch EvaluateChallengesJob
↓
Continue normal match processing
```

Use a queued Job.

---

## Members

Only registered members belonging to the destination contribute.

Example:

Destination A:

* Alice
* Bob
* Charlie

Match:

* Alice plays
* Bob absent
* Charlie absent

Only Alice contributes.

---

## Member Ownership

A Member belongs to exactly one Destination.

No multi-destination membership support is required.

---

# Database Changes

Create a new migration:

```php
destination_challenges.current_progress
```

Requirements:

```php
unsignedInteger
default(0)
indexed
```

Update model fillable/casts as needed.

This field becomes the canonical numeric progress tracker.

Do NOT derive progress from JSON.

---

# Evaluation Modes

Do not hardcode snapshot vs accumulative logic.

Future challenges support multiple evaluation styles.

Store evaluation behavior inside challenge configuration.

Examples:

```json
{
  "evaluation_mode": "accumulative"
}
```

```json
{
  "evaluation_mode": "single_match_individual"
}
```

```json
{
  "evaluation_mode": "single_match_team"
}
```

No evaluator must assume only one mode exists.

---

# Build Core Framework

## ChallengeEvaluator Interface

Create:

```php
app/Contracts/Challenges/ChallengeEvaluator.php
```

Example shape:

```php
interface ChallengeEvaluator
{
    public function evaluate(
        DestinationChallenge $challenge,
        DotaMatch $match,
        Collection $participatingMembers
    ): EvaluationResult;
}
```

Evaluators must be pure.

They calculate.

They do NOT persist.

They do NOT send notifications.

They do NOT create events.

---

## EvaluationResult DTO

Create a dedicated DTO.

Required capabilities:

```php
matched
progressDelta
contributors
progressData
completed
```

Suggested shape:

```php
final class EvaluationResult
{
    public function __construct(
        public bool $matched,
        public int $progressDelta = 0,
        public array $contributors = [],
        public array $progressData = [],
        public bool $completed = false,
    ) {}
}
```

---

## ChallengeEvaluatorRegistry

Create a registry/service that resolves evaluator implementations from challenge code.

Example:

```php
$registry->resolve($challenge->challenge->code);
```

The mapping should use the existing evaluator config already added to `config/dota.php`.

Avoid giant switch statements.

---

# Progress Data Contract

Standardize all progress_data around this structure:

```json
{
  "contributors": {},
  "matches": [],
  "metadata": {}
}
```

Example:

```json
{
  "contributors": {
    "123": 2,
    "456": 1
  },
  "matches": [
    10001,
    10002
  ],
  "metadata": {}
}
```

Future evaluators may add extra metadata.

Do not create evaluator-specific top-level structures.

---

# ChallengeProgressService

Create a service responsible for persistence.

Responsibilities:

```text
Find applicable challenges
Resolve evaluator
Run evaluator
Persist progress
Create events
Detect completion
Queue completion notification
```

Evaluators should never directly mutate the database.

The service owns all mutations.

Suggested public API:

```php
processMatch(DotaMatch $match): void
```

---

# Idempotency Requirements

This is mandatory.

The same match may be reprocessed.

Challenge progress must never be counted twice.

Use existing challenge_events table.

Before applying progress:

Check whether a progress event already exists for:

```php
destination_challenge_id
match_id
type=progress
```

If already processed:

```php
skip
```

The unique index already exists.

Use it.

---

# Completion Logic

After applying progress:

```php
current_progress >= current_requirement
```

means completed.

Required actions:

```php
status = completed
completed_at = now()
```

Create:

```php
ChallengeEvent(type=completed)
```

Queue:

```php
ChallengeNotification(type=completed)
```

Notification should remain pending.

Do not send it.

---

# First Evaluator Only

Implement ONLY:

```text
HERO_WIN
```

Do not implement TOTAL_KILLS, TOTAL_DENIES, BKB_WIN, etc yet.

We want one complete vertical slice before expanding.

---

# HERO_WIN Behavior

Example challenge:

```text
Win 2 games using Pudge
```

Configuration:

```json
{
  "hero_id": 14
}
```

Rules:

* member must belong to destination
* member must use configured hero
* member must win the match

Progress equals number of qualifying members.

Example:

```text
Alice Pudge Win
Bob Pudge Win
Charlie Rubick Win
```

Result:

```text
progressDelta = 2
```

NOT:

```text
progressDelta = 1
```

This is intentional.

Multiple members may contribute in the same match.

---

# Contributors

EvaluationResult should return contributor information.

Example:

```php
[
    [
        'member_id' => 1,
        'value' => 1,
    ],
    [
        'member_id' => 2,
        'value' => 1,
    ],
]
```

ChallengeProgressService should merge contributor data into progress_data.

---

# Queue Job

Create:

```php
EvaluateChallengesJob
```

Responsibilities:

```php
public function handle(
    ChallengeProgressService $service
): void
{
    $service->processMatch($this->match);
}
```

The job should be dispatched only after a match is fully stored.

Do not wire notification delivery.

Only queue challenge notifications.

---

# Tests Required

Create comprehensive tests for:

## HeroWinEvaluator

* qualifying member contributes
* wrong hero ignored
* losing match ignored
* two qualifying members => +2 progress
* no destination members => no match

## ChallengeProgressService

* progress persisted
* completion detected
* completion notification queued
* progress event created
* duplicate processing skipped

## EvaluateChallengesJob

* job delegates to service

---

# Deliverables

Implement:

1. Migration adding `current_progress`
2. ChallengeEvaluator interface
3. EvaluationResult DTO
4. ChallengeEvaluatorRegistry
5. HeroWinEvaluator
6. ChallengeProgressService
7. EvaluateChallengesJob
8. Automated tests
9. Integration point hook (dispatch job after OpenDota match storage)

Do not implement any other challenge type.
Do not implement Step 5 features.
Keep the implementation extensible for future evaluators.
