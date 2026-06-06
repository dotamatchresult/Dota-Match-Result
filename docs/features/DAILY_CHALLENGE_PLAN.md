# **Step 1: Database Design** ✅ Implemented

### **1.1 Create `challenges` table (challenge pool)**

Table: `challenges`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | auto-increment |
| `code` | string (unique) | Machine-readable evaluator key (e.g. `total_kills`, `zero_death_win`) |
| `name` | string | Display name |
| `description` | text | Announcement text with `{requirement}` placeholder |
| `category` | string (nullable) | `accumulative` or `snapshot` |
| `base_requirement` | unsigned integer | Starting threshold |
| `increment_value` | unsigned integer | Growth on failure (default 0; always 0 for snapshots) |
| `max_requirement` | unsigned integer | Cap to prevent runaway targets |
| `configuration` | json (nullable) | Type-specific config (e.g. `{"item_id": 116}` for item_win) |
| `is_active` | boolean | Soft-disable from pool (default true) |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Indexes: unique(`code`), composite(`is_active`, `category`)

### **1.2 Create `destination_challenges` table**

Table: `destination_challenges`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | auto-increment |
| `destination_id` | FK → destinations.id | cascade on delete |
| `challenge_id` | FK → challenges.id | cascade on delete |
| `assigned_date` | date | Day the challenge was generated (00:00) |
| `status` | string | `active` or `completed` (default `active`) |
| `current_requirement` | unsigned integer | Current threshold (may have been incremented) |
| `progress_data` | json (nullable) | Accumulated progress across matches |
| `failed_days` | unsigned integer | Count of consecutive 23:00 reviews where incomplete (default 0) |
| `completed_at` | timestamp (nullable) | When challenge was finished |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Indexes: unique(`destination_id`, `challenge_id`, `assigned_date`), composite(`destination_id`, `status`), single(`status`)

### **1.3 Create `challenge_events` table (audit log)**

Table: `challenge_events` — append-only; no updated_at
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | auto-increment |
| `destination_challenge_id` | FK → destination_challenges.id | cascade on delete |
| `match_id` | FK → dota_matches.id (nullable) | nullOnDelete; null for non-match events (assigned, incremented) |
| `type` | string | `assigned`, `progress`, `incremented`, `completed`, `failed_review` |
| `value_before` | integer (nullable) | Progress before event |
| `value_after` | integer (nullable) | Progress after event |
| `payload` | json (nullable) | Additional context (e.g. which metric changed) |
| `created_at` | timestamp | default CURRENT_TIMESTAMP |

Indexes: composite(`destination_challenge_id`, `type`), composite(`match_id`, `destination_challenge_id`), **unique**(`destination_challenge_id`, `match_id`, `type`) — prevents duplicate match contributions

### **1.4 Create `challenge_notifications` table**

Table: `challenge_notifications` — tracks scheduled and sent messages
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | auto-increment |
| `destination_challenge_id` | FK → destination_challenges.id (nullable) | cascade on delete; nullable for `backlog_full` notifications |
| `type` | string | `assigned_announcement`, `completed`, `failed_review`, `progress_milestone`, `backlog_full`, `recap` |
| `scheduled_at` | timestamp (nullable) | When notification should be sent (for deferred reviews) |
| `sent_at` | timestamp (nullable) | When actually delivered |
| `status` | string | `pending`, `sent`, `cancelled` (default `pending`) |
| `payload` | json (nullable) | Message content snapshot for audit |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Indexes: composite(`destination_challenge_id`, `type`, `status`), composite(`status`, `scheduled_at`)

> **Step 3 migration**: `destination_challenge_id` made nullable via `2026_06_05_012003_make_destination_challenge_id_nullable_on_challenge_notifications` to support `backlog_full` notifications.

### **1.5 Config in `config/dota.php`**

```php
'daily_challenge' => [
    'enabled' => env('DAILY_CHALLENGE_ENABLED', true),
    'max_active_per_destination' => 5,
    'assignment_time' => '08:00',
    'announcement_time' => '08:00',
    'review_time' => '23:00',
    'timezone' => 'Asia/Jakarta',
    'assignment_history_days' => 14,
    'review_force_after_hours' => 12,
    'evaluators' => [
        'total_kills' => 'total_kills',
        'total_denies' => 'total_denies',
        'total_heal' => 'total_heal',
        'hero_win' => 'hero_win',
        'item_win' => \App\Services\ChallengeEvaluators\ItemWinEvaluator::class,
        'last_hits' => 'last_hits',
        'zero_death_win' => 'zero_death_win',
        'fast_win' => 'fast_win',
    ],
],
```

---

# **Step 2: Models and Relationships** ✅ Implemented

### **2.1 Models**

#### **Challenge** (`app/Models/Challenge.php`)

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    /** @use HasFactory<\Database\Factories\ChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'description', 'category',
        'base_requirement', 'increment_value', 'max_requirement',
        'configuration', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'is_active' => 'boolean',
            'base_requirement' => 'integer',
            'increment_value' => 'integer',
            'max_requirement' => 'integer',
        ];
    }

    public function destinationChallenges(): HasMany
    {
        return $this->hasMany(DestinationChallenge::class);
    }

    /**
     * Scope to only active challenges.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }
}
```

#### **DestinationChallenge** (`app/Models/DestinationChallenge.php`)

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DestinationChallenge extends Model
{
    /** @use HasFactory<\Database\Factories\DestinationChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'destination_id', 'challenge_id', 'assigned_date',
        'status', 'current_requirement', 'progress_data',
        'failed_days', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'progress_data' => 'array',
            'completed_at' => 'datetime',
            'current_requirement' => 'integer',
            'failed_days' => 'integer',
        ];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChallengeEvent::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ChallengeNotification::class);
    }
}
```

#### **ChallengeEvent** (`app/Models/ChallengeEvent.php`)

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeEvent extends Model
{
    /** @use HasFactory<\Database\Factories\ChallengeEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'destination_challenge_id', 'match_id', 'type',
        'value_before', 'value_after', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'value_before' => 'integer',
            'value_after' => 'integer',
        ];
    }

    public function destinationChallenge(): BelongsTo
    {
        return $this->belongsTo(DestinationChallenge::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(DotaMatch::class, 'match_id');
    }
}
```

#### **ChallengeNotification** (`app/Models/ChallengeNotification.php`)

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeNotification extends Model
{
    /** @use HasFactory<\Database\Factories\ChallengeNotificationFactory> */
    use HasFactory;

    protected $fillable = [
        'destination_challenge_id', 'type',
        'scheduled_at', 'sent_at', 'status', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function destinationChallenge(): BelongsTo
    {
        return $this->belongsTo(DestinationChallenge::class);
    }
}
```

#### **Destination** (updated — added to `app/Models/Destination.php`)

```php
public function destinationChallenges(): HasMany
{
    return $this->hasMany(DestinationChallenge::class);
}
```

### **2.2 Relationships Summary**

| Model | Relationship | Target |
|-------|-------------|--------|
| `Challenge` | hasMany | `DestinationChallenge` |
| `Destination` | hasMany | `DestinationChallenge` |
| `DestinationChallenge` | belongsTo | `Challenge`, `Destination` |
| `DestinationChallenge` | hasMany | `ChallengeEvent`, `ChallengeNotification` |
| `ChallengeEvent` | belongsTo | `DestinationChallenge`, `DotaMatch` (nullable) |
| `ChallengeNotification` | belongsTo | `DestinationChallenge` |

### **2.3 Additional Deliverables**

- **`ChallengeSeeder`** (`database/seeders/ChallengeSeeder.php`): Seeds 8 initial challenge templates:
  - 5 accumulative: `total_kills` (30→60), `total_denies` (20→40), `total_heal` (10k→20k), `hero_win` (1→3), `item_win` (1→3)
  - 3 snapshot: `last_hits` (60), `zero_death_win` (1), `fast_win` (25 min)
  - Registered in `DatabaseSeeder`
- **4 Factories**: `ChallengeFactory`, `DestinationChallengeFactory` (states: `completed()`, `withProgress()`), `ChallengeEventFactory` (states: `forMatch()`, `ofType()`), `ChallengeNotificationFactory` (states: `scheduled()`, `sent()`, `cancelled()`)

---

# Step 3: Challenge Assignment Engine ✅ Implemented

### **3.1 At 00:00 – Generate daily challenges** ✅

Implemented via `challenges:assign-daily` Artisan command + `ChallengeAssignmentService`.

**Files created:**
- `app/Services/DailyChallenge/ChallengeAssignmentService.php` — Core assignment logic
- `app/Console/Commands/AssignDailyChallenges.php` — Artisan command

**Scheduler** (in `routes/console.php`):
```php
Schedule::command('challenges:assign-daily')
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->runInBackground();
```

**Business rules implemented:**
- **Max active limit** (5 per destination): Skips assignment and creates `backlog_full` notification when at capacity
- **Daily idempotency**: Checks `(destination_id, assigned_date)` before assignment — never double-assigns
- **14-day cooldown**: Prefers challenges not assigned to the destination in the last `assignment_history_days` days
- **Pool exhaustion fallback**: If all active challenges are within cooldown, falls back to any active challenge
- **Random selection**: Uses `inRandomOrder()` among eligible candidates
- **Item win randomization**: For `item_win` challenges, picks a random item with cost ≥ 4000 from the `items` table at assignment time and stores `item_id` in `DestinationChallenge.progress_data.metadata`
- **Hero win randomization**: For `hero_win` challenges, picks a random hero from the `heroes` table at assignment time and stores `hero_id` in `DestinationChallenge.progress_data.metadata` (added during Step 5 — needed for description rendering)
- **DB transaction**: Creates `DestinationChallenge` + `ChallengeEvent` (type: `assigned`) + `ChallengeNotification` (type: `assigned_announcement`) atomically
- **Chunked iteration**: Processes destinations in chunks of 100 via `chunkById()`
- **Structured logging**: Logs all outcomes (assigned, skipped-idempotency, skipped-limit, skipped-no-challenge, errors)

**Key design decision**: Uses `whereDate('assigned_date', ...)` instead of `where('assigned_date', ...)` for cross-database compatibility (SQLite tests store date-cast values with time component).

### **3.2 At 08:00 – Announce challenge** ⏳ Queue Only

- `ChallengeNotification` records are created with `type=assigned_announcement`, `status=pending`, `scheduled_at=08:00 Asia/Jakarta`
- Actual message sending (Fonnte/Telegram) is deferred to Step 5

### **3.3 During the day – Track progress** 🔜 Step 4

Not yet implemented. Will hook into match fetch pipeline in Step 4.

### **3.4 At 23:00 – End of day check** 🔜 Step 4

Not yet implemented. Review/increment logic deferred to Step 4.

### **3.5 Tests** ✅

`tests/Feature/AssignDailyChallengesCommandTest.php` — 8 tests, 47 assertions:
- Assignment created (with event + notification)
- Max active limit enforced (backlog notification created)
- Daily idempotency (run twice → one assignment)
- Cooldown logic (recently assigned challenges excluded)
- Pool exhaustion fallback (succeeds when all within cooldown)
- Disabled config exits early
- No active challenges handled gracefully
- Multiple destinations each receive assignment

---

# Step 4: Challenge Logic

### **4.1 Progress calculation**

* Store JSON like:

```json
{
    "members": {
        "account_id_1": {"kills": 10, "wins": 1},
        "account_id_2": {"kills": 8, "wins": 1}
    },
    "team_total": {"kills": 18, "last_hits": 120}
}
```

* This allows additive progress for multi-day completion

### **4.2 Increment logic**

* Only increment at 23:00 check if not completed
* Do **not** increment if OpenDota match result delayed > 30 min
* Cap at max_requirement to prevent runaway targets

---

# **Step 5: Notification Delivery System** ✅ Implemented

### **5.1 Architecture**

Transport-agnostic notification delivery pipeline that reads pending `ChallengeNotification` records, batches completions by destination, renders formatted messages in **Bahasa Indonesia**, and routes through existing `TelegramService`/`FonnteService`.

**Pipeline flow:**

```
ChallengeNotification (status=pending)
    ↓
ChallengeNotificationDispatcher (group, batch, orchestrate)
    ↓
ChallengeMessageRenderer (format message)
    ↓
DestinationMessageService (route by transport)
    ↓
TelegramService / FonnteService
    ↓
status=sent, sent_at=now()
```

**Files created:**
- `app/Services/Messaging/DestinationMessageService.php` — Thin transport router (whatsapp → Fonnte, telegram → Telegram)
- `app/Services/DailyChallenge/ChallengeDescriptionService.php` — Human-readable challenge descriptions in Bahasa Indonesia
- `app/Services/DailyChallenge/ChallengeMessageRenderer.php` — Formatted message rendering for each notification type
- `app/Services/DailyChallenge/ChallengeNotificationDispatcher.php` — Orchestrator: groups, batches, renders, sends, marks sent
- `app/Console/Commands/SendChallengeNotifications.php` — `challenges:send-notifications` artisan command

**Scheduler** (in `routes/console.php`):
```php
Schedule::command('challenges:send-notifications')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
```

### **5.2 ChallengeDescriptionService**

Generates human-readable challenge descriptions from `DestinationChallenge` data. Uses `progress_data['metadata']` (set by evaluator) as primary source, falling back to `challenge.configuration`.

**Templates (Bahasa Indonesia):**

| Code | Template |
|---|---|
| `hero_win` | `Menangkan {n} pertandingan menggunakan {hero_name}` |
| `item_win` | `Menangkan {n} pertandingan dengan membawa {item_name}` |
| `total_kills` | `Dapatkan {n} total kill` |
| `total_denies` | `Dapatkan {n} total deny` |
| `total_heal` | `Pulihkan {n} HP` (with `number_format`) |
| `last_hits` | `Dapatkan {n} last hit dalam satu pertandingan` |
| `zero_death_win` | `Menangkan pertandingan tanpa mati` |
| `fast_win` | `Menangkan pertandingan dalam waktu kurang dari {n} menit` |
| default | `$challenge->description` with `{requirement}` replaced |

**Dynamic value resolution:**
- `hero_name`: Looks up `Hero` by `hero_id`, prefers `localized_name`, falls back to `name`, then `"Hero yang ditentukan"`
- `item_name`: Looks up `Item` by `item_id`, prefers `dname`, falls back to `name`, then `"Item yang ditentukan"`
- Indonesian has no plural noun forms, so `"1 pertandingan"` and `"2 pertandingan"` are both naturally correct

### **5.3 ChallengeMessageRenderer**

Formats notification messages. Supports single notifications via `render()` and batched completions via `renderBatch()`.

**`assigned_announcement`:**
```
🎯 TANTANGAN HARIAN

{description}

Progress:
{current_progress} / {current_requirement}

Semoga beruntung.
```

**`completed` (single):**
```
🎉 TANTANGAN SELESAI

✅ {description}

Kerja bagus.
```

**`completed` (multi — 2+ notifications):**
```
🎉 TANTANGAN SELESAI

Tim kamu menyelesaikan {count} tantangan:

✅ {description_1}
✅ {description_2}
✅ {description_3}

Teruskan.
```

**`backlog_full`:**
```
📚 TANTANGAN MENUMPUK

Kamu sudah punya {max} tantangan aktif.

Selesaikan dulu yang ada.
```

### **5.4 ChallengeNotificationDispatcher**

Orchestrator: queries pending notifications, groups `completed` by destination, respects `scheduled_at` for `assigned_announcement`, handles `backlog_full` (loads destination from payload's `destination_id` since `destination_challenge_id` is null).

**Batching rules:**
- All `completed` notifications for the same destination are batched into a single message
- If only 1 completed notification exists, still uses single-completion format (not multi)
- Other notification types (`assigned_announcement`, `backlog_full`) are always sent individually
- On success: marks all affected notifications `status=sent, sent_at=now()`
- On failure: leaves all as `status=pending`

### **5.5 DestinationMessageService**

Thin routing layer. Resolves transport from `$destination->code`:
- `whatsapp` → `FonnteService::sendMessage($destination->target, $message)`
- `telegram` → `TelegramService::sendMessage($message, 'default', $destination->target)`
- Other → throws `InvalidArgumentException`

No new transport abstractions — existing services already handle format conversion, token resolution, and `message_enabled` toggle.

### **5.6 Command Output**

```
Processed: 14
Sent: 12
Batched: 3 groups
Failed: 0
```

### **5.7 Cross-Step Changes**

- **Step 3 (`ChallengeAssignmentService`)**: Added `hero_win` randomization in `resolveRuntimeConfig()` — picks a random hero from the `heroes` table at assignment time, mirroring the existing `item_win` pattern. Required for description rendering to name the specific hero in the 08:00 announcement.

### **5.8 Tests**

`tests/Feature/ChallengeDescriptionServiceTest.php` — 13 tests:
- All 8 challenge code templates
- Metadata priority over configuration
- Fallback when hero/item not found
- Number formatting for `total_heal`

`tests/Feature/ChallengeMessageRendererTest.php` — 5 tests:
- `assigned_announcement`, `backlog_full`, single `completed`, multi `completed` (3 items), single item via `renderBatch`

`tests/Feature/ChallengeNotificationDispatcherTest.php` — 8 tests:
- Successful send marks sent, failed send remains pending
- Completed batching (same destination → one message, different destinations → separate)
- `scheduled_at` respected (future → skipped, past → sent)
- `backlog_full` via destination from payload
- Summary counts

`tests/Feature/SendChallengeNotificationsCommandTest.php` — 2 tests:
- Command processes pending and outputs summary
- Empty queue handled gracefully

### **5.9 End-of-Day Review & Recap Notifications** ✅ Implemented

**Files created:**
- `app/Services/DailyChallenge/ChallengeReviewService.php` — Core review logic
- `app/Console/Commands/ReviewDailyChallenges.php` — `challenges:review-daily` Artisan command

**Files modified:**
- `app/Services/DailyChallenge/ChallengeMessageRenderer.php` — Added `recap` notification type
- `app/Services/DailyChallenge/ChallengeNotificationDispatcher.php` — Renamed `$backlogNotifications` → `$nullDcNotifications` to handle both `backlog_full` and `recap` (both have null `destination_challenge_id`)
- `routes/console.php` — Added scheduler entry at 23:00 Asia/Jakarta

**Scheduler** (in `routes/console.php`):
```php
Schedule::command('challenges:review-daily')
    ->dailyAt(config('dota.daily_challenge.review_time', '23:00'))
    ->timezone(config('dota.daily_challenge.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();
```

**Business rules implemented:**
- **23:00 review**: Processes all active challenges assigned today
- **Increment logic**: For incomplete accumulative challenges, bumps `current_requirement` by `increment_value`, capped at `max_requirement`. Snapshot challenges (`increment_value=0`) keep same requirement.
- **failed_days tracking**: Increments `failed_days` for ALL incomplete challenges (both accumulative and snapshot)
- **Audit events**: Creates `ChallengeEvent(type='incremented')` and `ChallengeEvent(type='failed_review')` for audit trail
- **Recap notification**: Creates one `ChallengeNotification(type='recap', destination_challenge_id=null)` per destination that has failures. Skips recap entirely if all challenges completed.
- **DB transactions**: Each challenge's updates are wrapped in `DB::transaction()` for atomicity
- **Idempotency**: Uses `whereDoesntHave('events', ...)` filter — second run on same day is a true no-op
- **Chunked iteration**: Processes challenges in chunks of 100 via `chunkById()`

**Recap message format (Bahasa Indonesia):**
```
🪦 {failed_count} tantangan gak selesai

{encouragement}

Sebagai hukuman, tantangan kalian ditambah 👺:
- {description_1} ({progress_1}/{req_1} selesai)
- {description_2} ({progress_2}/{req_2} selesai)
```

Encouragement varies by failed count: 1 → `Masih bisa dikejar besok.`, 2-3 → `Yuk lebih fokus besok.`, 4+ → `Evaluasi strategi kalian.`

**Command output:**
```
Reviewed: 12
Incremented: 8
Failed: 4
Recap destinations: 3
```

**Tests:**

`tests/Feature/ChallengeReviewServiceTest.php` — 11 tests, 52 assertions:
- Accumulative challenge increment (with cap at `max_requirement`)
- Snapshot challenge requirement unchanged
- Completed challenge skipped entirely
- `failed_days` incremented on each review
- `incremented` and `failed_review` events created with correct before/after values
- Recap notification created with payload structure (`destination_id`, `failed_count`, `failed_challenges`)
- No recap when all challenges completed
- Multiple destinations → separate recaps only if failures exist
- Review idempotent (second run on same day is a no-op)

`tests/Feature/ChallengeMessageRendererTest.php` — 3 new recap tests:
- Punishment format (🪦, `gak selesai`, `Sebagai hukuman`, `ditambah 👺`, `(x/y selesai)`)
- Encouragement varies by failed count (1 → `Masih bisa dikejar besok.`, 4+ → `Evaluasi strategi kalian.`)

`tests/Feature/ReviewDailyChallengesCommandTest.php` — 3 tests:
- Command runs and outputs summary with all four keys
- Empty queue handled gracefully (all zeros)
- No recap notification generated when all challenges completed

---

# **Step 6: Handling OpenDota Delays** ✅ Implemented

### **6.1 Problem**

Steam detects a match before OpenDota provides detailed data. At 23:00 review time, a match that finished near the cutoff may exist in Steam but lack OpenDota results — causing false review failures when the match would have fulfilled challenge requirements.

```
22:45 match starts
23:05 match ends

Steam knows match exists
OpenDota still unavailable

23:00 review runs → challenge incorrectly fails
23:20 OpenDota result arrives → challenge should have completed
```

### **6.2 Updated Business Rules**

Challenge assignment moved from `00:00` to `08:00` Asia/Jakarta:

```
08:00       new challenge assigned
08:00-23:00 challenge window
23:00       review
23:00-08:00 free session
```

### **6.3 Config Changes** (`config/dota.php`)

```php
'daily_challenge' => [
    'assignment_time' => '08:00',     // was '00:00'
    'review_force_after_hours' => 12,  // new
    // ...
],
```

### **6.4 `finished_at` Column on `dota_matches`**

**Migration** (`database/migrations/..._add_finished_at_to_dota_matches_table.php`):
- Added `finished_at` (timestamp, nullable) after `match_timestamp`
- Backfill: `match_timestamp + duration` (seconds from `match_data.duration`)
- MySQL: `DATE_ADD(match_timestamp, INTERVAL JSON_UNQUOTE(JSON_EXTRACT(...)) SECOND)`
- SQLite: `datetime(match_timestamp, '+' || CAST(JSON_EXTRACT(...) AS INTEGER) || ' seconds')`

**DotaMatch model** — added `'finished_at'` to `$fillable` and `casts()` as `'datetime'`.

**CheckMatchesCommand** — `calculateFinishedAt()` helper computes `start_time + duration` for new matches.

### **6.5 ReviewDecision DTO**

**File:** `app/DataObjects/Challenges/ReviewDecision.php`

```php
final readonly class ReviewDecision
{
    public bool $allowed;
    public bool $blocked;
    public bool $forceReview;
    public array $blockingMatches;

    static allowed(): self;
    static blocked(array $matchIds): self;
    static forceReview(array $matchIds): self;
}
```

### **6.6 ChallengeReviewGuardService**

**File:** `app/Services/DailyChallenge/ChallengeReviewGuardService.php`

`canReviewDestination(Destination $destination): ReviewDecision`

**Logic:**
1. Find member IDs for destination via `destinationConfig` relationship
2. Query `dota_matches` where: member JSON overlap, `finished_at < now()-1h`, `parse_status = 'pending'`
3. No blocking matches → `allowed()`
4. If `review_delayed` notification exists for this destination today AND `created_at < now()-12h` → `forceReview(matchIds)`
5. Otherwise → `blocked(matchIds)`

**JSON overlap query:** `DB::getDriverName()` dispatch — MySQL: `whereJsonContains('members', $id)`, SQLite: `where('members', 'like', '%'.$id.'%')`.

### **6.7 ChallengeReviewService Modifications**

**Constructor** now injects `ChallengeReviewGuardService`.

**`review()` flow:**
```
For each destination with active challenges:
    → ChallengeReviewGuardService.canReviewDestination($dest)
        ├─ blocked  → create review_delayed notification (dedup), skip, deferred++
        ├─ forceReview → create review_forced event per challenge, proceed, forced++
        └─ allowed  → proceed
```

**`reviewDestination(Destination): array`** — public method reused by deferred review command.

**`createDelayNotification()`** — deduplication: one `review_delayed` notification per destination per day.

**New return keys:** `deferred`, `forced`.

### **6.8 Deferred Review Processing**

**Scheduler** (`routes/console.php`):
```php
Schedule::command('challenges:process-deferred-reviews')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
```

**Command:** `app/Console/Commands/ProcessDeferredReviews.php` (`challenges:process-deferred-reviews`)

Finds pending `review_delayed` notifications (status=pending, today). For each destination:
- Calls guard → if still blocked, skip; if force, create `review_forced` events
- Runs `ChallengeReviewService::reviewDestination()`
- Marks notification `status=sent`

Output: `Processed`, `Resolved`, `Forced`, `Still blocked`.

### **6.9 Notification Rendering**

Added `review_delayed` case to `ChallengeMessageRenderer::render()`:

```
⏳ Review tantangan hari ini ditunda.

Masih ada pertandingan yang belum diproses OpenDota.
Kami akan mengecek ulang secara otomatis setelah hasil pertandingan tersedia.
```

### **6.10 `review_forced` Audit Events**

One `ChallengeEvent(type='review_forced')` per active challenge when force threshold exceeded. Payload carries `reason` and `blocking_match_ids`.

### **6.11 Files Summary**

**Created (4):**
- `app/DataObjects/Challenges/ReviewDecision.php`
- `app/Services/DailyChallenge/ChallengeReviewGuardService.php`
- `app/Console/Commands/ProcessDeferredReviews.php`
- `database/migrations/..._add_finished_at_to_dota_matches_table.php`

**Modified (8):**
- `config/dota.php`, `routes/console.php`
- `app/Models/DotaMatch.php`, `app/Console/Commands/CheckMatchesCommand.php`
- `app/Services/DailyChallenge/ChallengeReviewService.php`
- `app/Console/Commands/ReviewDailyChallenges.php`
- `app/Services/DailyChallenge/ChallengeMessageRenderer.php`

### **6.12 Tests**

`tests/Feature/ChallengeReviewGuardServiceTest.php` — 9 tests:
- Normal review when no delayed matches, no members
- Blocked when `parse_status=pending` & `finished_at > 1h`
- Not blocked when match < 1h old, `parse_status=parsed`, or `parse_status=null`
- Force review after threshold (13h-old delay notification)
- Force not triggered within threshold (5h-old)
- Multiple blocking matches returned

`tests/Feature/ChallengeReviewServiceTest.php` — 5 new guard-integration tests:
- `review_delayed` notification created when blocked
- Increment/failed_days/recap skipped when blocked
- `review_forced` events created + review proceeds when forceReview
- Duplicate delay notifications not created (dedup)
- Recap still created for non-blocked destinations

`tests/Feature/ProcessDeferredReviewsCommandTest.php` — 5 tests:
- Succeeds after match resolution (parse_status=parsed)
- Stays blocked when match still pending
- Force review via deferred command after threshold
- Empty queue handled gracefully
- Notification marked sent after processing

`tests/Feature/ChallengeMessageRendererTest.php` — 1 new test:
- `review_delayed` renders correct message format

---

# **Step 7: Seeder for Challenge Pool**

* Seed initial challenges to DB
* Optional: maintain a static PHP array if you prefer immutable pool

---

# **Step 8: Testing**

1. Create test destinations and members
2. Seed challenge pool
3. Simulate matches using your `match_result_parsed.json` and `match_result_unparsed.json`
4. Validate progress updates, increments, and messaging
