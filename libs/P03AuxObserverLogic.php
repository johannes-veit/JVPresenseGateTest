<?php

declare(strict_types=1);

final class P03AuxObserverLogic
{
    /**
     * A dedicated P03 Human CrossRegion rule is the only source of a mast proof.
     * The camera rule Name is authoritative. Different Dahua firmwares may send
     * CfgRuleId, RuleID and RuleId with unrelated values, and event "index" is
     * the video channel, NOT the VideoAnalyseRule table index.
     *
     * Without Name, accept numeric identity only if ALL supplied rule IDs are
     * consistent with the configured rule Id. Ambiguous events fail closed.
     *
     * @param array<string,mixed> $event
     */
    public static function isMatchingRuleEvent(
        array $event,
        string $wantedName,
        int $wantedIndex,
        int $wantedId
    ): bool {
        if (strcasecmp(trim((string) ($event['code'] ?? '')), 'CrossRegionDetection') !== 0) {
            return false;
        }
        $name = trim((string) ($event['ruleName'] ?? ''));
        if ($name !== '') {
            return $wantedName !== '' && strcasecmp($name, $wantedName) === 0;
        }

        if ($wantedId < 0) {
            return false;
        }
        $found = false;
        foreach (['cfgRuleId', 'ruleIdUpper', 'ruleIdLower', 'ruleId'] as $field) {
            $value = $event[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_numeric($value)) {
                return false;
            }
            $found = true;
            if ((int) $value !== $wantedId) {
                return false;
            }
        }
        return $found;
    }

    /**
     * The rule itself enforces Human, but an explicitly nonhuman object in the
     * payload must never create evidence even when the rule name matches.
     *
     * @param array<string,mixed> $event
     */
    public static function isHumanProofStart(
        array $event,
        string $wantedName,
        int $wantedIndex,
        int $wantedId
    ): bool {
        $action = strtolower(trim((string) ($event['action'] ?? '')));
        if (!in_array($action, ['start', 'on', 'pulse'], true)
            || !self::isMatchingRuleEvent($event, $wantedName, $wantedIndex, $wantedId)) {
            return false;
        }

        $classification = strtolower(trim((string) ($event['classification'] ?? '')));
        if ($classification !== ''
            && !in_array($classification, ['human', 'person', 'pedestrian'], true)) {
            return false;
        }
        return true;
    }

    /**
     * Dahua EventID is not a permanent globally unique transaction ID.
     * Some 5442 firmware uses stable/recycled identifiers across detections.
     *
     * Only suppress repeated identical deliveries in a short window; a new
     * genuine Start carrying the same EventID seconds later MUST be counted.
     * Include rule/action/group/object to avoid collisions where available.
     *
     * @param array<string,mixed> $event
     * @param array<string,int> $seen
     */
    public static function isFreshDelivery(array $event, array &$seen, int $now, int $windowSeconds = 3): bool
    {
        $windowSeconds = max(1, min(10, $windowSeconds));
        foreach ($seen as $key => $timestamp) {
            if (!is_numeric($timestamp) || (int) $timestamp > $now
                || $now - (int) $timestamp >= $windowSeconds) {
                unset($seen[$key]);
            }
        }

        $eventId = trim((string) ($event['eventId'] ?? ''));
        $key = $eventId !== ''
            ? 'id:' . $eventId
            : 'raw:' . sha1((string) ($event['raw'] ?? ''));
        $key .= '|a:' . strtolower(trim((string) ($event['action'] ?? '')));
        $key .= '|r:' . strtolower(trim((string) ($event['ruleName'] ?? '')))
            . ':' . trim((string) ($event['cfgRuleId'] ?? $event['ruleId'] ?? ''));
        $key .= '|g:' . trim((string) ($event['groupId'] ?? ''));
        $key .= '|o:' . trim((string) ($event['objectId'] ?? ''));

        if (isset($seen[$key]) && $now - (int) $seen[$key] < $windowSeconds) {
            return false;
        }

        $seen[$key] = $now;
        if (count($seen) > 200) {
            asort($seen, SORT_NUMERIC);
            $seen = array_slice($seen, -200, null, true);
        }
        return true;
    }
}
