You are helping implement **Step 6 only** of an existing Daily Challenge system for a Laravel 12 application.

Read PLAN.md first and strictly follow the existing architecture, naming conventions, services, models, commands, tests, and patterns already implemented.

Do NOT redesign previous steps.

Do NOT implement future steps.

Only implement the requirements described below.

# Context

The system already has:

* Challenge assignment engine
* Challenge evaluation engine
* Notification delivery system
* Daily review system (23:00)
* Telegram + Fonnte integration
* OpenDota-based challenge evaluation
* Steam Match History polling every minute

Current flow:

```text
Steam Match History
↓
New Match Detected
↓
OpenDota Match Fetch
↓
Store Match
↓
ProcessMatchNotification
↓
EvaluateChallengesJob
```

Challenge review currently runs at:

```text
23:00 Asia/Jakarta
```

and may increment unfinished challenges.

The problem:

```text
Steam already knows a match exists
but OpenDota may be delayed.
```

This can cause false review failures.

Example:

```text
22:45 match starts
23:05 match ends

Steam knows match exists
OpenDota still unavailable

23:00 review runs
challenge incorrectly fails

23:20 OpenDota result arrives
challenge should have completed
```

We must prevent this.

# Updated Business Rules

Important: challenge assignment now happens at:

```text
08:00 Asia/Jakarta
```

not midnight.

Challenge lifecycle:

```text
08:00
new challenge assigned

08:00 - 23:00
challenge window

23:00
review

23:00 - 08:00
free session
```

Matches between:

```text
23:00 and 08:00
```

should not be treated specially.

If OpenDota arrives after 08:00 the next day, challenge evaluation should still run normally.

We intentionally accept that delayed matches may contribute to currently-active challenges.

Do NOT add complicated cross-day challenge protection logic.

Keep implementation simple.

# OpenDota Delay Rule

At review time (23:00):

For each destination:

If there exists a Steam-detected match that:

```text
started more than 1 hour ago
```

and

```text
still has no OpenDota result
```

then:

```text
skip review
skip requirement increment
skip failed_days increment
skip recap notification
```

for that destination.

The destination enters a deferred-review state.

# Delayed Review Notification

When review is skipped because of OpenDota delay:

Create a notification:

```text
type = review_delayed
```

Example message:

```text
⏳ Review tantangan hari ini ditunda.

Masih ada pertandingan yang belum diproses OpenDota.
Kami akan mengecek ulang secara otomatis setelah hasil pertandingan tersedia.
```

This notification should only be sent once per review cycle.

Do not spam repeated delay notifications.

# Deferred Review Processing

Create a scheduler that runs:

```text
every 10 minutes
```

Purpose:

```text
Find destinations whose review was deferred.
Check whether all blocking matches have been resolved.
If resolved:
    run normal review logic
```

The review outcome must be exactly the same as if it had happened at 23:00.

Reuse existing services whenever possible.

Avoid duplicating review logic.

# Maximum Delay Protection

OpenDota can sometimes be delayed for many hours.

Add config:

```php
daily_challenge.review_force_after_hours
```

Default:

```php
12
```

If a deferred review remains blocked longer than this threshold:

```text
force review
```

even if OpenDota data still has not arrived.

Create audit event:

```text
type = review_forced
```

before running the review.

# Match Finish Timestamp

Currently finish time is derived from:

```text
start_time + duration
```

Implement an explicit column:

```php
dota_matches.finished_at
```

Requirements:

* migration
* model cast
* backfill existing rows in migration if practical
* populate automatically for new matches

Store UTC consistently following existing project conventions.

# Review Guard Service

Create:

```php
ChallengeReviewGuardService
```

Responsibilities:

```php
canReviewDestination(Destination $destination): ReviewDecision
```

Return a DTO/value object describing:

```php
allowed
blocked
forceReview
blockingMatches
```

Avoid returning raw arrays.

# Deferred Review Persistence

Do NOT store deferred reviews only in memory.

Create a persistent mechanism.

You may choose either:

Option A:

* new database table

Option B:

* dedicated challenge notification type with metadata

Choose the simplest solution that integrates cleanly with existing architecture.

Explain your choice in code comments.

The system must survive queue restarts and deployments.

# Notification System

Integrate with existing Step 5 notification architecture.

Support new type:

```text
review_delayed
```

through:

* ChallengeMessageRenderer
* ChallengeNotificationDispatcher
* tests

# Testing Requirements

Add comprehensive tests for:

1. Review runs normally when no delayed matches exist.
2. Review is skipped when unresolved Steam match exists.
3. Review_delayed notification is created.
4. Deferred review later succeeds after match resolution.
5. Forced review after configured threshold.
6. Duplicate deferred reviews are not created.
7. Review remains idempotent.
8. finished_at calculation works correctly.

Follow existing Pest style used in the project.

# Constraints

* Laravel 12
* Reuse existing architecture
* Do not break existing tests
* Prefer small focused services
* Maintain event audit trail
* Maintain notification-based architecture
* Maintain idempotency guarantees
* Keep implementation limited strictly to Step 6

# Deliverables

Provide:

1. Architectural plan
2. Files to create
3. Files to modify
4. Database migrations
5. Service implementations
6. Command/scheduler changes
7. Test implementations
8. Any assumptions made

Before writing code, review the existing implementation from DAILY_CHALLENGE_PLAN.md and align with existing naming and patterns.
