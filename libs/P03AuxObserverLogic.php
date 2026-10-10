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
}
