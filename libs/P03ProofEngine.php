<?php

declare(strict_types=1);

/**
 * Deterministic multi-camera proof engine for portal P03 HOME <-> LAGERPLATZ.
 *
 * The actual P03 transfer is determined by the two mast cameras.
 *
 * - JV_LEFT -> WORK_LEFT = HOME_TO_LAGER
 * - WORK_LEFT -> JV_LEFT = LAGER_TO_HOME
 *
 * Both detections must occur one after another inside the configured time
 * window. Terrace/CrossLine is optional supporting/diagnostic evidence only.
 * No score is used.
 */
final class P03ProofEngine
{
    public const SRC_TERRACE = 'TERRACE';
    public const SRC_JV_LEFT = 'JV_LEFT';
    public const SRC_WORK_LEFT = 'WORK_LEFT';

    public const ACTION_HOME_TO_LAGER = 'HOME_TO_LAGER';
    public const ACTION_LAGER_TO_HOME = 'LAGER_TO_HOME';

    public const STATE_PENDING = 'PENDING';
    public const STATE_VERIFIED = 'VERIFIED';
    public const STATE_STRONG_VERIFIED = 'STRONG_VERIFIED';
    public const STATE_PROVISIONAL = 'PROVISIONAL';
    public const STATE_UNKNOWN = 'UNKNOWN';
    public const STATE_CONTRADICTION = 'CONTRADICTION';

    /**
     * Strict diagnostic-only evaluation of an optional TERRACE IVS event.
     *
     * The user's proven near-field camera event at 19:35:50 carried
     * LeftToRight, RuleID=5 and Human identify the tested OUT orientation.
     * RightToLeft is NOT an OUT event; it can support optional IN fusion
     * through P03TerraceInBackup, but never through this OUT-only helper.
     *
     * No transfer may be booked by this function. All caller state remains
     * unchanged. Only named, independently verified IVS rule identities
     * and already-counted observer Human starts qualify.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $expectedRule
     * @return array{status:string,direction:string,action:?string,reason:string}
     */
    public static function terraceOutOnlyShadow(
        array $event, array $expectedRule
    ): array {
        $direction = trim((string)($event['direction'] ?? ''));
        $rejected = static fn(string $reason): array => [
            'status'=>'IGNORED','direction'=>$direction,'action'=>null,'reason'=>$reason
        ];
        if (strcasecmp((string)($event['code'] ?? ''),'CrossLineDetection') !== 0) {
            return $rejected('NOT_CROSSLINE');
        }
        if (!in_array(strtolower(trim((string)($event['action']??''))),
            ['start','on','pulse'],true)) {
            return $rejected('NOT_START');
        }
        if ((string)($event['decision']??'') !== 'STAIR_HUMAN_START_COUNTED'
            || (string)($event['rejectionReason']??'') !== 'MATCHED_FILTER') {
            return $rejected('NOT_ACCEPTED_OBSERVER_HUMAN');
        }
        $expectedName=trim((string)($expectedRule['name']??''));
        $expectedID=$expectedRule['id']??null;
        if ($expectedName==='' || !is_numeric($expectedID) || (int)$expectedID<0
            || trim((string)($event['ruleName']??''))!==$expectedName) {
            return $rejected('RULE_NAME_OR_EXPECTED_ID_MISMATCH');
        }
        $hasID=false;
        foreach (['cfgRuleId','ruleIdUpper','ruleIdLower','ruleId'] as $field) {
            $actualID=$event[$field]??null;
            if ($actualID===null || $actualID==='') continue;
            if (!is_numeric($actualID) || (int)$actualID!==(int)$expectedID) {
                return $rejected('CAMERA_RULE_ID_CONFLICT');
            }
            $hasID=true;
        }
        if (!$hasID) {
            return $rejected('MISSING_CAMERA_RULE_ID');
        }
        $classification=strtolower(trim((string)($event['classification']??'')));
        if ($classification!==''
            && !in_array($classification,['human','person','pedestrian'],true)) {
            return $rejected('NOT_HUMAN');
        }
        if ($classification==='' && empty($expectedRule['humanOnly'])) {
            return $rejected('MIXED_CLASS_NO_HUMAN_IDENTITY');
        }
        if ($direction!=='LeftToRight') {
            return $rejected($direction==='RightToLeft'
                ? 'OPPOSITE_DIRECTION_NOT_OUT'
                : 'UNKNOWN_DIRECTION_NEVER_PROOF');
        }
        return [
            'status'=>'OUT_CANDIDATE_ONLY',
            'direction'=>'LeftToRight',
            'action'=>self::ACTION_HOME_TO_LAGER,
            'reason'=>'SHADOW_ONLY_OUT_NEEDS_LIVE_DIRECTION_VERIFICATION'
        ];
    }

    /**
     * @param array<string,mixed> $cross
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public static function evaluate(
        array $cross,
        array $events,
        float $now,
        float $nearWindow = 30.0,
        float $terraceWindow = 60.0
    ): array {
        $crossTs = (float) ($cross['ts'] ?? 0.0);
        if ($crossTs <= 0.0) {
            return self::result(self::STATE_UNKNOWN, null, 'NO_CROSS_TIMESTAMP', [], []);
        }

        $events = array_values(array_filter($events, static function ($e) {
            return is_array($e)
                && empty($e['used'])
                && isset($e['ts'], $e['source'])
                && (float) $e['ts'] > 0.0;
        }));

        $preJv = self::latestBefore($events, self::SRC_JV_LEFT, $crossTs, $nearWindow);
        $postJv = self::earliestAfter($events, self::SRC_JV_LEFT, $crossTs, $nearWindow);
        $preWork = self::latestBefore($events, self::SRC_WORK_LEFT, $crossTs, $nearWindow);
        $postWork = self::earliestAfter($events, self::SRC_WORK_LEFT, $crossTs, $nearWindow);
        $preTerrace = self::latestBefore($events, self::SRC_TERRACE, $crossTs, $terraceWindow);
        $postTerrace = self::earliestAfter($events, self::SRC_TERRACE, $crossTs, $terraceWindow);

        // CrossLine may strengthen/diagnose a transfer, but it may never
        // substitute either mast camera. Both mast detections are mandatory.
        $out = $preJv !== null && $postWork !== null;
        $in = $preWork !== null && $postJv !== null;

        if ($out && $in) {
            return self::result(
                self::STATE_CONTRADICTION,
                null,
                'BOTH_MAST_DIRECTIONS_MATCH_AROUND_CROSSLINE',
                [],
                self::evidence($preTerrace, $preJv, $preWork, $postTerrace, $postJv, $postWork)
            );
        }

        if ($out) {
            return self::result(
                self::STATE_STRONG_VERIFIED,
                self::ACTION_HOME_TO_LAGER,
                'P03_OUT_MAST_PAIR_PLUS_CROSSLINE',
                self::ids([$preJv, $postWork]),
                self::evidence($preTerrace, $preJv, $preWork, $postTerrace, $postJv, $postWork)
            );
        }

        if ($in) {
            return self::result(
                self::STATE_STRONG_VERIFIED,
                self::ACTION_LAGER_TO_HOME,
                'P03_IN_MAST_PAIR_PLUS_CROSSLINE',
                self::ids([$preWork, $postJv]),
                self::evidence($preTerrace, $preJv, $preWork, $postTerrace, $postJv, $postWork)
            );
        }

        $maxWait = max($nearWindow, $terraceWindow);
        if ($now <= ($crossTs + $maxWait)) {
            return self::result(
                self::STATE_PENDING,
                null,
                'WAITING_FOR_POST_CONFIRMATION',
                [],
                self::evidence($preTerrace, $preJv, $preWork, $postTerrace, $postJv, $postWork)
            );
        }

        $hasAny = $preTerrace !== null || $preJv !== null || $preWork !== null
            || $postTerrace !== null || $postJv !== null || $postWork !== null;

        return self::result(
            $hasAny ? self::STATE_PROVISIONAL : self::STATE_UNKNOWN,
            null,
            $hasAny ? 'INCOMPLETE_PROOF_PATH' : 'NO_HUMAN_CONFIRMATION',
            [],
            self::evidence($preTerrace, $preJv, $preWork, $postTerrace, $postJv, $postWork)
        );
    }

    /**
     * @param array<string,mixed> $map
     * @return array<string,mixed>
     */
    public static function learnDirection(array $map, ?string $direction, ?string $action): array
    {
        if (!in_array($direction, ['LeftToRight', 'RightToLeft'], true)
            || !in_array($action, [self::ACTION_HOME_TO_LAGER, self::ACTION_LAGER_TO_HOME], true)) {
            return $map;
        }

        foreach (['LeftToRight', 'RightToLeft'] as $dir) {
            if (!isset($map[$dir]) || !is_array($map[$dir])) {
                $map[$dir] = [
                    self::ACTION_HOME_TO_LAGER => 0,
                    self::ACTION_LAGER_TO_HOME => 0
                ];
            }
        }

        $map[$direction][$action] = (int) ($map[$direction][$action] ?? 0) + 1;
        return $map;
    }

    /**
     * Primary P03 transfer proof from the two mast cameras.
     *
     * OUT: JV_LEFT -> WORK_LEFT
     * IN:  WORK_LEFT -> JV_LEFT
     *
     * The order itself defines direction. Both events must be unconsumed and
     * strictly sequential inside maxGap. A short settle delay prevents an
     * immediate reversal/overlap from being guessed as a clean transfer.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public static function evaluateTwoCameraSequence(
        array $events,
        float $now,
        float $maxGap = 30.0,
        float $settleDelay = 2.0
    ): array {
        $events = array_values(array_filter($events, static function ($e) {
            return is_array($e)
                && empty($e['used'])
                && isset($e['ts'], $e['source'])
                && in_array((string) $e['source'], [self::SRC_JV_LEFT, self::SRC_WORK_LEFT], true)
                && (float) $e['ts'] > 0.0;
        }));
        usort($events, static fn(array $a, array $b) => ((float) $a['ts']) <=> ((float) $b['ts']));

        $out = self::latestTwoCameraSequence(
            $events,
            self::SRC_JV_LEFT,
            self::SRC_WORK_LEFT,
            $maxGap
        );
        $in = self::latestTwoCameraSequence(
            $events,
            self::SRC_WORK_LEFT,
            self::SRC_JV_LEFT,
            $maxGap
        );

        if ($out !== null && $in !== null) {
            $outIds = self::ids($out);
            $inIds = self::ids($in);
            $outEnd = (float) ($out[1]['ts'] ?? 0.0);
            $inEnd = (float) ($in[1]['ts'] ?? 0.0);

            // Overlapping opposite paths are ambiguous and fail closed.
            if (array_intersect($outIds, $inIds) !== []
                || abs($outEnd - $inEnd) <= max(0.0, $settleDelay)) {
                return self::result(
                    self::STATE_CONTRADICTION,
                    null,
                    'P03_2CAM_BOTH_DIRECTIONS',
                    [],
                    [
                        'out' => array_map([self::class, 'compact'], $out),
                        'in' => array_map([self::class, 'compact'], $in)
                    ]
                );
            }

            // Two non-overlapping historical transfers may coexist; process latest.
            if ($outEnd > $inEnd) {
                $in = null;
            } else {
                $out = null;
            }
        }

        $sequence = $out ?? $in;
        if ($sequence === null) {
            return self::result(self::STATE_UNKNOWN, null, 'NO_2CAM_SEQUENCE', [], []);
        }

        $endTs = (float) ($sequence[1]['ts'] ?? 0.0);
        if ($now < ($endTs + max(0.0, $settleDelay))) {
            return self::result(
                self::STATE_PENDING,
                null,
                'P03_2CAM_SETTLE',
                [],
                ['sequence' => array_map([self::class, 'compact'], $sequence)]
            );
        }

        $isOut = $out !== null;
        return self::result(
            self::STATE_VERIFIED,
            $isOut ? self::ACTION_HOME_TO_LAGER : self::ACTION_LAGER_TO_HOME,
            $isOut ? 'P03_2CAM_JV_THEN_WORK' : 'P03_2CAM_WORK_THEN_JV',
            self::ids($sequence),
            ['sequence' => array_map([self::class, 'compact'], $sequence)]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>|null
     */
    private static function latestTwoCameraSequence(
        array $events,
        string $firstSource,
        string $secondSource,
        float $maxGap
    ): ?array {
        $best = null;
        $bestEnd = -INF;
        $bestStart = -INF;

        foreach ($events as $i => $first) {
            if (($first['source'] ?? '') !== $firstSource) {
                continue;
            }
            $t1 = (float) ($first['ts'] ?? 0.0);

            foreach ($events as $j => $second) {
                if ($j === $i || ($second['source'] ?? '') !== $secondSource) {
                    continue;
                }
                $t2 = (float) ($second['ts'] ?? 0.0);
                $gap = $t2 - $t1;
                if ($gap <= 0.0 || $gap > max(0.0, $maxGap)) {
                    continue;
                }

                if ($t2 > $bestEnd || ($t2 === $bestEnd && $t1 > $bestStart)) {
                    $best = [$first, $second];
                    $bestEnd = $t2;
                    $bestStart = $t1;
                }
            }
        }

        return $best;
    }

    /**
     * Three-camera fallback when the physical CrossLine is not emitted.
     *
     * OUT: TERRACE -> JV_LEFT -> WORK_LEFT
     * IN:  WORK_LEFT -> JV_LEFT -> TERRACE
     *
     * All three human events are mandatory and may not have been consumed by
     * another transfer. This is a named deterministic proof path, not a score.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public static function evaluateThreeCameraSequence(
        array $events,
        float $now,
        float $nearWindow = 30.0,
        float $terraceWindow = 60.0,
        float $settleDelay = 3.0
    ): array {
        $events = array_values(array_filter($events, static function ($e) {
            return is_array($e)
                && empty($e['used'])
                && isset($e['ts'], $e['source'])
                && (float) $e['ts'] > 0.0;
        }));
        usort($events, static fn(array $a, array $b) => ((float) $a['ts']) <=> ((float) $b['ts']));

        $out = self::latestThreeCameraSequence(
            $events,
            [self::SRC_TERRACE, self::SRC_JV_LEFT, self::SRC_WORK_LEFT],
            [$terraceWindow, $nearWindow]
        );
        $in = self::latestThreeCameraSequence(
            $events,
            [self::SRC_WORK_LEFT, self::SRC_JV_LEFT, self::SRC_TERRACE],
            [$nearWindow, $terraceWindow]
        );

        if ($out !== null && $in !== null) {
            $outEnd = (float) ($out[2]['ts'] ?? 0.0);
            $inEnd = (float) ($in[2]['ts'] ?? 0.0);

            // If two complete opposite sequences overlap in the same unconsumed
            // event window, do not guess. The caller must wait for a clean path.
            $outIds = self::ids($out);
            $inIds = self::ids($in);
            if (array_intersect($outIds, $inIds) !== [] || abs($outEnd - $inEnd) <= max($nearWindow, $terraceWindow)) {
                return self::result(
                    self::STATE_CONTRADICTION,
                    null,
                    'P03_3CAM_BOTH_DIRECTIONS',
                    [],
                    ['out' => array_map([self::class, 'compact'], $out), 'in' => array_map([self::class, 'compact'], $in)]
                );
            }

            // Non-overlapping historical sequences: choose the latest one only.
            if ($outEnd > $inEnd) {
                $in = null;
            } else {
                $out = null;
            }
        }

        $sequence = $out ?? $in;
        if ($sequence === null) {
            return self::result(self::STATE_UNKNOWN, null, 'NO_3CAM_SEQUENCE', [], []);
        }

        $endTs = (float) ($sequence[2]['ts'] ?? 0.0);
        if ($now < ($endTs + max(0.0, $settleDelay))) {
            return self::result(
                self::STATE_PENDING,
                null,
                'P03_3CAM_SETTLE',
                [],
                ['sequence' => array_map([self::class, 'compact'], $sequence)]
            );
        }

        $isOut = $out !== null;
        return self::result(
            self::STATE_STRONG_VERIFIED,
            $isOut ? self::ACTION_HOME_TO_LAGER : self::ACTION_LAGER_TO_HOME,
            $isOut ? 'P03_3CAM_OUT_NO_LINE' : 'P03_3CAM_IN_NO_LINE',
            self::ids($sequence),
            ['sequence' => array_map([self::class, 'compact'], $sequence)]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @param array<int,string> $sources
     * @param array<int,float> $maxGaps
     * @return array<int,array<string,mixed>>|null
     */
    private static function latestThreeCameraSequence(array $events, array $sources, array $maxGaps): ?array
    {
        $best = null;
        $bestEnd = -INF;

        foreach ($events as $i => $first) {
            if (($first['source'] ?? '') !== $sources[0]) {
                continue;
            }
            $t1 = (float) ($first['ts'] ?? 0.0);

            foreach ($events as $j => $second) {
                if ($j === $i || ($second['source'] ?? '') !== $sources[1]) {
                    continue;
                }
                $t2 = (float) ($second['ts'] ?? 0.0);
                if ($t2 < $t1 || ($t2 - $t1) > (float) ($maxGaps[0] ?? 0.0)) {
                    continue;
                }

                foreach ($events as $k => $third) {
                    if ($k === $i || $k === $j || ($third['source'] ?? '') !== $sources[2]) {
                        continue;
                    }
                    $t3 = (float) ($third['ts'] ?? 0.0);
                    if ($t3 < $t2 || ($t3 - $t2) > (float) ($maxGaps[1] ?? 0.0)) {
                        continue;
                    }

                    if ($t3 > $bestEnd) {
                        $best = [$first, $second, $third];
                        $bestEnd = $t3;
                    }
                }
            }
        }

        return $best;
    }

    /** @param array<string,mixed> $map */
    public static function directionStatus(array $map): array
    {
        $resolved = [];
        $contradiction = false;

        foreach (['LeftToRight', 'RightToLeft'] as $dir) {
            $out = (int) ($map[$dir][self::ACTION_HOME_TO_LAGER] ?? 0);
            $in = (int) ($map[$dir][self::ACTION_LAGER_TO_HOME] ?? 0);

            if ($out > 0 && $in > 0) {
                $contradiction = true;
                continue;
            }
            if ($out >= 2) {
                $resolved[$dir] = self::ACTION_HOME_TO_LAGER;
            } elseif ($in >= 2) {
                $resolved[$dir] = self::ACTION_LAGER_TO_HOME;
            }
        }

        $stable = !$contradiction
            && count($resolved) === 2
            && ($resolved['LeftToRight'] ?? null) !== ($resolved['RightToLeft'] ?? null);

        return [
            'stable' => $stable,
            'contradiction' => $contradiction,
            'resolved' => $resolved,
            'counts' => $map
        ];
    }

    /** @param array<int,array<string,mixed>> $events */
    private static function latestBefore(array $events, string $source, float $crossTs, float $window): ?array
    {
        $best = null;
        foreach ($events as $event) {
            if (($event['source'] ?? '') !== $source) {
                continue;
            }
            $ts = (float) ($event['ts'] ?? 0.0);
            if ($ts > $crossTs || $ts < ($crossTs - $window)) {
                continue;
            }
            if ($best === null || $ts > (float) $best['ts']) {
                $best = $event;
            }
        }
        return $best;
    }

    /** @param array<int,array<string,mixed>> $events */
    private static function earliestAfter(array $events, string $source, float $crossTs, float $window): ?array
    {
        $best = null;
        foreach ($events as $event) {
            if (($event['source'] ?? '') !== $source) {
                continue;
            }
            $ts = (float) ($event['ts'] ?? 0.0);
            if ($ts < $crossTs || $ts > ($crossTs + $window)) {
                continue;
            }
            if ($best === null || $ts < (float) $best['ts']) {
                $best = $event;
            }
        }
        return $best;
    }

    private static function evidence(
        ?array $preTerrace,
        ?array $preJv,
        ?array $preWork,
        ?array $postTerrace,
        ?array $postJv,
        ?array $postWork
    ): array {
        return [
            'preTerrace' => self::compact($preTerrace),
            'preJv' => self::compact($preJv),
            'preWork' => self::compact($preWork),
            'postTerrace' => self::compact($postTerrace),
            'postJv' => self::compact($postJv),
            'postWork' => self::compact($postWork)
        ];
    }

    private static function compact(?array $event): ?array
    {
        if ($event === null) {
            return null;
        }
        return [
            'id' => (string) ($event['id'] ?? ''),
            'source' => (string) ($event['source'] ?? ''),
            'ts' => (float) ($event['ts'] ?? 0.0)
        ];
    }

    /** @param array<int,array<string,mixed>> $events */
    private static function ids(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $id = (string) ($event['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function result(string $state, ?string $action, string $path, array $usedIds, array $evidence): array
    {
        return [
            'state' => $state,
            'action' => $action,
            'path' => $path,
            'usedEventIds' => $usedIds,
            'evidence' => $evidence
        ];
    }
}
