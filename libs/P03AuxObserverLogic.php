<?php

declare(strict_types=1);

final class P03AuxObserverLogic
{
    /**
     * Match a Dahua CrossRegion event to the P03-owned rule.
     *
     * Rule name is authoritative because Dahua firmwares can expose three
     * different numeric fields in the same event (CfgRuleId, RuleID, RuleId).
     * Numeric ids are only a fallback for firmwares that omit Name.
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

        $eventName = trim((string) ($event['ruleName'] ?? ''));
        if ($eventName !== '') {
            return strcasecmp($eventName, $wantedName) === 0;
        }

        $accepted = [];
        foreach ([$wantedId] as $candidate) {
            if ($candidate >= 0) {
                $accepted[(string) $candidate] = true;
            }
        }
        if ($accepted === []) {
            return false;
        }

        foreach (['cfgRuleId', 'ruleIdUpper', 'ruleIdLower', 'ruleId'] as $field) {
            $value = $event[$field] ?? null;
            if ($value !== null && $value !== '' && isset($accepted[(string) $value])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A matching event from the dedicated P03 rule is already Human-filtered
     * by ObjectTypes=Human. The payload does not have to repeat ObjectType.
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
        return in_array($action, ['start', 'on', 'pulse'], true)
            && self::isMatchingRuleEvent($event, $wantedName, $wantedIndex, $wantedId);
    }
}
