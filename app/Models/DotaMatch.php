<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DotaMatch extends Model
{
    /** @use HasFactory<\Database\Factories\DotaMatchFactory> */
    use HasFactory;

    protected $fillable = [
        'match_id',
        'match_timestamp',
        'match_data',
        'members',
        'notified_at',
        'resend_count',
        'last_resent_at',
        'parse_status',
        'parse_job_id',
        'parse_requested_at',
        'parse_completed_at',
        'parse_retry_count',
        'ai_analysis',
        'analysis_data',
        'ai_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'match_data' => 'array',
            'members' => 'array',
            'analysis_data' => 'array',
            'match_timestamp' => 'datetime',
            'notified_at' => 'datetime',
            'last_resent_at' => 'datetime',
            'parse_requested_at' => 'datetime',
            'parse_completed_at' => 'datetime',
            'ai_analyzed_at' => 'datetime',
        ];
    }

    public function memberModels(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'members');
    }

    protected function team(): Attribute
    {
        return Attribute::make(
            get: function () {
                $matchData = $this->match_data;
                $memberIds = $this->members;

                if (empty($matchData['players']) || empty($memberIds)) {
                    return 'Unknown';
                }

                $members = Member::whereIn('id', $memberIds)->get()->keyBy('steam_id');

                foreach ($matchData['players'] as $player) {
                    $accountId = $player['account_id'] ?? null;

                    if (! $accountId) {
                        continue;
                    }

                    $steamId = Member::convertAccountIdToSteamId($accountId);

                    if ($members->has($steamId)) {
                        $playerSlot = $player['player_slot'] ?? 0;
                        $isRadiant = $playerSlot < 128;

                        return $isRadiant ? 'Radiant' : 'Dire';
                    }
                }

                return 'Unknown';
            }
        );
    }

    protected function outcome(): Attribute
    {
        return Attribute::make(
            get: function () {
                $matchData = $this->match_data;
                $memberIds = $this->members;

                return static::matchOutcome($matchData, $memberIds);
            }
        );
    }

    /**
     * Get computed metrics from analysis pipeline
     */
    public function getComputedMetrics(): ?array
    {
        return $this->analysis_data;
    }

    /**
     * Check if match has been analyzed by the pipeline
     */
    public function hasAnalysis(): bool
    {
        return ! empty($this->ai_analysis) && ! empty($this->analysis_data);
    }

    /**
     * Determine if the outcome was a win
     */
    public static function matchOutcome(array $matchData, array $memberIds): string
    {
        if (empty($matchData['players']) || empty($memberIds)) {
            return 'Unknown';
        }

        $radiantWin = $matchData['radiant_win'] ?? false;
        $members = Member::whereIn('id', $memberIds)->get()->keyBy('steam_id');

        foreach ($matchData['players'] as $player) {
            $accountId = $player['account_id'] ?? null;

            if (! $accountId) {
                continue;
            }

            $steamId = Member::convertAccountIdToSteamId($accountId);

            if ($members->has($steamId)) {
                $playerSlot = $player['player_slot'] ?? 0;
                $isRadiant = $playerSlot < 128;

                return ($isRadiant === $radiantWin) ? 'Won' : 'Lost';
            }
        }

        return 'Unknown';
    }
}
