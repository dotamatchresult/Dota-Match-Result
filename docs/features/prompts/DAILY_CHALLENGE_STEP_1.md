# Prompt

You are a senior Laravel 12 architect.

I need you to implement only the database layer and Eloquent models for a new Daily Challenge feature.

## Existing Project Context

This is a Laravel 12 application.

Existing models:

* Destination
* Member
* Match

Relationships:

* Destination has many Members
* Member belongs to Destination
* Member has many Matches

The system tracks Dota 2 Turbo matches and sends match result notifications to chat groups (Destinations).

DO NOT implement schedulers, jobs, services, challenge evaluation logic, notification sending, or business logic yet.

Only implement:

* migrations
* models
* relationships
* enums
* casts
* indexes
* config entries

---

# Daily Challenge Requirements

A Destination can have multiple active challenges.

Every day at 00:00 a new challenge may be assigned.

Old unfinished challenges remain active.

Example:

Monday:

* Win 2 games with Pudge

Tuesday:

* Get 20 denies

Wednesday:

* Get 150 last hits

All 3 challenges remain active until completed.

A Destination can have at most:

```php
config('dota.daily_challenge.max_active_per_destination')
```

Default value:

```php
5
```

If the limit is reached, no new challenge will be assigned.

Challenges never expire.

Challenge states:

* active
* completed

---

# Database Design

## challenges

Challenge templates.

Columns:

```sql
id

code
name
description

category

base_requirement
increment_value
max_requirement

configuration json nullable

is_active boolean

created_at
updated_at
```

Requirements:

* code unique
* category indexed
* is_active indexed

Example configuration:

Hero challenge:

```json
{
  "hero_id": 14
}
```

Fast win challenge:

```json
{
  "duration_minutes": 23
}
```

---

## destination_challenges

Represents a challenge assigned to a destination.

Columns:

```sql
id

destination_id
challenge_id

assigned_date

status

current_requirement

progress

failed_days

progress_data json nullable

completed_at nullable

created_at
updated_at
```

Requirements:

* destination_id foreign key
* challenge_id foreign key

Indexes:

```sql
(destination_id, status)
(destination_id, assigned_date)
(status)
```

Status values:

```php
active
completed
```

---

## challenge_events

Audit trail.

Columns:

```sql
id

destination_challenge_id

type

value_before nullable
value_after nullable

match_id nullable

payload json nullable

created_at
```

Event types:

```php
assigned
progress
incremented
completed
```

Requirements:

* destination_challenge_id foreign key
* match_id nullable foreign key to matches table

Indexes:

```sql
(destination_challenge_id)
(type)
(match_id)
```

---

## challenge_notifications

Stores challenge-related notifications.

Columns:

```sql
id

destination_challenge_id

type

scheduled_at

sent_at nullable

status

payload json nullable

created_at
updated_at
```

Notification types:

```php
announcement
completion
recap
failure
backlog_full
```

Notification status:

```php
pending
sent
cancelled
```

Indexes:

```sql
(status, scheduled_at)
(destination_challenge_id)
(type)
```

---

# Laravel Requirements

Create:

## Models

* Challenge
* DestinationChallenge
* ChallengeEvent
* ChallengeNotification

Use:

```php
protected $fillable
protected $casts
```

appropriately.

JSON columns must be cast to array.

Dates should use datetime casts.

---

# Relationships

Challenge

```php
hasMany DestinationChallenge
```

DestinationChallenge

```php
belongsTo Destination
belongsTo Challenge
hasMany ChallengeEvent
hasMany ChallengeNotification
```

ChallengeEvent

```php
belongsTo DestinationChallenge
belongsTo Match nullable
```

ChallengeNotification

```php
belongsTo DestinationChallenge
```

---

# Enums

Use PHP 8.3 backed enums.

Create:

## ChallengeStatus

```php
active
completed
```

## ChallengeEventType

```php
assigned
progress
incremented
completed
```

## ChallengeNotificationType

```php
announcement
completion
recap
failure
backlog_full
```

## ChallengeNotificationStatus

```php
pending
sent
cancelled
```

Use enum casting in Eloquent models.

---

# Config

Modify config/dota.php

Add:

```php
'daily_challenge' => [
    'max_active_per_destination' => 5,
],
```

Do not overwrite existing config.

---

# Deliverables

Generate:

1. all migrations
2. all enums
3. all Eloquent models
4. relationship methods
5. casts
6. indexes
7. foreign keys
8. config changes

Do not generate services, jobs, commands, schedulers, controllers, repositories, policies, factories, tests, or business logic.

Implement only the persistence layer for Step 1.
