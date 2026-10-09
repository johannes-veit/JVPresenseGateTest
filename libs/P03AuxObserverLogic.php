<?php

declare(strict_types=1);

final class P03AuxObserverLogic
{
    /**
     * A matching event from the dedicated P03 CrossRegion rule is already
     * Human-filtered by the camera rule itself (ObjectTypes=Human).
     * The event payload does not have to repeat the classification.
     *
     * @param array<string,mixed> $event
     */
    public static function isHumanProofStart(array $event, int $wantedRule): bool
    {
        if ($wantedRule < 0) {
            return false;
        }

        $code = trim((string) ($event['code'] ?? ''));
        $action = strtolower(trim((string) ($event['action'] ?? '')));
        $ruleId = $event['ruleId'] ?? null;

        return strcasecmp($code, 'CrossRegionDetection') === 0
            && in_array($action, ['start', 'on', 'pulse'], true)
            && $ruleId !== null
            && is_numeric($ruleId)
            && (int) $ruleId === $wantedRule;
    }

    /**
     * @param array<string,mixed> $event
     */
    public static function isMatchingRuleEvent(array $event, int $wantedRule): bool
    {
        $code = trim((string) ($event['code'] ?? ''));
        $ruleId = $event['ruleId'] ?? null;

        return $wantedRule >= 0
            && strcasecmp($code, 'CrossRegionDetection') === 0
            && $ruleId !== null
            && is_numeric($ruleId)
            && (int) $ruleId === $wantedRule;
    }
}
