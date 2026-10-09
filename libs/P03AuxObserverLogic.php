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

        // Exact configured rule name is the strongest match.
        if ($eventRuleName !== '' && $wantedRuleName !== ''
            && strcasecmp($eventRuleName, $wantedRuleName) === 0) {
            return true;
        }

        if ($wantedRuleId < 0) {
            return false;
        }

        // CfgRuleId and RuleID are the strong Dahua identifiers observed on
        // IVS events. Either may identify the configured rule.
        foreach ([
            $event['cfgRuleId'] ?? null,
            $event['ruleIdPrimary'] ?? null,
        ] as $strongId) {
            if ($strongId !== null && is_numeric($strongId) && (int) $strongId === $wantedRuleId) {
                return true;
            }
        }

        // If Dahua explicitly names another rule, never fall back to the weak
        // legacy RuleId/array-position-like identifier.
        if ($eventRuleName !== '' && $wantedRuleName !== '') {
            return false;
        }

        $legacyId = $event['ruleIdLegacy'] ?? null;
        if ($legacyId !== null && is_numeric($legacyId) && (int) $legacyId === $wantedRuleId) {
            return true;
        }

        // Compatibility for synthetic/older parsed events that only expose
        // ruleIds/ruleId and no separated strong fields.
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
