<?php
declare(strict_types=1);

/**
 * Non-destructive, fail-closed selection of existing Dahua IVS staircase lines.
 * P03 must never infer a rule ID from a GUI row number or from the camera's
 * eventManager channel index. A street line must NEVER become a HOME/LAGER proof.
 */
final class P03ExistingIvsLines
{
    /**
     * Parse only a single, verified channel's IVS rule identity from Dahua's
     * standard CGI getConfig&name=VideoAnalyseRule response. No writing.
     * Different firmware may serialize nested fields in any order.
     * Fail closed if the response has no channel-0 rules.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fromCgi(string $body): array
    {
        $rules = [];
        foreach (preg_split('/\\r?\\n/', $body) ?: [] as $line) {
            if (!preg_match('/^table\\.(?:All\\.)?VideoAnalyseRule\\[0\\]\\[(\\d+)\\]\\.(Name|Type|Class|Enable|Id|ObjectTypes\\[(\\d+)\\])=(.*)$/D', trim($line), $match)) {
                continue;
            }
            $index = (int)$match[1];
            if ($index < 0 || $index > 128) {
                continue;
            }
            $field = $match[2];
            $value = trim($match[4]);
            if ($field === 'Enable') {
                $rules[$index]['Enable'] = strcasecmp($value, 'true') === 0;
            } elseif ($field === 'Id') {
                $rules[$index]['Id'] = ctype_digit($value) ? (int)$value : -1;
            } elseif (str_starts_with($field, 'ObjectTypes[')) {
                $objectIndex = (int)$match[3];
                if ($objectIndex >= 0 && $objectIndex < 32) {
                    $rules[$index]['ObjectTypes'][$objectIndex] = $value;
                }
            } else {
                $rules[$index][$field] = $value;
            }
        }
        ksort($rules, SORT_NUMERIC);
        return $rules;
    }

    /**
     * All camera rules, not just selected CrossLine candidates. An empty list
     * of filtered IVS lines MUST NOT be confused with an empty API response.
     *
     * @param array<int,mixed> $rules
     * @return array<int,array<string,mixed>>
     */
    public static function inventory(array $rules): array
    {
        $out = [];
        foreach ($rules as $idx => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $enable = $rule['Enable'] ?? null;
            $out[] = [
                'index' => (int)$idx,
                'name' => (string)($rule['Name'] ?? ''),
                'type' => (string)($rule['Type'] ?? ''),
                'class' => (string)($rule['Class'] ?? ''),
                'enable' => is_bool($enable) ? $enable
                    : (strcasecmp((string)$enable, 'true') === 0),
                'id' => is_numeric($rule['Id'] ?? null) ? (int)$rule['Id'] : null,
                'objectTypes' => array_values(array_map('strval',
                    is_array($rule['ObjectTypes'] ?? null) ? $rule['ObjectTypes'] : [])),
                'hasLine' => is_array($rule['Config']['DetectLine'] ?? null),
                'hasRegion' => is_array($rule['Config']['DetectRegion'] ?? null)
            ];
            if (count($out) >= 128) {
                break;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function wireReadSummary(array $reply): array
    {
        $json = $reply['json'] ?? [];
        $error = is_array($json) ? ($json['error'] ?? null) : null;
        $table = is_array($json) ? ($json['params']['table'][0] ?? null) : null;
        return [
            'ok' => !empty($reply['ok']),
            'http' => (int)($reply['http'] ?? 0),
            'result' => is_array($json) ? ($json['result'] ?? null) : null,
            'error' => $error,
            'hasTable' => is_array($table),
            'totalRules' => is_array($table) ? count($table) : null
        ];
    }

    /** @param array<string,mixed> $one
     *  @param array<string,mixed> $two
     */
    public static function sameSelectedStair(array $one, array $two): bool
    {
        if (empty($one['mapped']) || empty($two['mapped'])) {
            return false;
        }
        $a = $one['allowed'][0] ?? [];
        $b = $two['allowed'][0] ?? [];
        if (!is_array($a) || !is_array($b)) {
            return false;
        }
        return trim((string)($a['name'] ?? '')) !== ''
            && strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')) === 0
            && (int)($a['id'] ?? -1) >= 0
            && (int)($a['id'] ?? -1) === (int)($b['id'] ?? -2)
            && (bool)($a['humanOnly'] ?? false) === (bool)($b['humanOnly'] ?? true);
    }

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

    /**
     * Strictly diagnostic explanation of why a Dahua event could not qualify
     * as a staircase Human START. This NEVER authorizes a proof or changes
     * matchingHumanStart(). Do not guess the camera's rule from stream index.
     *
     * @param array<string,mixed> $event
     * @param array<int,array<string,mixed>> $allowed
     */
    public static function explainRejection(array $event, array $allowed): string
    {
        if (strcasecmp((string)($event['code'] ?? ''), 'CrossLineDetection') !== 0) {
            return 'NOT_CROSSLINE';
        }
        if (!in_array(strtolower(trim((string)($event['action'] ?? ''))),
            ['start','on','pulse'], true)) {
            return 'NOT_START_OR_PULSE';
        }
        if (count($allowed) !== 1 || !is_array($allowed[0] ?? null)) {
            return 'NO_UNIQUE_VERIFIED_STAIR_RULE';
        }
        $rule = $allowed[0];
        $name = trim((string)($rule['name'] ?? ''));
        $id = (int)($rule['id'] ?? -1);
        if ($name === '' || $id < 0) {
            return 'VERIFIED_STAIR_RULE_INVALID';
        }
        $eventName = trim((string)($event['ruleName'] ?? ''));
        if ($eventName !== '' && strcasecmp($eventName, $name) !== 0) {
            return 'EVENT_RULE_NAME_NOT_STAIR';
        }
        $hasNumeric = false;
        foreach (['cfgRuleId','ruleIdUpper','ruleIdLower','ruleId'] as $key) {
            $value = $event[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_numeric($value) || (int)$value !== $id) {
                return 'CAMERA_EVENT_RULE_ID_CONFLICT';
            }
            $hasNumeric = true;
        }
        if ($eventName === '' && !$hasNumeric) {
            return 'EVENT_HAS_NO_VERIFIABLE_RULE_IDENTITY';
        }
        $type = strtolower(trim((string)($event['classification'] ?? '')));
        if ($type !== '' && !in_array($type, ['human','person','pedestrian'], true)) {
            return 'NOT_HUMAN_CLASSIFICATION';
        }
        if ($type === '' && empty($rule['humanOnly'])) {
            return 'MIXED_HUMAN_VEHICLE_REQUIRES_CLASSIFICATION';
        }
        return 'MATCHED_BY_FILTER_OR_UNEXPECTED_REJECTION';
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
