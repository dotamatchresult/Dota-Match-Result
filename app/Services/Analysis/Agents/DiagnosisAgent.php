<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\DiagnosisContext;
use App\DataObjects\Analysis\DiagnosisResult;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;

class DiagnosisAgent
{
    public function __construct(
        protected ClientContract $client
    ) {}

    /**
     * Stage 1 LLM: structured JSON diagnosis of why the team lost.
     * Temperature 0.2, max 350 tokens, output is strict JSON.
     */
    public function diagnose(DiagnosisContext $context): ?DiagnosisResult
    {
        try {
            $systemPrompt = $this->buildSystemPrompt();
            $userPrompt = $this->buildUserPrompt($context);

            $response = $this->client->chat()->create([
                'model' => config('openai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                // 'temperature' => 0.2,
                'reasoning_effort' => config('openai.reasoning_effort'),
                // 'max_tokens' => 550,
                'response_format' => ['type' => 'json_object'], // Forces valid JSON
            ]);

            if (config('app.env') !== 'production') {
                Log::info('DiagnosisAgent LLM response received', [
                    'match_id' => $context->matchId,
                    'systemPrompt' => $systemPrompt,
                    'userPrompt' => $userPrompt,
                    'tokensUsed' => $response->usage->totalTokens ?? 0,
                    'rawContent' => $response->choices[0]->message->content ?? null,
                ]);
            }

            $content = $response->choices[0]->message->content ?? null;
            $tokensUsed = $response->usage->totalTokens ?? 0;

            if (! $content) {
                Log::warning('DiagnosisAgent received empty LLM response', ['match_id' => $context->matchId]);

                return $this->buildFallback($context, $tokensUsed);
            }

            // Strip potential markdown code fences before decoding
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($content));
            $data = json_decode($cleaned, true);

            if (! is_array($data)) {
                Log::warning('DiagnosisAgent LLM returned non-JSON, using fallback', [
                    'match_id' => $context->matchId,
                    'raw' => substr($content, 0, 200),
                ]);

                return $this->buildFallback($context, $tokensUsed);
            }

            return new DiagnosisResult(
                lossTypeConfirmed: $data['loss_type_confirmed'] ?? $context->lossType,
                rootCause: $data['root_cause'] ?? 'Performance analisis tidak tersedia',
                keyFactors: (array) ($data['key_factors'] ?? $context->semanticTags),
                momentum: $data['momentum'] ?? 'Tidak terdeteksi',
                tokensUsed: $tokensUsed,
                heroFindings: (array) ($data['hero_failures'] ?? $data['hero_specific_findings'] ?? []),
                enemyPressureSources: (array) ($data['enemy_pressure_sources'] ?? []),
                objectiveConsequences: (array) ($data['objective_consequences'] ?? []),
                scalingAssessment: (array) ($data['scaling_assessment'] ?? []),
            );
        } catch (\Exception $e) {
            Log::error('DiagnosisAgent LLM exception', [
                'match_id' => $context->matchId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildFallback(DiagnosisContext $context, int $tokensUsed): DiagnosisResult
    {
        return new DiagnosisResult(
            lossTypeConfirmed: $context->lossType,
            rootCause: 'Analisis otomatis — LLM tidak merespons dengan JSON valid',
            keyFactors: $context->semanticTags,
            momentum: 'Tidak terdeteksi',
            tokensUsed: $tokensUsed,
            heroFindings: [],
        );
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are a structured Dota 2 match analyst. Diagnose WHY the losing team lost.
            You receive pre-computed deterministic metrics, a Hero Contribution Matrix, an Enemy Threat Matrix, an Objective Flow Summary, and a Scaling Summary — trust them completely.
            Do NOT invent data beyond what is provided.
            Reply in valid JSON only. No prose, no markdown, no explanation outside the JSON object. Your response MUST start with { and end with }.

            STRICT RULES:
            1. HERO layer — every structural weakness MUST reference a specific hero name from the Hero Contribution Matrix.
            2. ENEMY layer — every enemy pressure claim MUST reference a specific hero from the Enemy Threat Matrix.
            3. OBJECTIVE layer — every consequence claim MUST reference a value from the Objective Flow Summary.
            4. SCALING layer — trend conclusion MUST match the provided ScalingTrendPattern exactly.
            5. No team-level statement is allowed without naming the responsible hero(es).
            6. No invented hero names or facts outside the provided context.

            LANGUAGE RULE:
            Narrative fields MUST use casual Bahasa Indonesia — empati, santai, fokus ke "kita" dan "tim", bukan individu.
            Boleh pakai istilah gaming atau slang.
            Narrative fields: root_cause, key_factors, momentum, team_impact, how_it_hurt_us, summary.
            Structural/enum fields stay in English: loss_type_confirmed, role, issue, threat_type, pattern.
            PROMPT;
    }

    private function buildUserPrompt(DiagnosisContext $context): string
    {
        $tags = ! empty($context->semanticTags)
            ? implode(', ', $context->semanticTags)
            : 'none';

        $memberHeroes = implode(', ', $context->memberHeroes);

        $scalingLine = sprintf(
            'early %+d, mid %+d, late %+d gold vs enemy',
            $context->scalingEarlyGold,
            $context->scalingMidGold,
            $context->scalingLateGold
        );

        $hcmLines = [];
        foreach ($context->heroContributionMatrix->toPayload() as $entry) {
            $tagStr = ! empty($entry['tags']) ? implode(', ', $entry['tags']) : 'none';
            $roleStr = implode('+', (array) $entry['role']);
            $hcmLines[] = "- {$entry['hero']} [{$roleStr}]: {$tagStr}";
        }
        $hcmBlock = implode("\n", $hcmLines) ?: '- (no hero data available)';

        // Enemy Threat Matrix block
        $etmLines = [];
        $primaryThreat = $context->enemyThreatMatrix->primaryThreat();
        foreach ($context->enemyThreatMatrix->toPayload() as $entry) {
            $impacts = implode(', ', $entry['impact_on_us']);
            $etmLines[] = "- {$entry['hero']} [{$entry['threat_type']}]: {$impacts}";
        }
        $etmBlock = implode("\n", $etmLines) ?: '- (no enemy data)';

        // Objective Flow Summary block
        $ofs = $context->objectiveFlowSummary->toPayload();
        $ofsBlock = implode("\n", [
            "- Roshan: {$ofs['roshan_control']}",
            "- Post-pickoff objectives lost: {$ofs['post_pickoff_objectives_lost']}",
            "- Missed objectives after wins: {$ofs['missed_objectives_after_wins']}",
            "- Map control shift: {$ofs['map_control_shift']}",
            "- Critical event: {$ofs['critical_event']}",
        ]);

        // Scaling Summary block
        $ss = $context->scalingSummary->toPayload();
        $collapseStr = $ss['collapse_at'] !== null ? "{$ss['collapse_at']} min" : 'no collapse detected';
        $ssBlock = "Pattern: {$ss['pattern']}  |  Early: {$ss['early']}, Mid: {$ss['mid']}, Late: {$ss['late']}\n- Gold collapse at: {$collapseStr}";

        return <<<PROMPT
            Loss Classification: {$context->lossType}
            Semantic Signals: {$tags}

            Metrics:
            - Fight Control Ratio: {$context->fightControlRatio} (won {$this->pct($context->fightControlRatio)}% of fights)
            - Objective Conversion Rate: {$context->objectiveConversionRate} (converted {$this->pct($context->objectiveConversionRate)}% of fight wins to objectives)
            - Enemy Pickoff Rate: {$context->enemyPickoffRate} ({$this->pct($context->enemyPickoffRate)}% of our deaths outside fights)
            - Protection Index: {$context->protectionIndex} (core survived early game {$this->pct($context->protectionIndex)}%)
            - Damage Concentration: {$context->damageConcentrationRatio} (top hero = {$this->pct($context->damageConcentrationRatio)}% of team damage)
            - Scaling: {$scalingLine}

            Our team ({$context->memberTeam}): {$memberHeroes}
            Duration: {$context->durationMinutes} min | Mode: {$context->gameMode}

            Hero Contribution Matrix (our team):
            {$hcmBlock}

            Enemy Threat Matrix (primary: {$primaryThreat}):
            {$etmBlock}

            Objective Flow:
            {$ofsBlock}

            Scaling:
            {$ssBlock}

            Diagnose in JSON:
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
            PROMPT;
    }

    private function pct(float $ratio): int
    {
        return (int) round($ratio * 100);
    }
}
