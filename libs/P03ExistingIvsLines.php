<?php
declare(strict_types=1);

/**
 * Non-destructive, fail-closed selection of existing Dahua IVS staircase lines.
 * P03 must never infer a rule ID from a GUI row number or from the camera's
 * eventManager channel index. A street line must NEVER become a HOME/LAGER proof.
 */
final class P03ExistingIvsLines
{
    /** @param array<int,mixed> $rules */
    public static function select(array $rules): array
    {
        $all = [];
        $stairs = [];
        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $name = trim((string)($rule['Name'] ?? ''));
            $type = trim((string)($rule['Type'] ?? ''));
            $enabled = ($rule['Enable'] ?? false) === true
                || strtolower((string)($rule['Enable'] ?? '')) === 'true';
            if (!$enabled || strcasecmp($type, 'CrossLineDetection') !== 0) {
                continue;
            }
            $id = $rule['Id'] ?? null;
            $classification = array_values(array_map('strtolower',
                array_filter(array_map('strval',
                    is_array($rule['ObjectTypes'] ?? null) ? $rule['ObjectTypes'] : []))));
            $humanOnly = count($classification) === 1 && $classification[0] === 'human';
            $info = ['name' => $name, 'id' => is_numeric($id) ? (int)$id : -1,
                'tableIndex' => (int)$index, 'humanOnly' => $humanOnly,
                'objects' => $classification];
            $all[] = $info;
            // The camera web UI displays the first part of the user-given
            // label, so the untruncated camera API must corroborate "Treppe".
            if (preg_match('/trepp/i', $name)
                && !preg_match('/stra(?:ss|ß)e|street/i', $name)
                && $name !== '' && $info['id'] >= 0) {
                $stairs[] = $info;
            }
        }
        if (count($stairs) !== 1) {
            return ['mapped' => false, 'allowed' => [],
                'reason' => count($stairs) === 0
                    ? 'KEINE eindeutig lesbare aktive Treppen-CrossLine im Dahua VideoAnalyseRule-API'
                    : 'MEHRERE Treppen-CrossLine-Regeln – eindeutige Auswahl unmöglich',
                'all' => $all];
        }
        return ['mapped' => true, 'allowed' => $stairs,
            'reason' => 'Eine aktive Treppen-CrossLine eindeutig identifiziert',
            'all' => $all];
    }

    /** @param array<string,mixed> $event
     *  @param array<int,array<string,mixed>> $allowed
     */
    public static function matchingHumanStart(array $event, array $allowed): bool
    {
        if (strcasecmp((string)($event['code'] ?? ''), 'CrossLineDetection') !== 0
            || !in_array(strtolower(trim((string)($event['action'] ?? ''))),
                ['start','on','pulse'], true)) {
            return false;
        }
        $eventName = trim((string)($event['ruleName'] ?? ''));
        foreach ($allowed as $rule) {
            if (!is_array($rule) || trim((string)($rule['name'] ?? '')) === ''
                || (int)($rule['id'] ?? -1) < 0) {
                continue;
            }
            if ($eventName !== '') {
                if (strcasecmp($eventName, (string)$rule['name']) !== 0) {
                    continue;
                }
                // Even a matching name must not override an explicitly
                // inconsistent camera rule ID.
            }
            $hasNumeric = false;
            $identityOK = true;
            foreach (['cfgRuleId','ruleIdUpper','ruleIdLower','ruleId'] as $field) {
                $value = $event[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (!is_numeric($value) || (int)$value !== (int)$rule['id']) {
                    $identityOK = false;
                    break;
                }
                $hasNumeric = true;
            }
            if (!$identityOK || ($eventName === '' && !$hasNumeric)) {
                continue;
            }
            $classification = strtolower(trim((string)($event['classification'] ?? '')));
            if ($classification !== '') {
                return in_array($classification, ['human','person','pedestrian'], true);
            }
            // Vehicle+Human camera rule without a per-event classification:
            // impossible to distinguish. Never pretend this is a person.
            if (!empty($rule['humanOnly'])) {
                return true;
            }
            return false;
        }
        return false;
    }
}
