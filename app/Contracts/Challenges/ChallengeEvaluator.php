<?php

namespace App\Contracts\Challenges;

use App\DataObjects\Challenges\EvaluationResult;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use Illuminate\Support\Collection;

interface ChallengeEvaluator
{
    /**
     * Evaluate a match against a challenge for the given participating members.
     *
     * Evaluators are pure — they calculate results but never persist.
     * All database mutations are owned by ChallengeProgressService.
     *
     * @param  Collection<int, \App\Models\Member>  $participatingMembers  Members already filtered to the destination
     */
    public function evaluate(
        DestinationChallenge $challenge,
        DotaMatch $match,
        Collection $participatingMembers
    ): EvaluationResult;
}
