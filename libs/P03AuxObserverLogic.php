<?php

declare(strict_types=1);

final class P03AuxObserverLogic
{
    /**
     * A matching event from the dedicated P03 CrossRegion rule is already
     * Human-filtered by the camera rule itself (ObjectTypes=Human).
     * The event payload therefore does not have to repeat ObjectType=Human.
     *
     * Rule identity is matched fail-closed:
     * 1) If Dahua supplies the configured rule Name, it must match exactly.
     * 2) If Name is absent, any of the separately parsed Dahua rule IDs may match.
     *
     * @param array<string,mixed> $event
     */
    public static function isHumanProofStart(array $event, int $wantedRuleId, string $wantedRuleName = ''): bool
    {
        $action = strtolower(trim((string) ($event['action'] ?? '')));
        return in_array($action, ['start', 'on', 'pulse'], true)
            && self::isMatchingRuleEvent($event, $wantedRuleId, $wantedRuleName);
    }

    /**
     * @param array<string,mixed> $event
     */
    public static function isMatchingRuleEvent(array $event, int $wantedRuleId, string $wantedRuleName = ''): bool
    {
        if (strcasecmp(trim((string) ($event['code'] ?? '')), 'CrossRegionDetection') !== 0) {
            return false;
        }

        $wantedRuleName = trim($wantedRuleName);
        $eventRuleName = trim((string) ($event['ruleName'] ?? ''));

        // A supplied Dahua rule name is authoritative for our uniquely named
        // P03-owned rules. An explicit different name must not be rescued by
        // an ambiguous numeric ID.
        if ($eventRuleName !== '' && $wantedRuleName !== '') {
            return strcasecmp($eventRuleName, $wantedRuleName) === 0;
        }

        if ($wantedRuleId < 0) {
            return false;
        }

        $candidates = $event['ruleIds'] ?? [];
        if (!is_array($candidates) || $candidates === []) {
            $legacy = $event['ruleId'] ?? null;
            $candidates = $legacy === null ? [] : [$legacy];
        }

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate === $wantedRuleId) {
                return true;
            }
        }

        return false;
    }
}
