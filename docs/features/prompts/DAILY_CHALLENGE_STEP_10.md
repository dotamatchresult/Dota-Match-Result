You are working on an existing Laravel 12 project. Read the attached PLAN.md first.

IMPORTANT RULES:

* Only implement **Step 10: Challenge Content Expansion**
* Do NOT redesign, refactor, or revisit Steps 1-9 unless absolutely required by Step 10
* Do NOT introduce new evaluator patterns
* Do NOT introduce new infrastructure layers
* Do NOT change database schema unless explicitly justified
* Reuse the existing architecture
* Reuse existing evaluators whenever possible
* Keep changes minimal and focused on content expansion

Current system status:

* Challenge Catalog exists and is the canonical source of truth
* Weighted challenge assignment exists
* Active challenge code exclusion exists
* MetricRegistry exists
* Evaluator framework exists
* OpenDota delay handling exists
* Notification system exists
* Review/escalation exists
* All existing tests pass

The goal of Step 10 is to significantly expand challenge variety using the evaluator framework built in Step 8.

---

# Existing Evaluators

Already available:

* HeroWinEvaluator
* ItemWinEvaluator
* AccumulativeTeamMetricEvaluator
* SingleMatchTeamMetricEvaluator
* SingleMatchIndividualMetricEvaluator
* FastWinEvaluator
* ZeroDeathWinEvaluator

Do not create additional evaluators unless absolutely required.

---

# Available Metrics

Only use metrics already available in our stored unparsed OpenDota payload:

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

Do not use:

* roshan
* wards
* sentries
* courier kills
* skill usage
* item activation
* damage type breakdowns
* stun duration
* silence duration
* parsed-only data
* benchmarks

---

# Design Rules

Every challenge must:

* Work in Turbo mode
* Be realistically achievable
* Encourage teamwork
* Avoid griefing incentives
* Avoid intentional feeding
* Avoid AFK incentives
* Avoid extreme RNG dependence
* Be completable within roughly 1-3 matches under normal conditions
* Support challenge escalation where appropriate

Prefer:

"One player reaches X"

instead of:

"Player with most X"

because teammates can help achieve it.

---

# Challenge Families To Add

Expand the catalog from the current small set into a much larger challenge library.

Add challenges using existing evaluator patterns.

Examples:

## Accumulative Team

* Total assists
* Total hero damage
* Total tower damage
* Total last hits
* Total net worth accumulated across members

## Single Match Team

* Team assists in one match
* Team kills in one match
* Team last hits in one match
* Team denies in one match
* Team hero damage in one match
* Team tower damage in one match

## Single Match Individual

* One player reaches kills target
* One player reaches assists target
* One player reaches last hits target
* One player reaches hero damage target
* One player reaches tower damage target
* One player reaches net worth target
* One player reaches GPM target
* One player reaches XPM target

Use sensible thresholds suitable for Turbo mode.

---

# Catalog Improvements

Introduce optional challenge grouping.

Example:

* kills
* assists
* last_hits
* hero_damage
* tower_damage
* economy
* hero_win
* item_win

Goal:

Prevent assignment from repeatedly selecting challenges that feel nearly identical.

Example:

Bad:

Monday: total_kills
Tuesday: team_kills_match
Wednesday: player_kills_match

Even though codes differ, the gameplay experience is repetitive.

Implement a lightweight grouping mechanism.

Requirements:

* Minimal schema changes
* Compatible with existing assignment engine
* Assignment should avoid active challenges from the same group
* Keep weighted selection behavior

---

# Weights

Review and rebalance weights across the expanded catalog.

Guidelines:

Common:

* kills
* assists
* hero_win

Medium:

* denies
* hero_damage
* tower_damage

Rare:

* fast_win
* zero_death_win
* extreme GPM/XPM challenges

Provide justification for the chosen weights.

---

# Deliverables

Implement Step 10 and provide:

1. Architecture summary
2. Files created
3. Files modified
4. Challenge catalog additions
5. Grouping design
6. Assignment logic changes
7. Weight balancing rationale
8. Migration summary (if any)
9. Test plan
10. Risks and edge cases

Before coding:

First inspect PLAN.md and compare it with the implementation assumptions above.

If anything conflicts with PLAN.md, explain the conflict and propose the smallest possible adjustment before implementation.

Do not continue expanding scope beyond Step 10.
