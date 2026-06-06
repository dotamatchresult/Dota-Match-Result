# PLAN.md

## Daily Challenge System Roadmap

### Status

The Daily Challenge system is now production-ready.

Completed milestones include:

* Step 1 — Database Design
* Step 2 — Models & Relationships
* Step 3 — Challenge Assignment Engine
* Step 4 — Challenge Evaluation Engine
* Step 5 — Messaging Service Integration
* Step 6 — OpenDota Delay Handling
* Step 7 — Challenge Catalog System
* Step 8 — Evaluator Expansion & Metric Framework
* Step 9 — Challenge Simulation & Validation
* Step 10 — Challenge Content Expansion

Current challenge catalog:

* 27 challenge templates
* Group-aware assignment
* Weighted random selection
* Runtime hero/item randomization
* OpenDota delay protection
* Escalation system
* WhatsApp & Telegram notifications

---

# Step 11 — Challenge Analytics & Balancing

## Goal

Provide visibility into challenge health, completion rates, difficulty, and player engagement.

## Deliverables

### Challenge Statistics

Track per challenge:

* Total assigned
* Total completed
* Total failed
* Completion rate
* Average completion time
* Average failed days before completion

Example:

| Challenge        | Completion Rate |
| ---------------- | --------------- |
| total_kills      | 84%             |
| hero_win         | 72%             |
| player_xpm_match | 18%             |

### Group Statistics

Track performance by challenge group:

* kills
* assists
* damage
* economy
* objectives
* survival
* hero
* item

Example:

| Group   | Completion Rate |
| ------- | --------------- |
| assists | 82%             |
| damage  | 76%             |
| economy | 41%             |

### Escalation Analysis

Measure:

* Average requirement increase
* Most frequently escalated challenges
* Challenges commonly abandoned

### Admin Command

Create:

```bash
php artisan challenges:analytics
```

Outputs:

* Top easiest challenges
* Top hardest challenges
* Most completed challenges
* Most failed challenges

### Future Dashboard Compatibility

Design statistics collection so data can later be exposed through:

* Filament
* Laravel Pulse
* Custom admin panel

---

# Step 12 — Streak System

## Goal

Reward consistent challenge participation.

## Deliverables

### Destination Streaks

Track:

* Current streak
* Longest streak

Definition:

A streak increases when at least one challenge is completed on a day.

### Notifications

Examples:

```text
🔥 3 hari berturut-turut menyelesaikan tantangan!

🔥🔥🔥 STREAK 7 HARI!
```

### Bonus Messages

Special celebrations:

* 3 days
* 7 days
* 14 days
* 30 days

### Optional Future Rewards

Potential future integrations:

* Special titles
* Custom announcements
* Hall of fame

---

# Step 13 — Seasonal Events

## Goal

Allow temporary challenge pools during special occasions.

## Examples

### Battle Pass Week

Only use:

* Hero challenges
* Item challenges

### Economy Week

Only use:

* GPM
* XPM
* Net worth

### Ramadan Event

Custom challenge pool.

### TI Season Event

Custom challenge pool tied to The International.

## Deliverables

### Event Table

Store:

* name
* start_date
* end_date
* active flag

### Assignment Integration

Assignment engine prefers event-specific challenges when an event is active.

---

# Step 14 — Challenge Difficulty Tuning

## Goal

Use analytics data to automatically improve challenge balance.

## Deliverables

### Difficulty Review Command

```bash
php artisan challenges:review-balance
```

Outputs:

* Underperforming challenges
* Overperforming challenges
* Suggested threshold adjustments

### Balance Rules

Examples:

```text
Completion rate < 20%
→ challenge likely too hard

Completion rate > 90%
→ challenge likely too easy
```

### Catalog Reports

Generate recommendations for:

* Base requirement
* Increment value
* Maximum requirement
* Weight

---

# Step 15 — AI-Assisted Challenge Authoring

## Goal

Use LLM assistance to generate challenge proposals while keeping human approval.

## Deliverables

### Challenge Draft Generator

Input:

```text
Generate 20 Turbo challenge ideas.
```

Output:

Structured challenge proposals compatible with ChallengeCatalog.

### Validation Rules

Generated challenges must:

* Use unparsed match data only
* Avoid griefing incentives
* Avoid role-specific punishment
* Be completable in Turbo
* Respect challenge-authoring.md

### Human Approval Workflow

AI can suggest.

AI cannot directly publish.

All catalog additions remain manually reviewed.

---

# Step 16 — Community Features

## Goal

Increase long-term engagement.

## Ideas

### Destination Leaderboards

Track:

* Challenges completed
* Completion rate
* Current streak
* Longest streak

### Monthly Rankings

Top destinations.

### Hall of Fame

Best performers of the month.

### Seasonal Champions

Winners for special events.

---

# Future Challenge Expansion

Potential additions already supported by the existing evaluator framework:

### Team Accumulative

* Total assists
* Total hero damage
* Total tower damage
* Total last hits
* Total net worth

### Team Single Match

* Team kills
* Team assists
* Team last hits
* Team denies
* Team hero damage
* Team tower damage

### Individual Single Match

* Kills
* Assists
* Last hits
* Hero damage
* Tower damage
* Net worth
* GPM
* XPM

No new evaluator classes should be required for these additions.

---

# Long-Term Principles

1. Challenge definitions remain catalog-driven.
2. Evaluators remain generic whenever possible.
3. Only use metrics available in stored unparsed OpenDota results.
4. Assignment diversity is preferred over raw challenge count.
5. Analytics should drive balancing decisions.
6. New content should not require architectural changes.
7. OpenDota delays must never create false failures.
8. Human review remains required for challenge pool updates.
