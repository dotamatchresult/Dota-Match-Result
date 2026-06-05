# Task: Implement Step 5 - Challenge Notification Delivery System
Read and follow PLAN.md. Only implement **Step 5**.

Do NOT implement Step 6 or beyond.

Current project state:

- Laravel 12
- Challenge assignment system exists
- Challenge evaluation system exists
- `ChallengeNotification` records are already created by:`ChallengeAssignmentService`
- `ChallengeProgressService`

- Existing message services:`TelegramService`
- `FonnteService`

- Existing models:Destination
- DestinationChallenge
- Challenge
- ChallengeNotification
- ChallengeEvent

- Existing notification types:`assigned_announcement`
- `completed`
- `backlog_full`

- Future notification types (DO NOT IMPLEMENT YET):`failed_review`
- recap notifications
- sarcastic notifications

---

# Goal
Implement a transport-agnostic notification delivery system.

Flow:

ChallengeNotification(status=pending)
↓
ChallengeNotificationDispatcher
↓
ChallengeMessageRenderer
↓
DestinationMessageService
↓
TelegramService / FonnteService
↓
status=sent

Requirements:

- Use `challenge_notifications` as the single source of truth.
- No direct Telegram/Fonnte calls inside challenge assignment or progress services.
- Notification delivery must be decoupled from challenge logic.
- Must support both Telegram and WhatsApp destinations.

---

# Existing Destination Structure
Inspect existing Destination model and determine how transport type is stored.

Use existing platform/channel information.

Do NOT introduce a new destination type system if one already exists.

Reuse existing conventions.

---

# Deliverable 1: DestinationMessageService
Create:

```
app/Services/Messaging/DestinationMessageService.php
```
Responsibility:

```
send(
    Destination $destination,
    string $message
): void
```
Behavior:

- Resolve transport from destination
- Send via TelegramService OR FonnteService
- Reuse existing services
- Do not duplicate existing messaging logic

If useful, create:

```
DestinationMessageTransport
TelegramTransport
WhatsappTransport
```
But keep implementation simple.

---

# Deliverable 2: ChallengeDescriptionService
Create:

```
app/Services/DailyChallenge/ChallengeDescriptionService.php
```
Responsibility:

Generate human-readable challenge descriptions.

Input:

```
DestinationChallenge
```
Output:

```
string
```
Examples:

Hero challenge:

```
Win 2 matches using Pudge
```
Item challenge:

```
Win 3 matches while holding Butterfly
```
Requirements:

- Use runtime metadata stored in:

```
$destinationChallenge->progress_data['metadata']
```
when available.

- Fallback to challenge configuration.
- Reuse Item and Hero tables/models if available.

Do NOT hardcode names.

---

# Deliverable 3: ChallengeMessageRenderer
Create:

```
app/Services/DailyChallenge/ChallengeMessageRenderer.php
```
Input:

```
ChallengeNotification
```
Output:

```
string
```
Supported notification types:

---

## assigned_announcement
Example:

```
🎯 DAILY CHALLENGE

Win 2 matches using Pudge

Progress:
0 / 2

Good luck.
```
Use:

```
current_progress
current_requirement
```

---

## backlog_full
Example:

```
📚 CHALLENGE BACKLOG FULL

You already have 5 active challenges.

Finish some homework first.
```
Use:

```
config('dota.daily_challenge.max_active_per_destination')
```

---

## completed
The renderer must support:

### Single completion

```
🎉 CHALLENGE COMPLETED

✅ Win 2 matches using Pudge

Excellent work.
```

### Multiple completions

```
🎉 CHALLENGES COMPLETED

Your team completed 3 challenges:

✅ Win 2 matches using Pudge
✅ Win 3 matches while holding Butterfly
✅ Win 20 kills

Keep it going.
```
Do not generate this message directly from a single notification.

The dispatcher will provide grouped notifications.

Design renderer accordingly.

---

# Deliverable 4: ChallengeNotificationDispatcher
Create:

```
app/Services/DailyChallenge/ChallengeNotificationDispatcher.php
```
Responsibility:

Deliver pending notifications.

Flow:

```
pending notification
↓
render
↓
send
↓
mark sent
```
Rules:

- Load related destination.
- Use ChallengeMessageRenderer.
- Use DestinationMessageService.
- Mark:

```
status = sent
sent_at = now()
```
after successful delivery.

- Leave pending if delivery throws exception.

---

# Deliverable 5: Completion Notification Batching
Important requirement.

If multiple `completed` notifications exist for the same destination and none have been sent:

DO NOT send multiple messages.

Example:

Destination A has:

```
completed #1
completed #2
completed #3
```
Send:

```
🎉 CHALLENGES COMPLETED

Your team completed 3 challenges:

...
```
Mark all included notifications:

```
status = sent
sent_at = now()
```
Implementation suggestions:

- Group by destination.
- Batch only `completed` notifications.
- Other notification types remain independent.

The exact implementation is up to you.

---

# Deliverable 6: Artisan Command
Create:

```
php artisan make:command SendChallengeNotifications
```
Command:

```
challenges:send-notifications
```
Responsibilities:

- Process pending notifications.
- Handle batching.
- Output useful console summary.

Example:

```
Processed: 14
Sent: 12
Batched: 3 groups
Failed: 0
```

---

# Deliverable 7: Scheduler
Register scheduler.

Run every minute.

Requirements:

```
->everyMinute()
->withoutOverlapping()
```
Use existing scheduler conventions in project.

---

# Deliverable 8: Tests
Add tests for:

### ChallengeDescriptionService

- hero challenge
- item challenge
- metadata override

### ChallengeMessageRenderer

- assigned announcement
- backlog full
- single completion
- multi-completion

### ChallengeNotificationDispatcher

- successful send
- failed send remains pending

### Completion batching

- multiple completed notifications
- one message sent
- all notifications marked sent

Use existing testing style.

---

# Important Constraints
Do NOT implement:

- 23:00 review logic
- failed challenge escalation
- increment_value handling
- failed_days updates
- sarcastic messages
- recap notifications
- OpenDota delay handling
- new challenge evaluators

Only implement Step 5 notification delivery infrastructure.

Follow existing project architecture and coding style.
