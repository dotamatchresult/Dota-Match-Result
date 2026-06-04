# Task: Implement Step 3 - Challenge Assignment Engine

Read and follow `plan.md` first. Step 1 (architecture) and Step 2 (migrations/models) are already implemented and should be treated as the source of truth.

Your task is to implement **Step 3: Challenge Assignment Engine** in Laravel 12.

## Goal

Implement challenge assignment only.

DO NOT implement challenge evaluation, progress tracking, challenge completion, or match processing yet.

This step only covers:

1. Daily challenge assignment at 00:00
2. Daily challenge announcement scheduling for 08:00
3. Active challenge limit enforcement
4. Assignment idempotency
5. Challenge selection logic
6. Notification queue creation
7. Audit event creation

---

## Existing Architecture

Already implemented:

### Tables

* challenges
* destination_challenges
* challenge_events
* challenge_notifications

### Models

* Challenge
* DestinationChallenge
* ChallengeEvent
* ChallengeNotification
* Destination

### Config

config/dota.php

Contains:

```php
daily_challenge => [
    'enabled' => true,
    'max_active_per_destination' => 5,
    'opendota_delay_grace_minutes' => 30,
]
```

Challenge seeder already exists.

---

## Business Rules

### Rule 1 - One new challenge per destination per day

At 00:00 each destination should receive exactly one new challenge.

### Rule 2 - Maximum active challenges

Use:

```php
config('dota.daily_challenge.max_active_per_destination')
```

If active challenge count is already at the limit:

* Do not assign a new challenge
* Create a backlog notification entry

### Rule 3 - Challenge cooldown

Avoid assigning a challenge that has already been assigned to the same destination recently.

Add new config:

```php
assignment_history_days => 14
```

Selection should prefer challenges not assigned within the last 14 days.

### Rule 4 - Pool exhausted fallback

If every active challenge has already been assigned within the cooldown period:

Fallback to any active challenge.

Never fail assignment because of cooldown exhaustion.

### Rule 5 - Idempotency

The scheduler may run twice.

A destination must never receive more than one challenge assignment on the same day.

Before assignment, verify:

```php
destination_id
assigned_date
```

has not already been assigned today.

If today's assignment already exists:

Return without creating anything.

---

## Required Deliverables

### 1. ChallengeAssignmentService

Create:

```php
app/Services/DailyChallenge/ChallengeAssignmentService.php
```

Responsibilities:

```text
Destination
↓
Select Challenge
↓
Create DestinationChallenge
↓
Create ChallengeEvent
↓
Create ChallengeNotification
```

Suggested public API:

```php
public function assignForDestination(
    Destination $destination
): ?DestinationChallenge
```

Use database transactions.

---

### 2. Challenge Selection Logic

Algorithm:

1. Check active challenge count
2. Check daily idempotency
3. Gather recently assigned challenge IDs
4. Select active challenge not recently assigned
5. Fallback to any active challenge if pool exhausted

Use random selection.

---

### 3. Assignment Creation

Create DestinationChallenge:

```php
status = active

current_requirement = challenge.base_requirement

assigned_date = today()

progress_data = []
```

---

### 4. Event Creation

Create ChallengeEvent:

```php
type = assigned

value_before = 0

value_after = challenge.base_requirement
```

Payload should include useful debugging information.

Example:

```json
{
  "challenge_code": "TOTAL_KILLS"
}
```

---

### 5. Announcement Notification Queue

Do not send messages.

Only create ChallengeNotification.

Type:

```text
assigned_announcement
```

Status:

```text
pending
```

Scheduled:

```text
08:00 Asia/Jakarta
```

Store any useful payload snapshot that may help future notification rendering.

---

### 6. Backlog Notification Queue

When active challenge limit is reached:

Create notification:

```text
type = backlog_full
status = pending
```

Do not assign a challenge.

---

### 7. Artisan Command

Create:

```bash
php artisan make:command AssignDailyChallenges
```

Responsibilities:

* Iterate destinations in chunks
* Call ChallengeAssignmentService
* Log summary statistics

Suggested chunk size:

```php
100
```

---

### 8. Scheduler Registration

Register command in Laravel scheduler.

Schedule:

```php
dailyAt('00:00')
```

Timezone:

```php
Asia/Jakarta
```

---

### 9. Logging

Add structured logging.

Useful fields:

* destination_id
* challenge_id
* challenge_code
* assignment_date
* active_challenge_count

Log:

* assignment created
* skipped due to daily idempotency
* skipped due to max active challenges
* fallback challenge selection

---

### 10. Tests

Create feature tests covering:

#### Assignment Created

* destination has fewer than max active challenges
* challenge assigned
* event created
* announcement notification created

#### Max Active Limit

* destination already has max active challenges
* no assignment created
* backlog notification created

#### Daily Idempotency

* command executed twice
* only one assignment created

#### Cooldown Logic

* recently assigned challenges excluded

#### Pool Exhaustion Fallback

* all challenges recently assigned
* assignment still succeeds

---

## Constraints

* Laravel 12
* Follow existing project conventions
* Use transactions where appropriate
* Prefer service classes over fat commands
* Keep implementation production-ready
* Do not implement evaluator engine yet
* Do not implement challenge progress tracking yet
* Do not implement challenge completion yet

After implementation, provide:

1. Files created
2. Files modified
3. Architectural decisions
4. Any concerns or recommended improvements before Step 4
