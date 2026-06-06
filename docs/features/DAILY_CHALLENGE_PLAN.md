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
| `weight` | unsigned integer | Selection weight for random assignment (default 10) |
| `configuration` | json (nullable) | Type-specific config (e.g. `{"item_id": 116}` for item_win) |
| `is_active` | boolean | Soft-disable from pool (default true) |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Indexes: unique(`code`), composite(`is_active`, `category`), single(`weight`)

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
        'weight', 'configuration', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'is_active' => 'boolean',
            'base_requirement' => 'integer',
            'increment_value' => 'integer',
            'max_requirement' => 'integer',
            'weight' => 'integer',
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
- **Weighted random selection**: Uses `selectWeightedRandom()` — higher-weight challenges are assigned more frequently (added in Step 7)
- **Active code exclusion**: Challenges with the same `code` already active on the destination are excluded from the candidate pool (added in Step 7)
- **Item win randomization**: For `item_win` challenges, picks a random item with cost ≥ 4000 from the `items` table at assignment time and stores `item_id` in `DestinationChallenge.progress_data.metadata`
- **Hero win randomization**: For `hero_win` challenges, picks a random hero from the `heroes` table at assignment time, respecting `configuration.excluded_heroes` from the catalog (e.g., excludes Meepo, hero_id 82) — stores `hero_id` in `DestinationChallenge.progress_data.metadata` (added during Step 5; hero restrictions added in Step 7)
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

# **Step 7: Challenge Catalog System** ✅ Implemented

### **7.1 Challenge Catalog**

Move challenge definitions from inline seeder to a canonical PHP catalog.

**Files created:**
- `app/Support/DailyChallenge/ChallengeCatalog.php` — Canonical source of truth for all challenge templates
- `app/Support/DailyChallenge/ChallengeCatalogValidator.php` — Validates every catalog entry before use
- `docs/challenge-authoring.md` — Authoring guide: allowed/forbidden metrics, design rules
- `database/migrations/..._add_weight_to_challenges_table.php` — Adds `weight` column

**Files modified:**
- `app/Models/Challenge.php` — Added `weight` to `$fillable` and `casts()`
- `database/factories/ChallengeFactory.php` — Added `weight` to default state
- `database/seeders/ChallengeSeeder.php` — Refactored to source from `ChallengeCatalog::definitions()` via `updateOrCreate()`
- `app/Services/DailyChallenge/ChallengeAssignmentService.php` — Weighted selection, active code exclusion, hero restrictions

### **7.2 Challenge Weight**

**Migration**: `challenges.weight` — unsigned integer, default 10, indexed.

Used by `selectWeightedRandom()` to bias assignment toward higher-weight challenges.

**Suggested weights:**
| Code | Weight | Rationale |
|---|---|---|
| `hero_win` | 15 | Core gameplay, always relevant |
| `total_kills` | 15 | Core gameplay, always relevant |
| `item_win` | 10 | Common |
| `total_denies` | 10 | Common |
| `total_heal` | 8 | Normal |
| `last_hits` | 8 | Normal |
| `fast_win` | 5 | Situational |
| `zero_death_win` | 2 | Very hard to achieve |

### **7.3 Challenge Catalog**

**`ChallengeCatalog::definitions()`** returns all 8 challenge definitions with weights, categories, and type-specific `configuration`.

The catalog is the canonical source of truth. The database is only a synchronized runtime representation. The seeder uses `updateOrCreate()` by `code` for idempotency — safe to run multiple times, updates existing records, creates missing records, and does NOT delete extra rows.

**`hero_win` configuration:**
```php
'configuration' => [
    'random_hero' => true,
    'excluded_heroes' => [
        82, // Meepo
    ],
],
```

### **7.4 Catalog Validator**

**`ChallengeCatalogValidator::validate(array $definitions)`** — called automatically by `definitions()` before returning.

**Validation rules:**
- Required keys: `code`, `name`, `description`, `weight`, `base_requirement`, `increment_value`, `max_requirement`
- `weight >= 1`
- `base_requirement <= max_requirement`
- `code` must be unique across all entries

Throws `InvalidArgumentException` with descriptive message on failure.

### **7.5 Assignment Engine Improvements**

**Weighted random selection** (`selectWeightedRandom()`):
- Sums all candidate weights, picks a random float in `[0, totalWeight)`, iterates accumulating weight until threshold exceeded
- Uses `mt_rand()` for deterministic testability
- Applied to both cooldown-preference and fallback query paths

**Active code exclusion:**
- Gathers distinct `code` values from `DestinationChallenge` where `status='active'` for the destination
- Excludes them via `whereNotIn('code', ...)` in both candidate queries
- If no candidates remain after exclusion, returns null (skips assignment)

**Hero restrictions via catalog:**
- In `resolveRuntimeConfig()`, reads `configuration.excluded_heroes` from the challenge
- Filters Hero query with `whereNotIn('hero_id', $excludedHeroes)`
- Only Meepo (82) is excluded per current catalog configuration

### **7.6 Documentation**

**`docs/challenge-authoring.md`** documents:
- **Allowed metrics**: kills, assists, deaths, wins, hero_id, item_id, last_hits, denies, hero_healing, duration
- **Forbidden metrics**: roshan, wards, sentries, courier kills, damage types, skill usage, item activations, stun duration, silence duration
- **Design rules**: Turbo compatible, achievable in 1–3 matches, no griefing/feeding/AFK incentives
- Weight guidelines by frequency tier

### **7.7 Tests**

`tests/Feature/ChallengeCatalogTest.php` — 11 tests, 149 assertions:
- All 8 codes present, required keys, valid weights, valid requirements
- `hero_win` has `excluded_heroes` in configuration, catalog uses suggested weights
- Validator passes valid definitions, rejects: missing keys, weight < 1, base > max, duplicate codes

`tests/Feature/ChallengeSeederTest.php` — 4 tests, 15 assertions:
- Creates missing challenges (0 → 8)
- Updates existing challenges (old data → catalog values)
- Idempotent (run twice, same count)
- Does not delete extra rows (8 catalog + 1 extra = 9)

`tests/Feature/AssignDailyChallengesCommandTest.php` — 12 tests (3 new), 156 assertions:
- Weighted selection favors higher-weight challenges (100 vs 1)
- Active code exclusion prevents re-assignment of same code
- Hero exclusion: hero_win never assigns Meepo (hero_id 82)
- All existing tests (assignment, max limit, idempotency, cooldown, fallback, disabled, no-active, multi-dest, item-win) still pass

---

# **Step 8: Evaluator Expansion & Metric Challenge Framework** ✅ Implemented

### **8.1 Overview**

Replaced 6 stub (no-op) string-based evaluator references with 5 reusable evaluator classes, a `MetricRegistry`, standardized `progress_data` structure, and catalog-driven configuration. Existing `HeroWinEvaluator` and `ItemWinEvaluator` remain untouched.

The system now supports configuration-driven challenge evaluation — future challenge types can be added primarily through catalog configuration rather than custom evaluator code.

### **8.2 Architecture**

```
MetricRegistry (authoritative metric list)
    ↓ validated by
ChallengeCatalogValidator (metric references)
    ↓ configured via
ChallengeCatalog (configuration.metric on each entry)
    ↓ resolved via
ChallengeEvaluatorRegistry → config/dota.php
    ↓ dispatches
ChallengeProgressService → mergeProgressData (standardized)
```

**Evaluator patterns implemented:**

| Pattern | Purpose | Example |
|---------|---------|---------|
| `AccumulativeTeamMetricEvaluator` | Sum metric across members, accumulate across matches | `total_kills`, `total_denies`, `total_heal` |
| `SingleMatchTeamMetricEvaluator` | Team total within a single match, tracks `best_attempt` | Available for future catalog entries |
| `SingleMatchIndividualMetricEvaluator` | Best individual performance in a match | `last_hits` |
| `FastWinEvaluator` | Win + duration ≤ requirement | `fast_win` |
| `ZeroDeathWinEvaluator` | Win + 0 deaths | `zero_death_win` |

### **8.3 MetricRegistry**

**File:** `app/Support/DailyChallenge/MetricRegistry.php`

Authoritative list of 11 available metrics sourced from unparsed OpenDota payload:

`kills`, `deaths`, `assists`, `last_hits`, `denies`, `hero_healing`, `hero_damage`, `tower_damage`, `net_worth`, `gold_per_min`, `xp_per_min`

```php
MetricRegistry::all();       // returns all 11 metrics
MetricRegistry::exists('kills'); // true
MetricRegistry::exists('rampages'); // false
```

### **8.4 Progress Data Standardization**

Updated `ChallengeProgressService::mergeProgressData()` to handle standardized structure:

```php
[
    'contributors' => [],     // member_id => accumulated value
    'matches' => [],          // deduplicated match IDs
    'best_attempt' => null,   // best single-match/individual value
    'best_member_id' => null, // member who achieved best_attempt
    'best_match_id' => null,  // match where best_attempt was achieved
    'metadata' => [],         // challenge-specific metadata
]
```

**Merge rules:**
- `contributors`: sum values for same member_id across matches
- `matches`: deduplicate array
- `best_attempt`: keep `max(existing, new)` — only update when new beats old
- `best_member_id`/`best_match_id`: update only when `best_attempt` improves
- `metadata`: shallow merge

Backwards compatible with all existing stored data via `??` fallbacks.

### **8.5 Generic Metric Evaluators**

#### AccumulativeTeamMetricEvaluator

**File:** `app/Services/ChallengeEvaluators/AccumulativeTeamMetricEvaluator.php`

Sums the configured metric across all participating members for each match. Progress accumulates via `current_progress += teamTotal`. Completion when `current_progress >= requirement`.

**Configuration:** `['metric' => 'kills']`

**Mapped to:** `total_kills` (kills), `total_denies` (denies), `total_heal` (hero_healing)

#### SingleMatchTeamMetricEvaluator

**File:** `app/Services/ChallengeEvaluators/SingleMatchTeamMetricEvaluator.php`

Sums metric across all members within a single match. Tracks `best_attempt`. Only reports progress when `teamTotal > previousBest`. `progressDelta = teamTotal - previousBest`.

**Configuration:** `['metric' => 'last_hits']`

**Not mapped to any existing challenge** — available for future catalog entries (e.g., "Get 150 total last hits as a team in one match").

#### SingleMatchIndividualMetricEvaluator

**File:** `app/Services/ChallengeEvaluators/SingleMatchIndividualMetricEvaluator.php`

Finds the highest individual metric value among members within a single match. Tracks `best_attempt`, `best_member_id`, `best_match_id`. Only reports progress when new best beats previous.

**Configuration:** `['metric' => 'last_hits']`

**Mapped to:** `last_hits`

### **8.6 Special Evaluators**

#### FastWinEvaluator

**File:** `app/Services/ChallengeEvaluators/FastWinEvaluator.php`

**Rules:**
- Any participating member's team must win the match
- `match_data.duration / 60 <= current_requirement` (in minutes)
- Duration exactly at requirement passes
- Idempotency: returns `matched: false` if `current_progress >= 1`

**Stores:** `metadata.duration_minutes`, `metadata.duration_seconds`

**Mapped to:** `fast_win`

#### ZeroDeathWinEvaluator

**File:** `app/Services/ChallengeEvaluators/ZeroDeathWinEvaluator.php`

**Rules:**
- Any participating member must win the match AND have `deaths == 0`
- Each qualifying member contributes +1
- Same pattern as `HeroWinEvaluator`/`ItemWinEvaluator`

**Mapped to:** `zero_death_win`

### **8.7 Evaluator Registry Integration**

Updated `config/dota.php` — all 8 evaluator codes now resolve to class references:

```php
'evaluators' => [
    'total_kills' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
    'total_denies' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
    'total_heal' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
    'hero_win' => \App\Services\ChallengeEvaluators\HeroWinEvaluator::class,
    'item_win' => \App\Services\ChallengeEvaluators\ItemWinEvaluator::class,
    'last_hits' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
    'zero_death_win' => \App\Services\ChallengeEvaluators\ZeroDeathWinEvaluator::class,
    'fast_win' => \App\Services\ChallengeEvaluators\FastWinEvaluator::class,
],
```

No switch statements. No changes to `ChallengeEvaluatorRegistry` — existing class-based resolution handles it.

### **8.8 Challenge Catalog Expansion**

Added `configuration.metric` to 4 catalog entries:

| Code | Metric | Evaluator |
|------|--------|-----------|
| `total_kills` | `kills` | `AccumulativeTeamMetricEvaluator` |
| `total_denies` | `denies` | `AccumulativeTeamMetricEvaluator` |
| `total_heal` | `hero_healing` | `AccumulativeTeamMetricEvaluator` |
| `last_hits` | `last_hits` | `SingleMatchIndividualMetricEvaluator` |

Existing codes (`hero_win`, `item_win`, `zero_death_win`, `fast_win`) retain their original configuration.

### **8.9 Catalog Validation**

Extended `ChallengeCatalogValidator` with metric validation:

- If `configuration.metric` is present, it must be a string
- The metric value must exist in `MetricRegistry`
- Throws `InvalidArgumentException` with descriptive message on failure

Example error: `"Invalid catalog entry 'total_kills': configuration.metric 'rampages' is not a valid metric."`

### **8.10 Files Summary**

**Created (12):**
- `app/Support/DailyChallenge/MetricRegistry.php` — Authoritative metric list
- `app/Services/ChallengeEvaluators/AccumulativeTeamMetricEvaluator.php` — Team-sum accumulative evaluator
- `app/Services/ChallengeEvaluators/SingleMatchTeamMetricEvaluator.php` — Team-sum single-match evaluator
- `app/Services/ChallengeEvaluators/SingleMatchIndividualMetricEvaluator.php` — Individual best evaluator
- `app/Services/ChallengeEvaluators/FastWinEvaluator.php` — Fast win evaluator
- `app/Services/ChallengeEvaluators/ZeroDeathWinEvaluator.php` — Zero death win evaluator
- `tests/Unit/MetricRegistryTest.php` — 3 tests, 21 assertions
- `tests/Unit/AccumulativeTeamMetricEvaluatorTest.php` — 8 tests
- `tests/Unit/SingleMatchTeamMetricEvaluatorTest.php` — 8 tests
- `tests/Unit/SingleMatchIndividualMetricEvaluatorTest.php` — 8 tests
- `tests/Unit/FastWinEvaluatorTest.php` — 8 tests
- `tests/Unit/ZeroDeathWinEvaluatorTest.php` — 7 tests

**Modified (5):**
- `config/dota.php` — 6 string stubs replaced with class references
- `app/Support/DailyChallenge/ChallengeCatalog.php` — Added `configuration.metric` to 4 entries
- `app/Support/DailyChallenge/ChallengeCatalogValidator.php` — Validates `configuration.metric` against `MetricRegistry`
- `app/Services/DailyChallenge/ChallengeProgressService.php` — `mergeProgressData` handles `best_attempt`, `best_member_id`, `best_match_id`
- `tests/Feature/ChallengeCatalogTest.php` — 7 new tests for metric validation (total: 18 tests)

**Preserved (unchanged):**
- `HeroWinEvaluator.php`, `ItemWinEvaluator.php` — no modifications
- `ChallengeEvaluator.php` interface, `EvaluationResult.php` DTO — no modifications
- `ChallengeEvaluatorRegistry.php` — no modifications (already supports class resolution)
- `ChallengeProgressService.php` (except `mergeProgressData`) — `processChallenge`, `completeChallenge` unchanged
- `ChallengeDescriptionService.php`, `ChallengeAssignmentService.php`, `ChallengeReviewService.php` — no modifications

### **8.11 No Migration Required**

`progress_data` is a JSON column — new keys (`best_attempt`, `best_member_id`, `best_match_id`) are handled safely via `??` fallbacks. Existing stored data is fully backwards compatible.

### **8.12 Tests**

**New tests:** 56 tests, 258 assertions — **ALL PASS**

**Existing evaluator tests:** 13 tests — **ALL PASS** (no regressions)

`tests/Unit/MetricRegistryTest.php` — 3 tests:
- Registry returns all 11 metrics
- `exists()` returns true for valid, false for invalid

`tests/Unit/AccumulativeTeamMetricEvaluatorTest.php` — 8 tests:
- Sums contributors correctly, handles zero total, different metrics
- Missing config, no members, members not in match
- Contributors map, matches array

`tests/Unit/SingleMatchTeamMetricEvaluatorTest.php` — 8 tests:
- Team total calculation, `best_attempt` set on first match
- `best_attempt` not updated when lower, progress delta calculation
- Contributor storage, missing config, no members

`tests/Unit/SingleMatchIndividualMetricEvaluatorTest.php` — 8 tests:
- Highest player selected, `best_member_id`/`best_match_id` stored
- Not updated when lower, progress delta is improvement
- Missing config, no members

`tests/Unit/FastWinEvaluatorTest.php` — 8 tests:
- Win under requirement passes, loss fails, slow win fails
- Duration exactly at requirement passes
- Duration metadata stored, already completed (idempotency), no members

`tests/Unit/ZeroDeathWinEvaluatorTest.php` — 7 tests:
- Win + 0 deaths passes, win + deaths fails, loss + 0 deaths fails
- Multiple qualifying members, contributors map, no members

`tests/Feature/ChallengeCatalogTest.php` — 7 new tests:
- Valid metric accepted, invalid metric rejected
- Metric-based challenges have metric in configuration
- Each metric challenge uses correct metric name

---

# **Step 9: Challenge Simulation & Validation** ✅ Implemented

### **9.1 Overview**

Created comprehensive end-to-end validation tests using real OpenDota fixture data (`match_unparsed.json` from `docs/samples/`) and the full challenge pipeline — from match processing through evaluation, progress, completion, notification, idempotency, review delays, weighted assignment, and notification batching. All tests use real services without mocking.

### **9.2 Architecture**

```
tests/Helpers/ChallengeTestHelper.php (fixture loading + scenario creation)
    ↓ used by
7 new Feature test files (E2E, idempotency, progress data, review delay,
    weighted assignment, notification pipeline)
    ↓ exercising
ChallengeProgressService → ChallengeReviewService → ChallengeNotificationDispatcher
       ↓                       ↓
   EvaluatorRegistry     ChallengeReviewGuardService
```

### **9.3 Fixture Infrastructure**

**Files created:**
- `tests/Helpers/ChallengeTestHelper.php` — Reusable helper with methods:
  - `loadFixture(string $name): array` — Loads JSON from `docs/samples/`, cached
  - `loadMatchFixture(): array` — Loads `match_unparsed.json`
  - `loadParsedFixture(): array` — Loads `match_parsed.json`
  - `createMemberForAccount(int $accountId, string $dest): Member` — Converts 32-bit account_id → 64-bit Steam ID
  - `createMembersForAccounts(array $accountIds, string $dest): Collection` — Batch member creation
  - `createMatchFromFixture(array $memberIds, array $overrides): DotaMatch` — Maps members to fixture data with overridable match_data fields
  - `buildMatchData(array $players, array $overrides): array` — Constructs minimal custom match payloads
  - `getWhatsAppDestination(): Destination` — Get or create WhatsApp destination
  - `createTestDestination(): Destination` — Create unique test destination
  - `createChallengeScenario(string $code, array $attrs, array $dcAttrs, ?Destination): array` — One-liner for full setup returning `{Destination, Challenge, DestinationChallenge}`
  - `seedChallengesIfNeeded(): void` — Conditionally seeds challenge catalog
  - `getFixturePlayer(int $index): array` — Get player data by index
  - `getFixtureAccountIds(): array` — Get all valid account_ids from fixture

**Fixture data used:** 7 account_ids from `match_unparsed.json`:
- 125753349 (Dawnbreaker, hero 135, 11 kills, Silver Edge)
- 225621471 (Phoenix, hero 110, 7 kills, heal 7279)
- 296555939 (Pudge, hero 14, 8 kills)
- 322773166 (Sniper, hero 35, 4 denies)
- 153517712 (Spectre, hero 67, 154 last hits, 4 denies)
- 152866833 (Tusk, hero 100)
- 249831672 (Axe, hero 2)

Fixture baseline: `radiant_win: false`, `duration: 1741s (29min)`.

### **9.4 End-to-End Evaluator Tests**

`tests/Feature/ChallengeEvaluatorE2ETest.php` — 21 tests, 60 assertions:
- **hero_win** (3 tests): Hero match detection, completion at requirement=1, non-matching hero returns 0
- **item_win** (2 tests): Inventory detection (Silver Edge 249), completion with notification
- **total_kills** (3 tests): Accumulates 18 kills (11+7), below-requirement stays active, completion at requirement=15
- **total_denies** (1 test): Accumulates 8 denies (4+4), progress event recorded
- **total_heal** (2 tests): Accumulates 12109 heal (4830+7279), completion at requirement=10000
- **last_hits** (3 tests): Records `best_attempt=154`, `best_member_id`, `best_match_id`, completion, `best_attempt` not updated on lower value
- **fast_win** (2 tests): Duration check (29min ≤ 30min passes), loss fails, duration metadata stored
- **zero_death_win** (3 tests): 0-death+win passes, has-deaths fails, loss-with-0-deaths fails

### **9.5 Progress Data Validation**

`tests/Feature/ChallengeProgressDataTest.php` — 6 tests, 28 assertions:
- All 6 standardized keys present: `contributors`, `matches`, `best_attempt`, `best_member_id`, `best_match_id`, `metadata`
- Contributors accumulate across matches (11+7=18, doubled to 36 on second match)
- Matches array deduplicated
- `best_attempt` only updates when improved (154 → stays at 154 when 80 comes)
- Empty existing progress_data handled gracefully (null → full structure)
- Metadata shallow-merged correctly

### **9.6 Idempotency Simulation**

`tests/Feature/ChallengeIdempotencyTest.php` — 5 tests, 16 assertions:
- Same match processed twice → progress unchanged (1 not 2)
- Same match processed twice → duplicate completion not triggered (1 completed event, 1 notification)
- Post-completion match not processed (challenge already completed, skipped by processDestination)
- `EvaluateChallengesJob` dispatched twice → progress only counted once
- Mixed: 3 unique matches + 2 duplicates → only new matches contribute (18+18=36, only 2 matches in progress_data)

### **9.7 Review Delay Simulation**

`tests/Feature/ChallengeReviewDelayE2ETest.php` — 5 tests, 18 assertions:
- **Delayed Review**: `parse_status='pending'`, `finished_at > 1h` → review blocked, no increment, no `failed_days` increase, `review_delayed` notification created
- **Duplicate Delay**: Running review twice while blocked → only one `review_delayed` notification
- **Resolution Path**: Match becomes `parsed` → deferred review executes → challenge incremented and failed_days incremented
- **Force Review**: `review_delayed` notification > 12h old → `review_forced` events created, review proceeds normally
- **No Recap When Blocked**: Recap notification not created when review is blocked

### **9.8 Weighted Assignment Validation**

`tests/Feature/ChallengeWeightedAssignmentTest.php` — 5 tests, 13 assertions:
- `hero_win` (weight 15) appears >3x more often than `zero_death_win` (weight 2) in 500 iterations
- `total_kills` (weight 15) appears >1.5x more often than `fast_win` (weight 5) in 500 iterations
- All 8 challenge codes appear at least once in 1000 iterations
- Inactive challenges (is_active=false) are never selected
- Active code exclusion: hero_win never assigned when one is already active (100 iterations)

### **9.9 Notification Pipeline Validation**

`tests/Feature/ChallengeNotificationPipelineTest.php` — 7 tests, 25 assertions:
- **Completion Batching**: 3 completed challenges, same destination → 1 batched message, all marked sent
- **Single Completion**: Uses individual format, counts as 1 batch group
- **Different Destinations**: Not batched together → 2 separate sends
- **Future `scheduled_at`**: `assigned_announcement` with future time → skipped, remains pending
- **Past `scheduled_at`**: Sent immediately
- **Review Delayed**: Destination resolved correctly from `payload.destination_id`
- **Duplicate Prevention**: Dedup logic prevents duplicate `review_delayed` notifications

### **9.10 Files Summary**

**Created (7):**
- `tests/Helpers/ChallengeTestHelper.php` — Fixture loading & scenario creation helpers
- `tests/Feature/ChallengeEvaluatorE2ETest.php` — 21 tests, all 8 evaluators
- `tests/Feature/ChallengeProgressDataTest.php` — 6 tests, progress structure validation
- `tests/Feature/ChallengeIdempotencyTest.php` — 5 tests, duplicate processing safety
- `tests/Feature/ChallengeReviewDelayE2ETest.php` — 5 tests, OpenDota delay handling
- `tests/Feature/ChallengeWeightedAssignmentTest.php` — 5 tests, statistical weight validation
- `tests/Feature/ChallengeNotificationPipelineTest.php` — 7 tests, notification batching

**Modified (0):** No existing files modified.

**New test totals:** 49 tests, 160 assertions added across 6 feature test files plus 1 helper.

### **9.11 Architectural Observations**

1. **`current_requirement` dual-purpose for snapshots**: `FastWinEvaluator` uses `current_requirement` as the time threshold, but `ChallengeProgressService` uses it as the completion threshold (progress >= requirement). For snapshot evaluators where progress is binary (1), completion never triggers when requirement > 1. This should be addressed in a future step by separating the evaluator threshold from the completion threshold (e.g., via `challenge.configuration`).

2. **Force review date-dependency**: The `whereDate('created_at', $today)` filter in the guard makes force review only work when the delay notification is created today. If the delay spans midnight, a new notification created tomorrow won't match the old one. This is by design (daily challenge scope) but worth documenting.

3. **All tests pass cross-database**: SQLite in-memory for tests, MySQL for production — the fixture helpers and test assertions work on both.

---

# **Step 10: Challenge Content Expansion** ✅ Implemented

### 10.1 Overview

Expanded the challenge catalog from 8 to 27 entries using the 3 existing generic evaluators (`AccumulativeTeamMetricEvaluator`, `SingleMatchTeamMetricEvaluator`, `SingleMatchIndividualMetricEvaluator`). Added a `group` column to prevent same-theme repetition (e.g., `total_kills`, `team_kills_match`, `player_kills_match` are all in the `kills` group). Introduced group-aware assignment logic with three-tier fallback. Rebalanced weights across the expanded catalog. No new evaluators were created.

### 10.2 Challenge Catalog (27 entries)

**Existing (8)** — updated with groups:

| Code | Group | Weight | Type |
|---|---|---|---|
| `total_kills` | kills | 15 | accumulative |
| `total_denies` | denies | 10 | accumulative |
| `total_heal` | healing | 8 | accumulative |
| `hero_win` | hero_win | 15 | accumulative |
| `item_win` | item_win | 10 | accumulative |
| `last_hits` | last_hits | 8 | snapshot |
| `zero_death_win` | survival | 2 | snapshot |
| `fast_win` | speed | 5 | snapshot |

**New Accumulative Team (5)** — `AccumulativeTeamMetricEvaluator`:

| Code | Metric | Group | Weight | Base→Max |
|---|---|---|---|---|
| `total_assists` | assists | assists | 15 | 40→70 |
| `total_hero_damage` | hero_damage | hero_damage | 10 | 80000→125000 |
| `total_tower_damage` | tower_damage | tower_damage | 8 | 15000→24000 |
| `total_last_hits` | last_hits | last_hits | 12 | 300→450 |
| `total_net_worth` | net_worth | economy | 8 | 60000→90000 |

**New Single Match Team (6)** — `SingleMatchTeamMetricEvaluator`, all snapshot:

| Code | Metric | Group | Weight | Base |
|---|---|---|---|---|
| `team_assists_match` | assists | assists | 15 | 35 |
| `team_kills_match` | kills | kills | 15 | 30 |
| `team_last_hits_match` | last_hits | last_hits | 12 | 250 |
| `team_denies_match` | denies | denies | 8 | 15 |
| `team_hero_damage_match` | hero_damage | hero_damage | 10 | 70000 |
| `team_tower_damage_match` | tower_damage | tower_damage | 8 | 12000 |

**New Single Match Individual (8)** — `SingleMatchIndividualMetricEvaluator`, all snapshot:

| Code | Metric | Group | Weight | Base |
|---|---|---|---|---|
| `player_kills_match` | kills | kills | 15 | 12 |
| `player_assists_match` | assists | assists | 15 | 15 |
| `player_last_hits_match` | last_hits | last_hits | 12 | 80 |
| `player_hero_damage_match` | hero_damage | hero_damage | 10 | 25000 |
| `player_tower_damage_match` | tower_damage | tower_damage | 8 | 6000 |
| `player_net_worth_match` | net_worth | economy | 8 | 18000 |
| `player_gpm_match` | gold_per_min | economy | 3 | 500 |
| `player_xpm_match` | xp_per_min | economy | 3 | 600 |

### 10.3 Grouping Design

Added a `group` column (nullable string, indexed) to the `challenges` table. All 27 entries have group assignments across 12 unique groups:

| Group | Challenges |
|---|---|
| `kills` | total_kills, team_kills_match, player_kills_match |
| `assists` | total_assists, team_assists_match, player_assists_match |
| `last_hits` | last_hits, total_last_hits, team_last_hits_match, player_last_hits_match |
| `denies` | total_denies, team_denies_match |
| `hero_damage` | total_hero_damage, team_hero_damage_match, player_hero_damage_match |
| `tower_damage` | total_tower_damage, team_tower_damage_match, player_tower_damage_match |
| `economy` | total_net_worth, player_net_worth_match, player_gpm_match, player_xpm_match |
| `hero_win` | hero_win |
| `item_win` | item_win |
| `healing` | total_heal |
| `survival` | zero_death_win |
| `speed` | fast_win |

### 10.4 Assignment Logic Changes

Three-tier fallback in `selectChallenge()`:

1. **Tier 1**: Exclude cooldown IDs + active codes + **active groups**
2. **Tier 2** (cooldown exhausted): Exclude active codes + active groups
3. **Tier 3** (groups exhausted): Exclude active codes only — drop group constraint

Group exclusion is gathered from active `DestinationChallenge` records joined with `challenges.group` (non-null values only). This prevents scenarios like Monday=total_kills, Tuesday=team_kills_match (both kills group).

### 10.5 Weight Balancing Rationale

| Tier | Weight | Groups | Rationale |
|---|---|---|---|
| Common | 15 | kills, assists, hero_win | Core gameplay, always relevant |
| Medium-High | 12 | last_hits | Frequent farming metric |
| Medium | 10 | denies, item_win, hero_damage | Common but situational |
| Medium-Low | 8 | healing, tower_damage, economy, net_worth | Normal frequency |
| Low | 5 | speed | Situational |
| Rare | 2-3 | survival, extreme GPM/XPM | Very hard or niche |

### 10.6 Files Created

- `database/migrations/2026_06_06_213202_add_group_to_challenges_table.php` — Schema migration

### 10.7 Files Modified

- `app/Models/Challenge.php` — Added `group` to `$fillable`
- `app/Support/DailyChallenge/ChallengeCatalog.php` — 27 entries with groups, expanded PHPDoc
- `config/dota.php` — 19 new evaluator mappings
- `app/Services/DailyChallenge/ChallengeAssignmentService.php` — Three-tier group-aware selection
- `app/Services/DailyChallenge/ChallengeDescriptionService.php` — 19 new description templates (Bahasa Indonesia)
- `database/factories/ChallengeFactory.php` — Added `group` to default state
- `tests/Feature/ChallengeCatalogTest.php` — 8 new tests (group validation, count, weight consistency)
- `tests/Feature/AssignDailyChallengesCommandTest.php` — 5 new group exclusion tests
- `tests/Feature/ChallengeEvaluatorE2ETest.php` — 9 new E2E tests (accumulative, team, individual, GPM/XPM)
- `tests/Feature/ChallengeSeederTest.php` — Updated counts (8→27)

### 10.8 Test Summary

- **New tests**: 22 (8 catalog + 5 assignment + 9 E2E)
- **Modified tests**: 4 existing (seeder count update)
- **Total challenge test suite**: 208 tests, 1479 assertions — **ALL PASS**

### 10.9 Design Decisions

1. **No new evaluators** — All 19 new challenges reuse existing generic evaluators
2. **Three-tier fallback** — Group exclusion is a strong preference, not an absolute block
3. **`group` is nullable at DB level** — All catalog entries have groups, but schema supports null for flexibility
4. **GPM/XPM are snapshot-only** — Rates don't accumulate sensibly across matches
5. **Thresholds tuned for Turbo** — All base requirements are achievable in 1-3 Turbo matches (~20-25 min)
6. **Bahasa Indonesia descriptions** — All 19 new codes have explicit templates, not relying on English fallback
