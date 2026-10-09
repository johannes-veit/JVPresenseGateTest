<?php

declare(strict_types=1);

final class JVP03DahuaDigest
{
    /**
     * @return array<string,string>
     */
    public static function parseChallenge(string $header): array
    {
        $header = trim($header);
        if (stripos($header, 'Digest ') === 0) {
            $header = trim(substr($header, 7));
        }

        $result = [];
        if (preg_match_all('/([A-Za-z0-9_-]+)\s*=\s*(?:"([^"]*)"|([^,\s]+))/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower((string) $match[1]);
                $value = $match[2] !== '' ? (string) $match[2] : (string) $match[3];
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * @param array<string,string> $challenge
     */
    public static function buildAuthorization(
        string $username,
        string $password,
        string $method,
        string $uri,
        array $challenge,
        int $nonceCount,
        string $cnonce
    ): string {
        $realm = $challenge['realm'] ?? '';
        $nonce = $challenge['nonce'] ?? '';
        $opaque = $challenge['opaque'] ?? '';
        $algorithm = strtoupper($challenge['algorithm'] ?? 'MD5');
        $qopList = $challenge['qop'] ?? '';

        if ($realm === '' || $nonce === '') {
            throw new InvalidArgumentException('Digest challenge ohne realm/nonce');
        }

        $baseAlgorithm = str_ends_with($algorithm, '-SESS') ? substr($algorithm, 0, -5) : $algorithm;
        $hashAlgorithm = match ($baseAlgorithm) {
            'MD5' => 'md5',
            'SHA-256' => 'sha256',
            default => throw new InvalidArgumentException('Nicht unterstützter Digest-Algorithmus: ' . $algorithm)
        };

        $ha1 = hash($hashAlgorithm, $username . ':' . $realm . ':' . $password);
        if (str_ends_with($algorithm, '-SESS')) {
            $ha1 = hash($hashAlgorithm, $ha1 . ':' . $nonce . ':' . $cnonce);
        }

        $ha2 = hash($hashAlgorithm, $method . ':' . $uri);
        $nc = sprintf('%08x', max(1, $nonceCount));

        $qop = '';
        if ($qopList !== '') {
            $parts = array_map('trim', explode(',', strtolower($qopList)));
            if (in_array('auth', $parts, true)) {
                $qop = 'auth';
            }
        }

        if ($qop !== '') {
            $response = hash($hashAlgorithm, $ha1 . ':' . $nonce . ':' . $nc . ':' . $cnonce . ':' . $qop . ':' . $ha2);
        } else {
            $response = hash($hashAlgorithm, $ha1 . ':' . $nonce . ':' . $ha2);
        }

        $items = [
            'username="' . self::escapeQuoted($username) . '"',
            'realm="' . self::escapeQuoted($realm) . '"',
            'nonce="' . self::escapeQuoted($nonce) . '"',
            'uri="' . self::escapeQuoted($uri) . '"',
            'response="' . $response . '"'
        ];

        if ($algorithm !== '') {
            $items[] = 'algorithm=' . $algorithm;
        }
        if ($opaque !== '') {
            $items[] = 'opaque="' . self::escapeQuoted($opaque) . '"';
        }
        if ($qop !== '') {
            $items[] = 'qop=' . $qop;
            $items[] = 'nc=' . $nc;
            $items[] = 'cnonce="' . self::escapeQuoted($cnonce) . '"';
        }

        return 'Digest ' . implode(', ', $items);
    }

    private static function escapeQuoted(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
