<?php
declare(strict_types=1);

/**
 * Read-only, deterministic occupancy/identity hypothesis evaluator.
 *
 * This is a SHADOW component, NOT an IP-Symcon module. It never performs
 * device I/O, persists objects, or changes P03/P05/PresenceCore.
 *
 * All motion events must arrive as independently certified boundary proofs.
 * Phone/WLAN, light, motion silence, gates and vehicles are hints only.
 * No face recognition or new hardware is needed or assumed.
 */
final class JVIdentityHypothesisEngine
{
    public const SCHEMA = 1;
    public const INSIDE = ['HOME', 'WORK', 'PROPERTY_OTHER'];
    public const ZONES = ['HOME', 'WORK', 'PROPERTY_OTHER', 'AWAY'];
    private const MAX_PEOPLE = 24;
    private const MAX_UNKNOWN = 24;
    private const MAX_EVENTS = 200;
    private const MAX_WORLDS = 256;

    /**
     * @param array<string,string> $people Stable person IDs => proven baseline zone.
     * @param array<string,int> $unknown Known anonymous occupants, by zone.
     * @param array<string,bool> $coverage Audited coverage flags: home, house, property.
     * @param array<int,array> $events Ordered, normalized evidence.
     *
     * baselineCertified is EXTERNAL proof of the starting full person inventory;
     * do not set it from quiet sensors, light, Wi-Fi or vehicle status.
     */
    public static function evaluate(
        array $people, array $unknown, array $coverage,
        bool $baselineCertified, array $events
    ): array {
        $warnings = [];
        $observedPhones = [];
        $world = ['people'=>[], 'unknown'=>array_fill_keys(self::INSIDE, 0)];
        if (count($people) > self::MAX_PEOPLE) {
            return self::blocked('PEOPLE_LIMIT', $people);
        }
        foreach ($people as $id=>$zone) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)
                || !is_string($zone) || !in_array($zone, self::ZONES, true)) {
                return self::blocked('INVALID_BASELINE', $people);
            }
            $world['people'][$id] = $zone;
        }
        ksort($world['people']);
        foreach (self::INSIDE as $zone) {
            $n = $unknown[$zone] ?? 0;
            if (!is_int($n) || $n < 0 || $n > self::MAX_UNKNOWN) {
                return self::blocked('INVALID_UNKNOWN_COUNT', $people);
            }
            $world['unknown'][$zone] = $n;
        }
        if (array_diff(array_keys($unknown), self::INSIDE)) {
            return self::blocked('UNKNOWN_ZONE', $people);
        }
        if (count($events) > self::MAX_EVENTS) {
            return self::blocked('EVENT_LIMIT', $people);
        }
        $worlds = [$world];
        $seen = [];
        $lastTime = 0;
        $invalidated = [];
        $stopped = false;
        $verifiedTransfers = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                $warnings[] = 'INVALID_EVENT';
                $stopped = true;
                break;
            }
            $id = $event['id'] ?? null;
            $time = $event['time'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9:._-]{1,128}$/D', $id)
                || !is_int($time) || $time <= 0) {
                $warnings[] = 'INVALID_EVENT_META';
                $stopped = true;
                break;
            }
            $encoded = json_encode($event);
            if (isset($seen[$id])) {
                if ($seen[$id] !== $encoded) {
                    $warnings[] = 'CONFLICTING_EVENT_ID';
                    $stopped = true;
                    break;
                }
                continue; // exact replay is idempotent
            }
            if ($time < $lastTime) {
                $warnings[] = 'OUT_OF_ORDER_EVENT';
                $stopped = true;
                break;
            }
            $lastTime = $time;
            $seen[$id] = $encoded;
            $type = $event['type'] ?? '';
            if ($type === 'PHONE') {
                // A phone can be left behind, powered off or borrowed.
                $observedPhones[] = [
                    'personId'=>(string)($event['personId'] ?? ''),
                    'status'=>(string)($event['status'] ?? 'PHONE_UNKNOWN')
                ];
                continue;
            }
            if (in_array($type, ['LIGHT', 'SILENCE', 'GATE', 'VEHICLE', 'WIFI'], true)) {
                continue; // contextual only; never a transition/certificate
            }
            if ($type === 'ACTIVITY') {
                $zone = $event['zone'] ?? '';
                if (!in_array($zone, self::INSIDE, true)) {
                    $warnings[] = 'INVALID_ACTIVITY_ZONE';
                    $stopped = true;
                    break;
                }
                // GUS may be false-positive. It does not add a person, but it
                // invalidates an otherwise claimed empty area until rebaselined.
                $invalidated[$zone] = true;
                continue;
            }
            if ($type !== 'TRANSFER') {
                $warnings[] = 'UNKNOWN_EVENT_TYPE';
                $stopped = true;
                break;
            }
            $from = $event['from'] ?? null;
            $to = $event['to'] ?? null;
            $n = $event['count'] ?? null;
            $proof = $event['proof'] ?? null;
            if (!is_string($from) || !is_string($to)
                || !in_array($from, self::ZONES, true)
                || !in_array($to, self::ZONES, true)
                || $from === $to || !is_int($n) || $n < 1 || $n > self::MAX_PEOPLE
                || !self::proofComplete($proof)) {
                $warnings[] = 'TRANSFER_UNVERIFIED';
                $stopped = true;
                break;
            }
            $next = [];
            foreach ($worlds as $w) {
                $variants = self::transfer($w, $from, $to, $n);
                if ($variants === null || !$variants) {
                    $warnings[] = 'TRANSFER_INCONSISTENT_OR_TOO_AMBIGUOUS';
                    $stopped = true;
                    break;
                }
                foreach ($variants as $v) {
                    $key = self::worldKey($v);
                    $next[$key] = $v;
                    if (count($next) > self::MAX_WORLDS) {
                        $warnings[] = 'HYPOTHESIS_LIMIT';
                        $stopped = true;
                        break 2;
                    }
                }
            }
            if ($stopped) break;
            $worlds = array_values($next);
            $verifiedTransfers++;
        }

        $persons = [];
        foreach (array_keys($world['people']) as $id) {
            $possible = [];
            foreach ($worlds as $w) $possible[$w['people'][$id]] = true;
            $zones = array_keys($possible);
            sort($zones);
            $persons[$id] = [
                'zone'=>count($zones) === 1 ? $zones[0] : 'UNKNOWN',
                'possibleZones'=>$zones,
                'identityResolved'=>count($zones) === 1
            ];
        }
        $counts = [];
        foreach (self::ZONES as $zone) {
            $values = [];
            foreach ($worlds as $w) {
                $n = $w['unknown'][$zone] ?? 0;
                foreach ($w['people'] as $z) if ($z === $zone) $n++;
                $values[] = $n;
            }
            $counts[$zone] = ['min'=>min($values), 'max'=>max($values)];
        }

        $emptyHome = $counts['HOME']['max'] === 0;
        $emptyProperty = $emptyHome
            && $counts['WORK']['max'] === 0
            && $counts['PROPERTY_OTHER']['max'] === 0;
        $safe = $baselineCertified && !$stopped && !$warnings;
        $coverageHome = ($coverage['home'] ?? false) === true;
        $coverageHouse = ($coverage['house'] ?? false) === true;
        $coverageProperty = ($coverage['property'] ?? false) === true;
        $homeClear = !isset($invalidated['HOME']);
        $propertyClear = !$invalidated;
        $certificates = [
            'HOME_EMPTY_CERTIFIED'=>$safe && $coverageHome && $homeClear && $emptyHome,
            'HOUSE_EMPTY_CERTIFIED'=>$safe && $coverageHouse && $homeClear && $emptyHome,
            'PROPERTY_EMPTY_CERTIFIED'=>$safe && $coverageProperty
                && $coverageHouse && $coverageHome && $propertyClear && $emptyProperty
        ];

        $phoneWarnings = [];
        foreach ($observedPhones as $phone) {
            $id = $phone['personId'];
            if (!isset($persons[$id])) continue;
            if ($phone['status'] === 'PHONE_PROPERTY_INDICATION'
                && $persons[$id]['zone'] === 'AWAY') {
                $phoneWarnings[] = 'PHONE_MAY_BE_LEFT_BEHIND:'.$id;
            }
        }
        return [
            'schema'=>self::SCHEMA,
            'authority'=>'SHADOW_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBePublished'=>false,
            'baselineCertified'=>$baselineCertified,
            'worldCount'=>count($worlds),
            'transfersApplied'=>$verifiedTransfers,
            'stopped'=>$stopped,
            'people'=>$persons,
            'counts'=>$counts,
            'certificates'=>$certificates,
            'warnings'=>array_values(array_unique(array_merge($warnings, $phoneWarnings))),
            'unresolvedActivityZones'=>array_keys($invalidated)
        ];
    }

    private static function proofComplete($proof): bool
    {
        if (!is_array($proof)) return false;
        foreach (['human','boundary','direction','count','continuity','unique'] as $k) {
            if (($proof[$k] ?? null) !== true) return false;
        }
        return true;
    }

    private static function transfer(array $world, string $from, string $to, int $n): ?array
    {
        // AWAY is not a closed inventory. A person arriving from OUTSIDE
        // may be a known resident or an unseen visitor. ALWAYS include visitor
        // alternatives; do not claim returning identity through elimination.
        $known = [];
        foreach ($world['people'] as $id=>$zone) {
            if ($zone === $from) $known[] = $id;
        }
        $availableUnknown = $from === 'AWAY' ? $n : $world['unknown'][$from];
        if (count($known) + $availableUnknown < $n) return [];
        $results = [];
        $budget = self::MAX_WORLDS + 1;
        self::enumerateMoves($world, $known, $from, $to, $n, $availableUnknown,
            0, [], $results, $budget);
        if ($budget <= 0 || count($results) > self::MAX_WORLDS) return null; // never silently truncate possibilities
        return array_values($results);
    }

    private static function enumerateMoves(
        array $world, array $known, string $from, string $to, int $n,
        int $availableUnknown, int $index, array $chosen,
        array &$results, int &$budget
    ): void {
        if ($budget <= 0) return;
        if ($index === count($known)) {
            $anonymous = $n - count($chosen);
            if ($anonymous < 0 || $anonymous > $availableUnknown) return;
            $w = $world;
            foreach ($chosen as $id) $w['people'][$id] = $to;
            if ($from !== 'AWAY') $w['unknown'][$from] -= $anonymous;
            if ($to !== 'AWAY') $w['unknown'][$to] += $anonymous;
            if ($w['unknown']['HOME'] > self::MAX_UNKNOWN
                || $w['unknown']['WORK'] > self::MAX_UNKNOWN
                || $w['unknown']['PROPERTY_OTHER'] > self::MAX_UNKNOWN) return;
            $results[self::worldKey($w)] = $w;
            $budget--;
            return;
        }
        $id = $known[$index];
        self::enumerateMoves($world,$known,$from,$to,$n,$availableUnknown,
            $index+1,$chosen,$results,$budget);
        if (count($chosen) < $n) {
            $chosen[] = $id;
            self::enumerateMoves($world,$known,$from,$to,$n,$availableUnknown,
                $index+1,$chosen,$results,$budget);
        }
    }

    private static function worldKey(array $world): string
    {
        ksort($world['people']);
        return json_encode($world, JSON_THROW_ON_ERROR);
    }

    private static function blocked(string $reason, array $people): array
    {
        return [
            'schema'=>self::SCHEMA, 'authority'=>'SHADOW_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBePublished'=>false,
            'baselineCertified'=>false,
            'worldCount'=>0, 'transfersApplied'=>0, 'stopped'=>true,
            'people'=>[], 'counts'=>[], 'certificates'=>[
                'HOME_EMPTY_CERTIFIED'=>false,
                'HOUSE_EMPTY_CERTIFIED'=>false,
                'PROPERTY_EMPTY_CERTIFIED'=>false
            ], 'warnings'=>[$reason], 'unresolvedActivityZones'=>[]
        ];
    }
}
