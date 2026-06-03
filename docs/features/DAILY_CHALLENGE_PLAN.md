# **Step 1: Database Design**

### **1.1 Create `challenges` table (challenge pool)**

Fields:

```php
id
name
type (player_based, team_based)
base_requirement (integer)
increment (integer)
max_requirement (integer)
description (text)
is_active (boolean)
created_at
updated_at
```

### **1.2 Create `daily_challenges` table**

Fields:

```php
id
challenge_id
destination_id
date
current_requirement (integer)
status (enum: pending, completed)
progress (json)
created_at
updated_at
```

**Reasoning:**

* Track per-destination challenge each day
* Progress JSON allows team/player tracking without too many relational tables
* Increment unfinished tasks safely

---

# **Step 2: Models and Relationships**

### **2.1 Models**

```php
class Challenge extends Model {
    protected $fillable = ['name','type','base_requirement','increment','max_requirement','description','is_active'];
}

class DailyChallenge extends Model {
    protected $fillable = ['challenge_id','destination_id','date','current_requirement','status','progress'];
    protected $casts = [
        'progress' => 'array',
    ];

    public function challenge() {
        return $this->belongsTo(Challenge::class);
    }

    public function destination() {
        return $this->belongsTo(Destination::class);
    }
}
```

### **2.2 Relationships**

* `Destination` hasMany `DailyChallenge`
* `Challenge` hasMany `DailyChallenge`
* `DailyChallenge` belongsTo `Challenge` and `Destination`

---

# **Step 3: Cron Jobs / Scheduler**

### **3.1 At 00:00 – Generate daily challenges**

* Query `Challenge` pool → pick random active challenge
* Create `DailyChallenge` for each destination
* Set `current_requirement` = `base_requirement`

### **3.2 At 08:00 – Announce challenge**

* Send message to each destination with the challenge description

### **3.3 During the day – Track progress**

* Hook into your match fetch system
* After a match is retrieved and parsed:

  1. Check if it affects an active `DailyChallenge`
  2. Update `progress` JSON
  3. If requirement met → set status completed, send congrats message

### **3.4 At 23:00 – End of day check**

* For each `DailyChallenge`:

  * If not completed:

    * Increment `current_requirement` by `increment` (cap at `max_requirement`)
    * Send sarcastic notification to destination
  * If completed:

    * Send recap message

---

# **Step 4: Challenge Logic**

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

# **Step 5: Messaging Service Integration**

* Use your existing Fonnte Service
* Examples:

  * **08:00:** "Today's challenge: Get 20 kills in turbo mode!"
  * **On completion:** "🎉 Challenge completed by your team!"
  * **23:00 unfinished:** "😏 Challenge not completed today… better luck tomorrow!"

---

# **Step 6: Handling OpenDota Delays**

* Compare Steam match end time vs OpenDota result retrieval
* If delay > 30 min → skip increment, wait until match arrives
* Ensure next day's challenge does not get auto-completed due to delayed match from previous day

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
