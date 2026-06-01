<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\AnalysisContext;
use App\DataObjects\Analysis\LLMAnalysis;
use App\Models\Hero;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;

class LLMAnalysisAgent
{
    public function __construct(
        protected ClientContract $client
    ) {}

    /**
     * Analyze defeat match using structured data from previous agents
     */
    public function analyzeDefeat(AnalysisContext $context): ?LLMAnalysis
    {
        try {
            // Build user content with structured data (NOT raw JSON)
            $userContent = $this->buildUserContent($context);

            $response = $this->client->chat()->create([
                'model' => config('openai.model'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->buildSystemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $userContent,
                    ],
                ],
                // 'temperature' => 0.7,
                // 'max_tokens' => 500,
                'reasoning_effort' => config('openai.reasoning_effort'),
            ]);

            $analysisText = $response->choices[0]->message->content ?? null;

            if (! $analysisText) {
                Log::error('OpenAI returned empty analysis');

                return null;
            }

            return new LLMAnalysis(
                text: trim($analysisText),
                tokensUsed: $response->usage->totalTokens ?? 0,
                model: $response->model ?? config('openai.model'),
                temperature: 0.7,
            );
        } catch (\Exception $e) {
            Log::error('OpenAI API analyzeDefeat exception', [
                'match_id' => $context->matchId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildUserContent(AnalysisContext $context): string
    {
        $memberHeroes = array_map(fn ($p) => $p->heroName, $context->playerImpacts);
        $formattedHeroes = $this->formatHeroList($memberHeroes);

        $lines = [];
        $lines[] = "Match ID: {$context->matchId}";
        $lines[] = "Game Mode: {$context->gameMode}";
        $lines[] = "Duration: {$context->durationMinutes} minutes";
        $lines[] = "Our Team: {$context->memberTeam}";
        $lines[] = 'Result: Lost';
        $lines[] = '';
        $lines[] = "Our Heroes: {$formattedHeroes}";
        $lines[] = '';
        $lines[] = '=== TEAM COMPOSITIONS ===';
        $lines[] = 'Radiant: '.implode(', ', $context->radiantHeroes);
        $lines[] = 'Dire: '.implode(', ', $context->direHeroes);
        $lines[] = '';
        $lines[] = '=== PLAYER PERFORMANCES ===';

        foreach ($context->playerImpacts as $player) {
            $lines[] = sprintf(
                '%s - KDA: %.1f, Impact Score: %.1f (%s)',
                $player->heroName,
                $player->getKda(),
                $player->impactScore,
                $player->impactLabel
            );
        }

        if (! empty($context->keyFights)) {
            $lines[] = '';
            $lines[] = '=== KEY TEAMFIGHTS ===';

            foreach ($context->keyFights as $fight) {
                $lines[] = sprintf(
                    'Fight at %s: %s (%s swing) - Key heroes: %s',
                    $fight->getTimeFormatted(),
                    $fight->outcome,
                    $fight->swing,
                    implode(', ', $fight->keyHeroes)
                );
            }
        }

        if (! empty($context->momentumShifts)) {
            $lines[] = '';
            $lines[] = '=== MOMENTUM SHIFTS ===';
            foreach ($context->momentumShifts as $shift) {
                $lines[] = "- {$shift}";
            }
        }

        if ($context->criticalObjective) {
            $lines[] = '';
            $lines[] = "CRITICAL: {$context->criticalObjective}";
        }

        $lines[] = '';
        $lines[] = 'Analyze WHY we lost. Focus ONLY on our heroes: '.$formattedHeroes;

        return implode("\n", $lines);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
            Kamu adalah analis DotA 2 yang berpengalaman dan objektif.
            Fokus analisismu adalah TIM YANG KALAH.

            Gunakan HANYA data terstruktur yang diberikan. Jangan mengarang, jangan asumsi di luar data.

            Tugasmu:
            - Menarik KESIMPULAN PENYEBAB KEKALAHAN dari data yang sudah diproses
            - Hubungkan performa pemain, fight outcomes, dan objektif dengan konteks gameplay
            - Fokus HANYA pada hero yang dimainkan member kami

            Panduan berpikir:
            - Analisis impact score dan KDA untuk menilai performa individu
            - Perhatikan fight outcomes (menang/kalah) dan timing-nya
            - Identifikasi momentum shifts dan critical objectives
            - Jelaskan kenapa fight penting itu kalah
            - Gunakan komposisi tim musuh untuk memahami matchup dan win condition
            - Fokus analisis pada hero member, tapi boleh sebutkan hero musuh jika relevan dengan kekalahan

            Gaya bahasa:
            - Bahasa Indonesia casual dan friendly
            - Santai dan lucu
            - Empati (tidak menyalahkan, tidak menghakimi)
            - Fokus ke "kita" dan "tim", bukan individu
            - Boleh pakai istilah casual gaming: "ke-pickoff", "keburu kalah", "momen comeback"

            Output WAJIB:
            - 4–6 bullet point menggunakan "-"
            - Setiap poin HARUS berbasis data yang diberikan
            - Setiap poin HARUS langsung ke inti masalah
            - Setiap poin dirangkum ke dalam 6-12 kata

            Dilarang:
            - Menambah kalimat pembuka atau penutup
            - Memberi saran build atau item
            - Menyalahkan player secara personal
            - Menyimpulkan tanpa dukungan data
            - Menggunakan bahasa yang terlalu formal
            PROMPT;
    }

    /**
     * Format hero list with proper Indonesian grammar
     * Examples: "Pudge", "Pudge dan Drow Ranger", "Pudge, Drow Ranger, dan Juggernaut"
     */
    private function formatHeroList(array $heroNames): string
    {
        $count = count($heroNames);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $heroNames[0];
        }

        if ($count === 2) {
            return $heroNames[0].' dan '.$heroNames[1];
        }

        // For 3 or more heroes: "Hero1, Hero2, dan Hero3"
        $lastHero = array_pop($heroNames);

        return implode(', ', $heroNames).", dan {$lastHero}";
    }
}
