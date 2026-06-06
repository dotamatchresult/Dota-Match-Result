# Task: Implement Step 9 — Challenge Simulation & Validation

Read and follow `DAILY_CHALLENGE_PLAN.md` first. The project has already completed Steps 1–8. Do not redesign previous steps and do not implement future steps beyond Step 9.

Your task is to implement **Step 9: Challenge Simulation & Validation** only.

---

## Context

The Daily Challenge system is already fully implemented:

* Challenge Catalog
* Assignment Engine
* Weighted Selection
* Runtime Hero/Item Randomization
* Evaluator Registry
* HeroWinEvaluator
* ItemWinEvaluator
* AccumulativeTeamMetricEvaluator
* SingleMatchTeamMetricEvaluator
* SingleMatchIndividualMetricEvaluator
* FastWinEvaluator
* ZeroDeathWinEvaluator
* Progress Tracking
* Notification System
* Review Service
* OpenDota Delay Handling
* Review Deferral Logic

We are NOT adding new challenge types.

We are NOT adding new evaluators.

We are NOT redesigning architecture.

This step is validation, simulation, and confidence testing.

---

# Goal

Create an end-to-end validation suite using real OpenDota fixtures and existing infrastructure.

The objective is to verify:

Steam Match
→ Match Stored
→ Challenge Evaluation
→ Progress Update
→ Completion
→ Notification Creation
→ Review Logic
→ Delay Handling

works correctly.

---

# Scope

Implement only the following.

---

## 9.1 Fixture Infrastructure

Create reusable fixtures using the existing files:

* match_result_unparsed.json
* match_result_parsed.json

Requirements:

* Store them under test fixtures
* Load them through helper methods
* Avoid duplicated fixture parsing across tests
* Create reusable factory/helper methods for:

  * Destination
  * Members
  * DestinationChallenge
  * DotaMatch
  * ChallengeNotification

Goal:

Future tests should be able to create a complete challenge scenario in a few lines.

---

## 9.2 End-to-End Evaluator Tests

Create end-to-end tests using real fixture data.

Cover:

### Hero Win

Flow:

* Assign hero_win challenge
* Configure hero matching fixture hero
* Process match
* Verify:

  * progress updated
  * event created
  * completion detected when requirement met
  * completion notification created

---

### Item Win

Flow:

* Assign item_win challenge
* Configure item matching fixture inventory
* Process match
* Verify:

  * inventory detection works
  * progress updated
  * completion notification created

---

### Total Kills

Flow:

* Assign total_kills
* Process match
* Verify:

  * contributor breakdown
  * current_progress
  * progress_data
  * completion behavior

---

### Total Denies

Same pattern.

---

### Total Heal

Same pattern.

---

### Last Hits

Verify:

* best_attempt
* best_member_id
* best_match_id
* completion behavior

---

### Fast Win

Verify:

* duration logic
* winning requirement
* completion behavior

---

### Zero Death Win

Verify:

* death check
* win requirement
* completion behavior

---

## 9.3 Progress Data Validation

Add dedicated tests validating normalized progress structure.

Verify:

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

Rules:

* Existing evaluators remain backward compatible
* Merge logic works
* Contributors accumulate correctly
* Best-attempt fields update only when improved

---

## 9.4 Idempotency Simulation

Create tests that process the same match multiple times.

Run:

* EvaluateChallengesJob twice
* ChallengeProgressService twice

Verify:

* progress not duplicated
* completion not duplicated
* notifications not duplicated
* ChallengeEvent unique constraint remains effective

This is one of the most important sections.

---

## 9.5 Review Delay Simulation

Create end-to-end tests for OpenDota delay handling.

Scenario:

### Delayed Review

* Match exists
* parse_status='pending'
* finished_at older than 1 hour

Run review.

Verify:

* review blocked
* no increment
* no failed_days increase
* no recap
* review_delayed notification created

---

### Resolution Path

* Same scenario
* match later becomes parsed

Run deferred review command.

Verify:

* review executes
* challenge processed normally

---

### Force Review

* review_delayed older than configured threshold

Verify:

* force review path executes
* review_forced events created

---

## 9.6 Weighted Assignment Validation

Create statistical assignment tests.

Purpose:

Validate weighted selection behaves reasonably.

Procedure:

* Seed challenge catalog
* Simulate assignment thousands of times
* Record counts

Verify:

* hero_win appears significantly more often than zero_death_win
* total_kills appears significantly more often than fast_win
* distribution roughly follows configured weights

Do NOT assert exact percentages.

Use tolerance-based assertions.

The goal is detecting broken weighting logic.

---

## 9.7 Notification Pipeline Validation

Create tests covering:

### Completion batching

Verify:

* multiple completed challenges
* same destination
* produces one batched notification

---

### Assignment announcements

Verify:

* scheduled_at respected
* future notifications skipped
* eligible notifications sent

---

### Review delayed notifications

Verify:

* destination resolution works
* duplicate notifications are not created

---

# Non-Goals

Do NOT implement:

* Step 10
* New challenge content
* New challenge catalog entries
* New evaluators
* New metrics
* Analytics dashboards
* Leaderboards
* Challenge recommendations
* LLM challenge generation
* Refactoring unrelated systems

---

# Deliverables

Provide:

## 1. Files Created

Grouped by:

* Fixtures
* Helpers
* Feature tests
* Integration tests

---

## 2. Files Modified

Explain why each modification was necessary.

---

## 3. Test Coverage Summary

Show:

* number of tests added
* assertions added
* challenge families covered
* idempotency coverage
* delay coverage
* notification coverage

---

## 4. Architectural Review

After implementation:

* identify any weaknesses discovered
* identify flaky tests
* identify opportunities for future improvements

Do not implement those improvements.

Only document them.

---

Important:

Favor realistic integration tests over mocking.

Use the real challenge services whenever possible.

The purpose of Step 9 is confidence that the production pipeline works correctly using real match payloads and real challenge flows.
