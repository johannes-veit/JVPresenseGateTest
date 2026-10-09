<?php

declare(strict_types=1);

/**
 * Parses Dahua's documented devVideoAnalyse getTemplateRule flat response and
 * locates a native CrossRegionDetection template in arbitrary RPC2 structures.
 */
final class P03DahuaTemplate
{
    /** @return array<string,mixed>|null */
    public static function crossRegionFromFlat(string $raw): ?array
    {
        $rule = [];
        $found = false;

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $valueRaw] = explode('=', $line, 2);
            $key = trim($key);
            $needle = 'Rule.Normal.CrossRegionDetection';
            $pos = stripos($key, $needle);
            if ($pos === false) {
                continue;
            }

            $path = substr($key, $pos + strlen($needle));
            $path = ltrim($path, '.');
            if ($path === '') {
                continue;
            }

            $tokens = self::pathTokens($path);
            if ($tokens === []) {
                continue;
            }

            self::setPath($rule, $tokens, self::parseValue(trim($valueRaw)));
            $found = true;
        }

        if (!$found) {
            return null;
        }

        // Some firmwares omit Type/Class in the template leaf even though the
        // enclosing path already defines both. Add only those two identity fields.
        $rule['Type'] ??= 'CrossRegionDetection';
        $rule['Class'] ??= 'Normal';

        return self::looksLikeCrossRegion($rule) ? $rule : null;
    }

    /** @param mixed $node
     *  @return array<string,mixed>|null
     */
    public static function findCrossRegion($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        if (self::looksLikeCrossRegion($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (strcasecmp((string) $key, 'CrossRegionDetection') === 0 && is_array($value)) {
                $candidate = $value;
                $candidate['Type'] ??= 'CrossRegionDetection';
                $candidate['Class'] ??= 'Normal';
                if (self::looksLikeCrossRegion($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = self::findCrossRegion($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $rule */
    public static function looksLikeCrossRegion(array $rule): bool
    {
        $type = (string) ($rule['Type'] ?? '');
        $config = $rule['Config'] ?? null;
        return strcasecmp($type, 'CrossRegionDetection') === 0
            && is_array($config);
    }

    /** @return array<int,string|int> */
    private static function pathTokens(string $path): array
    {
        $tokens = [];
        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^([^\[]+)/', $segment, $m)) {
                $tokens[] = (string) $m[1];
            }
            if (preg_match_all('/\[(\d+)\]/', $segment, $matches)) {
                foreach ($matches[1] as $idx) {
                    $tokens[] = (int) $idx;
                }
            }
        }
        return $tokens;
    }

    /**
     * @param array<string|int,mixed> $root
     * @param array<int,string|int> $tokens
     * @param mixed $value
     */
    private static function setPath(array &$root, array $tokens, $value): void
    {
        $ref =& $root;
        $last = array_pop($tokens);
        foreach ($tokens as $token) {
            if (!isset($ref[$token]) || !is_array($ref[$token])) {
                $ref[$token] = [];
            }
            $ref =& $ref[$token];
        }
        if ($last !== null) {
            $ref[$last] = $value;
        }
        unset($ref);
    }

    /** @return mixed */
    private static function parseValue(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (($raw[0] ?? '') === '"' && str_ends_with($raw, '"')) {
            $decoded = json_decode($raw, true);
            if (is_string($decoded)) {
                return $decoded;
            }
            return trim($raw, '"');
        }

        if (($raw[0] ?? '') === '[' || ($raw[0] ?? '') === '{') {
            $decoded = json_decode($raw, true);
            if ($decoded !== null || strtolower($raw) === 'null') {
                return $decoded;
            }
        }

        if (strcasecmp($raw, 'true') === 0) {
            return true;
        }
        if (strcasecmp($raw, 'false') === 0) {
            return false;
        }
        if (strcasecmp($raw, 'null') === 0) {
            return null;
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return trim($raw, " \t\r\n\"'");
    }
}
