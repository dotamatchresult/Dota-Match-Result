### Potential Step 11 — Challenge Balancing

After a few weeks of real usage, collect data:

* Which challenges get completed most?
* Which are frequently abandoned?
* Average completion time?
* Which requirements are too easy/hard?

Then adjust:

```php
weight
base_requirement
increment_value
max_requirement
```

No new code may be needed—just catalog tuning.

---

### Potential Step 12 — Seasonal / Event Challenges

Examples:

* TI season challenges
* Patch-themed challenges
* Holiday events
* Weekend-only challenges

Your catalog system already supports this with minor extensions.

---

### Potential Step 13 — Challenge Analytics

Interesting statistics:

* Most completed challenge
* Most failed challenge
* Hardest challenge
* Top contributor in each destination
* Challenge completion rate

Mostly reporting, not gameplay logic.

---

### Potential Step 14 — Smarter Assignment

Currently:

```text
Random
+ Weight
+ Cooldown
+ Active exclusion
```

Later you could add:

```text
Avoid repeating support challenges
Prefer challenges not seen recently
Adjust difficulty based on completion rate
```

But that's optimization, not necessity.

---

### Potential Step 15 — AI-Assisted Challenge Authoring

This is where the `challenge-authoring.md` you created becomes valuable.

An LLM could generate:

```php
[
    'name' => 'Damage Dealer',
    'configuration' => [
        'metric' => 'hero_damage',
    ],
]
```

and a validator ensures it's legal before entering the catalog.

---

### My recommendation

Don't create Steps 11–15 right now.

Finish Step 9 first.

Once Step 9 passes, deploy and gather real usage for a few weeks. Real player behavior will reveal far more useful improvements than planning hypothetical future steps. The best roadmap after that will come from observing which challenges your groups actually enjoy chasing.
