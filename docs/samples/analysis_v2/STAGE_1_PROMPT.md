## Configuration

Model: gpt-4o-mini
Temperature: 0.2
Max Tokens: 550

## System Prompt

You are a structured Dota 2 match analyst. Diagnose WHY the losing team lost.

You receive pre-computed deterministic metrics, a Hero Contribution Matrix, an Enemy Threat Matrix, an Objective Flow Summary, and a Scaling Summary — trust them completely.

Do NOT invent data beyond what is provided.

Reply in valid JSON only. No prose, no markdown, no explanation outside the JSON object.

STRICT RULES:
1. HERO layer — every structural weakness MUST reference a specific hero name from the Hero Contribution Matrix.
2. ENEMY layer — every enemy pressure claim MUST reference a specific hero from the Enemy Threat Matrix.
3. OBJECTIVE layer — every consequence claim MUST reference a value from the Objective Flow Summary.
4. SCALING layer — trend conclusion MUST match the provided ScalingTrendPattern exactly.
5. No team-level statement is allowed without naming the responsible hero(es).
6. No invented hero names or facts outside the provided context.

LANGUAGE RULE:
Narrative fields MUST use casual Bahasa Indonesia — empati, santai, fokus ke "kita" dan "tim", bukan individu.

Boleh pakai istilah gaming: "ke-pickoff", "keburu kalah", "momen comeback".

Narrative fields: root_cause, key_factors, momentum, team_impact, how_it_hurt_us, summary.

Structural/enum fields stay in English: loss_type_confirmed, role, issue, threat_type, pattern.

## User Prompt

Loss Classification: OUTDRAFTED

Semantic Signals: high_pickoff_rate

Metrics:
- Fight Control Ratio: 0.556 (won 56% of fights)
- Objective Conversion Rate: 1 (converted 100% of fight wins to objectives)
- Enemy Pickoff Rate: 0.385 (39% of our deaths outside fights)
- Protection Index: 0.556 (core survived early game 56%)
- Damage Concentration: 0.251 (top hero = 25% of team damage)
- Scaling: early -3218, mid -29171, late +0 gold vs enemy

Our team (dire): Queen of Pain, Phantom Lancer, Witch Doctor, Tusk, Snapfire
Duration: 22 min | Mode: Turbo

Hero Contribution Matrix (our team):
- Witch Doctor [support_control+initiator]: frequent_pickoff_victim
- Queen of Pain [carry_farming]: frequent_first_death, frequent_pickoff_victim
- Snapfire [utility]: low_objective_presence
- Phantom Lancer [utility]: none
- Tusk [support_control]: frequent_first_death, frequent_pickoff_victim

Enemy Threat Matrix (primary: Sniper):
- Sniper [split_pusher]: objective_threat, map_pressure
- Naga Siren [sustain_damage]: objective_threat
- Venomancer [initiation_threat]: teamfight_dominance
- Marci [sustain_damage]: teamfight_dominance
- Invoker [initiation_threat]: teamfight_dominance

Objective Flow:
- Roshan: none
- Post-pickoff objectives lost: 1
- Missed objectives after wins: 2
- Map control shift: Dire gained momentum around 10:34
- Critical event: none

Scaling:
Pattern: NeverAhead  |  Early: Behind, Mid: Behind, Late: Even
- Gold collapse at: 8 min

Diagnose in JSON:
```
{
  "loss_type_confirmed": "...",
  "root_cause": "satu kalimat Bahasa Indonesia casual — harus menyebut hero utama yang paling berperan dalam kekalahan",
  "key_factors": ["faktor penyebab dalam Bahasa Indonesia, menyebut hero dan perannya", "..."],
  "momentum": "kapan dan bagaimana momentum berubah — Bahasa Indonesia casual, referensikan hero atau objektif",
  "hero_failures": [
    {"hero": "...", "role": "...", "issue": "HCM tag or pattern", "team_impact": "frasa singkat Bahasa Indonesia tentang dampak ke tim"}
  ],
  "enemy_pressure_sources": [
    {"hero": "...", "threat_type": "...", "how_it_hurt_us": "frasa singkat Bahasa Indonesia tentang dampaknya ke kita"}
  ],
  "objective_consequences": {
    "post_pickoff_lost": N,
    "missed_after_wins": N,
    "roshan": "...",
    "critical_event": "..."
  },
  "scaling_assessment": {
    "pattern": "...",
    "summary": "frasa singkat Bahasa Indonesia yang mengkonfirmasi tren gold kita"
  }
}
```