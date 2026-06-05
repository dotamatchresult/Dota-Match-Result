# Step 5.9: End-of-Day Review, Increment Logic & Recap Notifications

## Context

This prompt implements the remaining pieces of Step 5 (Notification Delivery System) that were deferred from Steps 3-5. You are working on a Laravel 12 application with an existing daily challenge system. Refer to `docs/features/DAILY_CHALLENGE_PLAN.md` for the full architecture. Only the features listed below are in scope.

## What Already Exists (DO NOT MODIFY)

| Component | File | What It Does |
|---|---|---|
| ChallengeProgressService | `app/Services/DailyChallenge/ChallengeProgressService.php` | Processes matches, evaluates challenges, persists progress, detects completion, creates `completed` notifications |
| ChallengeNotificationDispatcher | `app/Services/DailyChallenge/ChallengeNotificationDispatcher.php` | Reads pending notifications, batches completions, sends via DestinationMessageService, marks sent |
| ChallengeMessageRenderer | `app/Services/DailyChallenge/ChallengeMessageRenderer.php` | Renders `assigned_announcement`, `completed` (single/multi), `backlog_full` messages in Bahasa Indonesia |
| ChallengeDescriptionService | `app/Services/DailyChallenge/ChallengeDescriptionService.php` | Generates human-readable challenge descriptions in Bahasa Indonesia |
| DestinationMessageService | `app/Services/Messaging/DestinationMessageService.php` | Routes messages to TelegramService or FonnteService based on destination code |
| Challenge model | `app/Models/Challenge.php` | Fields: `code`, `name`, `base_requirement`, `increment_value`, `max_requirement`, `configuration`, `is_active` |
| DestinationChallenge model | `app/Models/DestinationChallenge.php` | Fields: `status` (active/completed), `current_requirement`, `current_progress`, `failed_days`, `progress_data` |
| ChallengeNotification model | `app/Models/ChallengeNotification.php` | Fields: `type`, `status` (pending/sent/cancelled), `scheduled_at`, `sent_at`, `payload`; supports `destination_challenge_id = null` for non-challenge notifications |
| ChallengeEvent model | `app/Models/ChallengeEvent.php` | Append-only audit log; types: `assigned`, `progress`, `completed` |
| Scheduler | `routes/console.php` | Already has: `challenges:assign-daily` (00:00), `challenges:send-notifications` (every minute) |

## What You Must Implement

### Deliverable 1: ChallengeReviewService

Create `app/Services/DailyChallenge/ChallengeReviewService.php`.

**Responsibility**: Run the 23:00 end-of-day review for all active challenges across all destinations.

**Method**: `review(): array` → returns `['reviewed' => int, 'incremented' => int, 'failed' => int, 'recap_destinations' => int]`

**Algorithm** (for each active DestinationChallenge):

1. Query all `DestinationChallenge` where `status = 'active'` and `assigned_date` is today in `Asia/Jakarta` timezone.
2. For each:
   - **If completed** (`current_progress >= current_requirement`): Skip — already handled by ChallengeProgressService.
   - **If not completed**:
     - Load the associated `Challenge` model.
     - **Increment requirement** if `challenge.increment_value > 0` AND `current_requirement < challenge.max_requirement`:
       - New requirement = `min(current_requirement + increment_value, max_requirement)`
       - Create `ChallengeEvent(type='incremented')` with `value_before`/`value_after` reflecting the old and new requirement.
       - Update `current_requirement` on the DestinationChallenge.
     - **Snapshot challenges** (`increment_value = 0`): Do NOT change `current_requirement`.
     - **Increment `failed_days`**: `failed_days += 1` (regardless of whether requirement was incremented).
     - Create a `ChallengeEvent(type='failed_review')` for the audit log (value_before=current_progress, value_after=current_requirement).
     - Do NOT create an individual `ChallengeNotification` for this challenge — the recap covers all failures in one message.
   - Wrap each challenge's updates in a DB transaction.
3. After processing all active challenges, create a **recap notification** per destination, but **only if** that destination had at least one failed challenge (see Deliverable 4). If all challenges completed, skip recap for that destination.
4. Log outcomes via `Log::info`.

**Important constraints**:
- Do NOT handle OpenDota delays — that's Step 6 territory. Assume all matches are processed by review time.
- Use `chunkById(100)` for iteration to avoid memory issues.
- Use `Carbon::now('Asia/Jakarta')` for timezone-aware date checks.

---

### Deliverable 2: Increment Logic in Detail

The increment logic lives inside `ChallengeReviewService`:

```
if (challenge.increment_value > 0 && current_requirement < challenge.max_requirement):
    new_req = min(current_requirement + challenge.increment_value, challenge.max_requirement)
    update current_requirement = new_req
    create ChallengeEvent(type='incremented', value_before=old_req, value_after=new_req)
endif

failed_days += 1
create ChallengeEvent(type='failed_review', value_before=current_progress, value_after=current_requirement)
// No individual notification — recap covers all failures in one message
```

**Snapshot challenges**: `increment_value` is always 0 — only `failed_days` increments, requirement stays the same.

---

### Deliverable 3: New Notification Type in ChallengeMessageRenderer

Extend `app/Services/DailyChallenge/ChallengeMessageRenderer.php` to support one new notification type.

**Add to the `render()` match statement:**

#### `recap`

**Only sent when there are failed challenges.** If all challenges completed, no recap is generated.

Message format (Bahasa Indonesia):

```
🪦 {failed_count} tantangan gak selesai

{encouragement}

Sebagai hukuman, tantangan kalian ditambah 👺:
- {description_1} ({progress_1}/{req_1} selesai)
- {description_2} ({progress_2}/{req_2} selesai)
```

Where:
- `{description}`: From `ChallengeDescriptionService` (reflects the new incremented `current_requirement`)
- `{progress}`: `current_progress` (unchanged) 
- `{req}`: `current_requirement` (may have been incremented)
- `{encouragement}` varies by failed count:
  - 1 failed: `Masih bisa dikejar besok.`
  - 2-3 failed: `Yuk lebih fokus besok.`
  - 4+ failed: `Evaluasi strategi kalian.`

Example:
```
🪦 3 tantangan gak selesai

Evaluasi strategi kalian.

Sebagai hukuman, tantangan kalian ditambah 👺:
- Menangkan 3 pertandingan menggunakan Pudge (1/3 selesai)
- Pulihkan 32.000 HP (24.000/32.000 selesai)
- Menangkan pertandingan tanpa mati (0/1 selesai)
```

**Recap notification**: `destination_challenge_id = null` (like `backlog_full`). Store `destination_id` in payload so the dispatcher can resolve it. The dispatcher already handles this pattern — no changes needed.

---

### Deliverable 4: Recap Notification Creation

The recap is created inside `ChallengeReviewService::review()` after all individual challenges are processed.

For each destination that had at least one active challenge today:
1. Separate challenges into completed and failed.
2. **If `failed_count === 0`**: Skip recap entirely for this destination. Do NOT create any recap notification.
3. If `failed_count > 0`:
   - Sum `failed_days` across the destination's challenges (optional, for payload context).
   - Create a single `ChallengeNotification(type='recap', destination_challenge_id=null)` with payload containing:
     - `destination_id`
     - `failed_count`
     - `failed_challenges`: Array of objects, each with:
       - `description` (from `ChallengeDescriptionService`, reflecting incremented `current_requirement`)
       - `progress` (`current_progress`)
       - `requirement` (`current_requirement` after increment)

The existing `ChallengeNotificationDispatcher` already handles `destination_challenge_id = null` notifications by loading the destination from payload — no dispatcher changes needed.

---

### Deliverable 5: Artisan Command

Create `app/Console/Commands/ReviewDailyChallenges.php`:

```
php artisan make:command ReviewDailyChallenges --command=challenges:review-daily
```

- Inject `ChallengeReviewService`.
- Call `review()`, output summary:
  ```
  Reviewed: 12
  Incremented: 8
  Failed: 4
  Recap destinations: 3
  ```
- Log summary via `Log::info`.

---

### Deliverable 6: Scheduler Registration

In `routes/console.php`, add after the existing `challenges:assign-daily` schedule:

```php
Schedule::command('challenges:review-daily')
    ->dailyAt(config('dota.daily_challenge.review_time', '23:00'))
    ->timezone(config('dota.daily_challenge.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();
```

Uses the existing `review_time` config key (`23:00`).

---

### Deliverable 7: Tests

All tests use Pest with `RefreshDatabase`. Follow existing test patterns in `tests/Feature/AssignDailyChallengesCommandTest.php` and `tests/Feature/ChallengeProgressServiceTest.php`.

#### ChallengeReviewServiceTest (`tests/Feature/ChallengeReviewServiceTest.php`)

| # | Test | What It Verifies |
|---|---|---|
| 1 | Accumulative challenge not completed → requirement incremented | `current_requirement` increases by `increment_value`, capped at `max_requirement` |
| 2 | Accumulative challenge at max → not incremented beyond cap | `current_requirement` stays at `max_requirement` |
| 3 | Snapshot challenge not completed → requirement unchanged | `current_requirement` stays same (increment_value=0) |
| 4 | Completed challenge → skipped entirely | No changes to completed challenge |
| 5 | failed_days incremented on each review | `failed_days` increments by 1 |
| 6 | Incremented event created | ChallengeEvent(type='incremented') with correct before/after |
| 7 | failed_review event created | ChallengeEvent(type='failed_review') with correct before/after values |
| 8 | Recap notification created for destination with failures | ChallengeNotification(type='recap') with failed challenge details |
| 9 | No recap when all challenges completed | No ChallengeNotification(type='recap') created for all-completed destination |
| 10 | Multiple destinations → separate recaps | Each destination gets its own recap, only if they have failures |
| 11 | Review idempotent (same day) | Running twice doesn't double-increment |

#### ChallengeMessageRendererTest additions (`tests/Feature/ChallengeMessageRendererTest.php`)

| # | Test |
|---|---|
| 11 | `recap` renders correct punishment format | Contains `🪦`, `gak selesai`, `Sebagai hukuman`, `ditambah 👺`, progress in `(x/y selesai)` format |
| 12 | `recap` encouragement varies by failed count (1) | `Masih bisa dikejar besok.` |
| 13 | `recap` encouragement varies by failed count (4+) | `Evaluasi strategi kalian.` |

#### ReviewDailyChallengesCommandTest (`tests/Feature/ReviewDailyChallengesCommandTest.php`)

| # | Test |
|---|---|
| 14 | Command runs and outputs summary |
| 15 | Command handles empty queue (no active challenges) gracefully |
| 16 | Recap not created when all challenges completed | No recap notification generated, recap_destinations count is 0 |

---

### Deliverable 8: ChallengeNotificationDispatcher — Add `recap` Support

The dispatcher already handles `assigned_announcement`, `completed`, and `backlog_full`. Add `recap` to the processing:

- `recap`: Process individually (same as `backlog_full` — loads destination from payload since `destination_challenge_id` is null). NOT batched.

The renderer's `render()` method should already handle this type after Deliverable 3 — the dispatcher just needs to route it through.

---

## Important Constraints

### DO Implement
- `ChallengeReviewService` with full increment/failed_days logic
- `recap` notifications per destination (only when there are failures)
- `challenges:review-daily` Artisan command
- Scheduler registration at 23:00 Asia/Jakarta
- Extend `ChallengeMessageRenderer` for `recap` notification type
- Extend `ChallengeNotificationDispatcher` to route `recap` notifications
- All tests listed above

### Do NOT Implement
- OpenDota delay handling (Step 6)
- New challenge evaluators
- Changes to `ChallengeAssignmentService` or `ChallengeProgressService` (unless a bug blocks review)
- Changes to `DestinationMessageService`, `ChallengeDescriptionService`, or the messaging pipeline
- Any UI/Filament changes

### Conventions
- Use `php artisan make:` commands with `--no-interaction`
- Constructor property promotion for injected dependencies
- Explicit return type declarations on all methods
- `casts()` method on models (not `$casts` property)
- Eloquent over raw queries; eager-load relationships to prevent N+1
- Pest tests with `RefreshDatabase`
- Bahasa Indonesia for all user-facing message strings
- Run `vendor/bin/pint --dirty` before finalizing
- Run tests with `php artisan test --compact --filter=<TestFile>`
