<?php

namespace App\DataObjects\Analysis;

readonly class DerivedMetrics
{
    public function __construct(
        /** Fraction of teamfights won by member team [0..1] */
        public float $fightControlRatio,
        /** Fraction of fight wins followed by an objective within 90s [0..1] */
        public float $objectiveConversionRate,
        /** Fraction of member deaths that happened outside fight windows [0..1] */
        public float $enemyPickoffRate,
        /** 1 - (core deaths before 20 min / core total deaths) [0..1] */
        public float $protectionIndex,
        /** Top member damage dealer's share of team hero damage [0..1] */
        public float $damageConcentrationRatio,
        /** Average gold advantage (member-team perspective) minutes 0–14 */
        public int $scalingEarlyGold,
        /** Average gold advantage (member-team perspective) minutes 15–29 */
        public int $scalingMidGold,
        /** Average gold advantage (member-team perspective) minutes 30+ */
        public int $scalingLateGold,
    ) {}
}
