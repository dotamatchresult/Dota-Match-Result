<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\AnalysisBullets;
use App\DataObjects\Analysis\CompressionContext;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;

class BulletCompressorAgent
{
    public function __construct(
        protected ClientContract $client
    ) {}

    /**
     * Stage 2 LLM: compress Stage 1 diagnosis into 4–6 casual Indonesian bullet points.
     * Temperature 0.7, max 280 tokens.
     */
    public function compress(CompressionContext $context): ?AnalysisBullets
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
                // 'temperature' => 0.7,
                // 'max_tokens' => 360,
                'reasoning_effort' => config('openai.reasoning_effort'),
            ]);

            if (config('app.env') !== 'production') {
                Log::info('BulletCompressorAgent LLM response received', [
                    'systemPrompt' => $systemPrompt,
                    'userPrompt' => $userPrompt,
                    'tokensUsed' => $response->usage->totalTokens ?? 0,
                    'rawContent' => $response->choices[0]->message->content ?? null,
                ]);
            }

            $content = $response->choices[0]->message->content ?? null;

            if (! $content) {
                Log::error('BulletCompressorAgent received empty LLM response');

                return null;
            }

            return new AnalysisBullets(
                bulletText: trim($content),
                tokensUsed: $response->usage->totalTokens ?? 0,
                model: $response->model ?? config('openai.model'),
            );
        } catch (\Exception $e) {
            Log::error('BulletCompressorAgent LLM exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
            Kamu adalah komentator DotA 2 yang santai dan objektif.
            Tugas kamu: ubah diagnosis match berikut menjadi 4–6 bullet point singkat.

            Aturan gaya bahasa:
            - Bahasa Indonesia casual, santai, dan sedikit lucu
            - Empati — tidak menyalahkan siapapun secara personal
            - Fokus ke "kita" dan "tim", bukan individu
            - Setiap poin harus berbasis data diagnosis yang diberikan

            Aturan format output:
            - HANYA bullet point menggunakan tanda "-"
            - Setiap poin 6–12 kata
            - Tidak ada kalimat pembuka atau penutup
            - Tidak ada saran build atau item
            - Tidak ada bahasa formal atau akademis

            Aturan hero WAJIB:
            - Jika hero disebut di diagnosis, WAJIB tetap muncul di minimal satu bullet.
            - Tidak boleh menghilangkan nama hero yang ada di hero_findings.
            - Setiap bullet yang menyebut hero harus menjelaskan dampaknya ke tim, bukan orangnya.

            Dilarang:
            - Menambah kalimat pembuka atau penutup
            - Menyalahkan player secara personal
            - Menggunakan bahasa terjemahan kaku dari bahasa Inggris
            PROMPT;
    }

    private function buildUserPrompt(CompressionContext $context): string
    {
        $memberHeroes = implode(', ', $context->memberHeroes);
        $keyFactors = ! empty($context->keyFactors)
            ? implode('; ', $context->keyFactors)
            : '-';

        $heroFindingsLines = [];
        foreach ($context->heroFindings as $f) {
            $hero = $f['hero'] ?? 'Unknown';
            $role = $f['role'] ?? '';
            $issue = $f['issue'] ?? '';
            $impact = $f['team_impact'] ?? '';
            $heroFindingsLines[] = "- {$hero} ({$role}): {$issue} → {$impact}";
        }
        $heroFindingsBlock = ! empty($heroFindingsLines)
            ? implode("\n", $heroFindingsLines)
            : '- (tidak ada data hero)';

        return <<<PROMPT
            Mode: {$context->gameMode} — {$context->durationMinutes} menit — Tim {$context->memberTeam}
            Heroes kita: {$memberHeroes}

            Diagnosis:
            Tipe kekalahan: {$context->lossTypeConfirmed}
            Penyebab utama: {$context->rootCause}
            Faktor kunci: {$keyFactors}
            Momentum: {$context->momentum}

            Hero findings:
            {$heroFindingsBlock}

            Buat 4–6 bullet point analisis kekalahan ini. Setiap hero di hero findings WAJIB disebut minimal sekali.
            PROMPT;
    }
}
