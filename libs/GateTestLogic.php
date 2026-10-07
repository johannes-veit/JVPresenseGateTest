<?php

declare(strict_types=1);

final class GateTestLogic
{
    /** @return array<int,array<string,mixed>> */
    public static function parseRules(string $raw): array
    {
        $rules = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(?:table\.)?VideoAnalyseRule\[0\]\[(\d+)\]\.([^=]+)=(.*)$/', $line, $m)) {
                continue;
            }
            $idx = (int) $m[1];
            $key = trim((string) $m[2]);
            $value = trim((string) $m[3]);
            $rules[$idx] ??= [];
            $rules[$idx][$key] = $value;
        }
        ksort($rules);
        return $rules;
    }

    public static function findRuleIndex(array $rules, string $name): ?int
    {
        foreach ($rules as $idx => $rule) {
            if (strcasecmp((string) ($rule['Name'] ?? ''), $name) === 0) {
                return (int) $idx;
            }
        }
        return null;
    }

    public static function firstFreeRuleIndex(array $rules, int $maxRules = 10): ?int
    {
        for ($i = 0; $i < $maxRules; $i++) {
            if (!array_key_exists($i, $rules)) {
                return $i;
            }
        }
        return null;
    }

    public static function configValue(string $raw, string $fullKey): ?string
    {
        $quoted = preg_quote($fullKey, '/');
        if (preg_match('/^(?:table\.)?' . $quoted . '=(.*)$/mi', $raw, $m)) {
            return trim((string) $m[1]);
        }
        return null;
    }

    /** @param array<string,mixed> $event */
    public static function directionFromEvent(array $event): ?string
    {
        $data = $event['data'] ?? null;
        if (is_array($data)) {
            $found = self::findDirectionRecursive($data);
            if ($found !== null) {
                return $found;
            }
        }

        $raw = (string) ($event['raw'] ?? '');
        if ($raw !== '' && preg_match('/(?:Direction|MoveDirection|CrossDirection)["\']?\s*[:=]\s*["\']?([A-Za-z0-9_\-]+)/i', $raw, $m)) {
            return self::normalizeDirection((string) $m[1]);
        }
        return null;
    }

    /** @param array<string|int,mixed> $node */
    private static function findDirectionRecursive(array $node): ?string
    {
        foreach ($node as $key => $value) {
            $k = strtolower((string) $key);
            if (is_array($value)) {
                $nested = self::findDirectionRecursive($value);
                if ($nested !== null) {
                    return $nested;
                }
                continue;
            }
            if (in_array($k, ['direction', 'movedirection', 'crossdirection'], true) && (is_string($value) || is_numeric($value))) {
                return self::normalizeDirection((string) $value);
            }
        }
        return null;
    }

    private static function normalizeDirection(string $value): string
    {
        $value = trim($value, " \t\r\n\"'");
        $compact = strtolower(str_replace([' ', '_', '-'], '', $value));
        return match ($compact) {
            'lefttoright', 'a2b', 'atob' => 'LeftToRight',
            'righttoleft', 'b2a', 'btoa' => 'RightToLeft',
            default => $value
        };
    }

    /** @param array<int,array<string,mixed>> $crossings */
    public static function evaluateFourCrossings(array $crossings): array
    {
        if (count($crossings) < 4) {
            return ['complete' => false, 'valid' => false, 'message' => 'Noch keine vier eindeutigen Grenzübertritte'];
        }
        $c = array_values(array_slice($crossings, 0, 4));
        $dirs = array_map(static fn(array $x) => $x['direction'] ?? null, $c);
        if (in_array(null, $dirs, true) || in_array('', $dirs, true)) {
            return ['complete' => true, 'valid' => false, 'message' => 'Vier Events empfangen, aber mindestens ein Direction-Feld fehlt', 'directions' => $dirs];
        }
        $valid = $dirs[0] === $dirs[2] && $dirs[1] === $dirs[3] && $dirs[0] !== $dirs[1];
        if (!$valid) {
            return ['complete' => true, 'valid' => false, 'message' => 'Richtungsfolge ist nicht stabil OUT/IN/OUT/IN', 'directions' => $dirs];
        }
        return [
            'complete' => true,
            'valid' => true,
            'outDirection' => $dirs[0],
            'inDirection' => $dirs[1],
            'message' => 'Richtungszuordnung eindeutig'
        ];
    }
}
