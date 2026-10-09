<?php

declare(strict_types=1);

final class JVP03DahuaEventParser
{
    private const MAX_CARRY = 524288;

    /**
     * Nimmt beliebige TCP-/HTTP-Stream-Chunks entgegen und liefert nur vollständig
     * zusammengesetzte Dahua-Ereignisse zurück. Unterstützt sowohl einzeilige
     * SMD-Ereignisse als auch mehrzeilige IVS-JSON-Daten.
     *
     * @return array<int,array{
     *   code:string,
     *   action:string,
     *   index:int,
     *   human:bool,
     *   classification:string|null,
     *   eventId:int|string|null,
     *   ruleId:int|string|null,
     *   cfgRuleId:int|string|null,
     *   ruleIdUpper:int|string|null,
     *   ruleIdLower:int|string|null,
     *   ruleName:string|null,
     *   groupId:int|string|null,
     *   objectId:int|string|null,
     *   raw:string,
     *   data:array<string|int,mixed>|null
     * }>
     */
    public static function feed(string $chunk, string &$carry): array
    {
        if ($chunk !== '') {
            $carry .= $chunk;
        }
        if (strlen($carry) > self::MAX_CARRY) {
            // Möglichst ab dem letzten vollständigen Eventanfang weiterarbeiten.
            $lastCode = strripos($carry, 'Code=');
            if ($lastCode !== false && $lastCode > 0) {
                $carry = substr($carry, $lastCode);
            } else {
                $carry = substr($carry, -131072);
            }
        }

        $events = [];
        $guard = 0;
        while ($carry !== '' && ++$guard < 1000) {
            $codePos = stripos($carry, 'Code=');
            if ($codePos === false) {
                // Heartbeats, Multipart-Header und Boundaries können komplett verworfen
                // werden. Eine kurze Endsequenz bleibt erhalten, falls "Code=" über
                // zwei TCP-Chunks geteilt wurde.
                $carry = substr($carry, -4);
                break;
            }

            if ($codePos > 0) {
                $carry = substr($carry, $codePos);
            }

            $length = self::completeEventLength($carry);
            if ($length === null) {
                break;
            }

            $raw = trim(substr($carry, 0, $length));
            $carry = substr($carry, $length);
            $carry = ltrim($carry, "\r\n\t \0\x0B-");

            if ($raw === '') {
                continue;
            }

            $event = self::parseEvent($raw);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private static function completeEventLength(string $buffer): ?int
    {
        if (!preg_match('/^Code\s*=\s*([^;\r\n]+)\s*;\s*action\s*=\s*([^;\r\n]+)\s*;\s*index\s*=\s*([^;\r\n]+)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            // Noch kein vollständiger Event-Kopf vorhanden.
            $lineEnd = self::firstLineEnd($buffer);
            if ($lineEnd !== null) {
                // Defekter/anderer Code-Block: diese Zeile verwerfen, um den Stream
                // nicht dauerhaft zu blockieren.
                return $lineEnd;
            }
            return null;
        }

        $headerEnd = strlen((string) $m[0][0]);
        $nextCode = self::nextCodePosition($buffer, 5);
        $boundary = self::boundaryPosition($buffer, $headerEnd);
        $lineEnd = self::firstLineEnd($buffer);

        // data= kann direkt nach index oder später in derselben Headerzeile folgen.
        $searchEndCandidates = array_filter([$nextCode, $boundary, $lineEnd], static fn($v) => $v !== null);
        $searchEnd = $searchEndCandidates === [] ? strlen($buffer) : min($searchEndCandidates);
        $headerSlice = substr($buffer, 0, $searchEnd);
        $dataPos = false;
        $dataTokenLength = 0;
        if (preg_match('/;\s*data\s*=\s*/i', $headerSlice, $dataMatch, PREG_OFFSET_CAPTURE)) {
            $dataPos = (int) $dataMatch[0][1];
            $dataTokenLength = strlen((string) $dataMatch[0][0]);
        }

        if ($dataPos === false) {
            // Standardevent ohne JSON: eine vollständige Zeile genügt. Einige Firmwares
            // trennen Events direkt per Boundary; auch das gilt als Abschluss.
            if ($lineEnd !== null) {
                return $lineEnd;
            }
            if ($boundary !== null) {
                return $boundary;
            }
            if ($nextCode !== null) {
                return $nextCode;
            }
            return null;
        }

        $valueStart = $dataPos + $dataTokenLength;
        while (isset($buffer[$valueStart]) && ctype_space($buffer[$valueStart])) {
            $valueStart++;
        }
        if (!isset($buffer[$valueStart])) {
            return null;
        }

        $first = $buffer[$valueStart];
        if ($first === '{' || $first === '[') {
            $jsonEnd = self::balancedJsonEnd($buffer, $valueStart);
            if ($jsonEnd === null) {
                return null;
            }
            return $jsonEnd + 1;
        }

        // Nicht-JSON-Datainfo: bis Zeilenende/Boundary/nächstes Event lesen.
        $candidates = array_filter([
            self::firstLineEnd($buffer, $valueStart),
            self::boundaryPosition($buffer, $valueStart),
            self::nextCodePosition($buffer, $valueStart)
        ], static fn($v) => $v !== null);
        if ($candidates === []) {
            return null;
        }
        return min($candidates);
    }

    private static function balancedJsonEnd(string $buffer, int $start): ?int
    {
        $open = $buffer[$start] ?? '';
        $close = $open === '{' ? '}' : ($open === '[' ? ']' : '');
        if ($close === '') {
            return null;
        }

        $stack = [];
        $inString = false;
        $escaped = false;
        $length = strlen($buffer);

        for ($i = $start; $i < $length; $i++) {
            $ch = $buffer[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
                continue;
            }
            if ($ch === '}' || $ch === ']') {
                if ($stack === []) {
                    return null;
                }
                $expected = array_pop($stack) === '{' ? '}' : ']';
                if ($ch !== $expected) {
                    return null;
                }
                if ($stack === []) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *   code:string,
     *   action:string,
     *   index:int,
     *   human:bool,
     *   classification:string|null,
     *   eventId:int|string|null,
     *   ruleId:int|string|null,
     *   cfgRuleId:int|string|null,
     *   ruleIdUpper:int|string|null,
     *   ruleIdLower:int|string|null,
     *   ruleName:string|null,
     *   groupId:int|string|null,
     *   objectId:int|string|null,
     *   raw:string,
     *   data:array<string|int,mixed>|null
     * }|null
     */
    private static function parseEvent(string $raw): ?array
    {
        if (!preg_match('/Code\s*=\s*([^;\r\n]+)\s*;\s*action\s*=\s*([^;\r\n]+)\s*;\s*index\s*=\s*([^;\r\n]+)/i', $raw, $m)) {
            return null;
        }

        $code = trim((string) $m[1]);
        $action = trim((string) $m[2]);
        $indexRaw = trim((string) $m[3]);
        $index = is_numeric($indexRaw) ? (int) $indexRaw : 0;

        $data = null;
        $dataRaw = null;
        $dataPos = false;
        $dataTokenLength = 0;
        if (preg_match('/;\s*data\s*=\s*/i', $raw, $dataMatch, PREG_OFFSET_CAPTURE)) {
            $dataPos = (int) $dataMatch[0][1];
            $dataTokenLength = strlen((string) $dataMatch[0][0]);
        }
        if ($dataPos !== false) {
            $dataRaw = trim(substr($raw, $dataPos + $dataTokenLength));
            if ($dataRaw !== '' && ($dataRaw[0] === '{' || $dataRaw[0] === '[')) {
                $decoded = json_decode($dataRaw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        $classification = $data !== null ? self::findClassification($data) : null;
        if ($classification === null && $dataRaw !== null
            && preg_match('/(?:ObjectType|TargetType|ObjectClass|ClassType)["\']?\s*[:=]\s*["\']?([A-Za-z_-]+)/i', $dataRaw, $classMatch)) {
            $classification = trim((string) $classMatch[1]);
        }

        $human = strcasecmp($code, 'SmartMotionHuman') === 0
            || strcasecmp($code, 'HumanDetection') === 0
            || strcasecmp($code, 'HumanBodyDetection') === 0;

        if (!$human && $classification !== null) {
            $human = in_array(strtolower($classification), ['human', 'person', 'pedestrian'], true);
        }
        if (!$human && $data !== null) {
            $human = self::containsHumanClassification($data);
        }
        if (!$human && $dataRaw !== null) {
            $human = (bool) preg_match(
                '/(?:ObjectType|TargetType|ObjectClass|ClassType|Category|Type)["\']?\s*[:=]\s*["\']?(?:Human|Person|Pedestrian)\b/i',
                $dataRaw
            );
        }

        $cfgRuleId = self::findIdentifier($data, ['CfgRuleId', 'CfgRuleID']);
        $ruleIdUpper = self::findIdentifier($data, ['RuleID']);
        $ruleIdLower = self::findIdentifier($data, ['RuleId']);
        $ruleName = self::findString($data, ['Name', 'RuleName']);

        return [
            'code' => $code,
            'action' => $action,
            'index' => $index,
            'human' => $human,
            'classification' => $classification,
            'eventId' => self::findIdentifier($data, ['EventID', 'EventId', 'EventIdEx']),
            // Canonical fallback only. Matching code should prefer ruleName and
            // inspect the three raw identifiers separately because Dahua can emit
            // CfgRuleId, RuleID and RuleId with different values in one event.
            'ruleId' => $cfgRuleId ?? $ruleIdUpper ?? $ruleIdLower,
            'cfgRuleId' => $cfgRuleId,
            'ruleIdUpper' => $ruleIdUpper,
            'ruleIdLower' => $ruleIdLower,
            'ruleName' => $ruleName,
            'groupId' => self::findIdentifier($data, ['GroupID', 'GroupId']),
            'objectId' => self::findIdentifier($data, ['ObjectID', 'ObjectId', 'TrackID', 'TrackId']),
            'raw' => $raw,
            'data' => $data
        ];
    }

    /** @param array<string|int,mixed> $node */
    private static function containsHumanClassification(array $node): bool
    {
        foreach ($node as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (is_array($value)) {
                if (self::containsHumanClassification($value)) {
                    return true;
                }
                continue;
            }

            if (is_bool($value) && $value === true && preg_match('/human|person|pedestrian/', $normalizedKey)) {
                return true;
            }

            if (!is_string($value)) {
                continue;
            }

            $normalizedValue = strtolower(trim($value));
            if (!in_array($normalizedValue, ['human', 'person', 'pedestrian'], true)) {
                continue;
            }

            if (preg_match('/(?:object|target|class|category|type|human|person|pedestrian)/', $normalizedKey)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string|int,mixed> $node */
    private static function findClassification(array $node, bool $objectContext = false): ?string
    {
        $directKeys = ['objecttype', 'targettype', 'objectclass', 'classtype'];
        foreach ($node as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $directKeys, true) && is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if ($objectContext && in_array($normalizedKey, ['type', 'category'], true)
                && is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value)) {
                $childContext = $objectContext || in_array($normalizedKey, ['object', 'objects', 'target', 'targets'], true);
                $nested = self::findClassification($value, $childContext);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string|int,mixed>|null $node
     * @param string[] $keys
     * @return int|string|null
     */
    private static function findIdentifier(?array $node, array $keys): int|string|null
    {
        if ($node === null) {
            return null;
        }
        $wanted = array_map('strtolower', $keys);

        foreach ($node as $key => $value) {
            if (in_array(strtolower((string) $key), $wanted, true) && (is_int($value) || is_string($value))) {
                return $value;
            }
            if (is_array($value)) {
                $nested = self::findIdentifier($value, $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string|int,mixed>|null $node
     * @param string[] $keys
     */
    private static function findString(?array $node, array $keys): ?string
    {
        if ($node === null) {
            return null;
        }
        $wanted = array_map('strtolower', $keys);

        foreach ($node as $key => $value) {
            if (in_array(strtolower((string) $key), $wanted, true) && is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            }
            if (is_array($value)) {
                $nested = self::findString($value, $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    private static function firstLineEnd(string $buffer, int $offset = 0): ?int
    {
        $r = strpos($buffer, "\r\n", $offset);
        $n = strpos($buffer, "\n", $offset);
        $positions = [];
        if ($r !== false) {
            $positions[] = $r + 2;
        }
        if ($n !== false) {
            $positions[] = $n + 1;
        }
        return $positions === [] ? null : min($positions);
    }

    private static function nextCodePosition(string $buffer, int $offset): ?int
    {
        $pos = stripos($buffer, 'Code=', $offset);
        return $pos === false ? null : $pos;
    }

    private static function boundaryPosition(string $buffer, int $offset): ?int
    {
        $positions = [];
        foreach (["\r\n--", "\n--"] as $needle) {
            $pos = strpos($buffer, $needle, $offset);
            if ($pos !== false) {
                $positions[] = $pos;
            }
        }
        return $positions === [] ? null : min($positions);
    }
}
