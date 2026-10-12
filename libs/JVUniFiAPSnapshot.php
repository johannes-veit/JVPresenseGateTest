<?php
declare(strict_types=1);

/**
 * Pure parser for a READ-ONLY UniFi AP mca-dump snapshot.
 *
 * This is not a network client. Another locally authorized source must
 * fetch one fresh AP snapshot; no shell/SSH command or credentials live here.
 * We keep only configured phone DeviceIDs, never all nearby station MACs.
 */
final class JVUniFiAPSnapshot
{
    public const MAX_BYTES = 262144;
    public const MAX_VAPS = 64;
    public const MAX_STATIONS = 1024;

    public static function normalizedMac(string $raw): ?string
    {
        $mac = strtolower(str_replace('-', ':', trim($raw)));
        if (!preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/D', $mac)) {
            return null;
        }
        // Broadcast/multicast and all-zero addresses are not a phone identity.
        if ($mac === '00:00:00:00:00:00'
            || (hexdec(substr($mac, 0, 2)) & 1) !== 0) {
            return null;
        }
        return $mac;
    }

    /**
     * @param array<int,array<string,mixed>> $devices Already validated rows.
     * @return array{ok:bool,matchedDeviceIDs:array,error:string,stations:int}
     */
    public static function parse(string $json, array $devices): array
    {
        $fail = static fn(string $error): array => [
            'ok'=>false,'matchedDeviceIDs'=>[],'error'=>$error,'stations'=>0
        ];
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            return $fail('EMPTY_OR_TOO_LARGE');
        }
        $data = json_decode($json,true);
        // mca-dump must be a single JSON object, never a station-array
        // fragment, an event, or a user-supplied MAC list.
        if (!is_array($data) || array_is_list($data)) {
            return $fail('INVALID_JSON_OBJECT');
        }
        if (!isset($data['vap_table']) || !is_array($data['vap_table'])
            || !array_is_list($data['vap_table'])
            || count($data['vap_table']) < 1
            || count($data['vap_table']) > self::MAX_VAPS) {
            return $fail('MISSING_OR_INVALID_VAP_TABLE');
        }
        $known = [];
        foreach ($devices as $device) {
            if (!is_array($device) || empty($device['enabled'])) continue;
            $id = (string)($device['id'] ?? '');
            $mac = self::normalizedMac((string)($device['mac'] ?? ''));
            if ($id !== '' && $mac !== null) $known[$mac][]=$id;
        }
        $matched = [];
        $stationCount=0;
        foreach ($data['vap_table'] as $vap) {
            if (!is_array($vap) || !array_key_exists('sta_table',$vap)
                || !is_array($vap['sta_table'])
                || !array_is_list($vap['sta_table'])) {
                // Missing data is not evidence of a disconnected phone.
                return $fail('INCOMPLETE_STATION_TABLE');
            }
            foreach ($vap['sta_table'] as $sta) {
                if (!is_array($sta) || !isset($sta['mac'])
                    || !is_string($sta['mac'])) {
                    return $fail('MALFORMED_STATION_ROW');
                }
                $stationCount++;
                if ($stationCount>self::MAX_STATIONS) {
                    return $fail('TOO_MANY_STATIONS');
                }
                $mac=self::normalizedMac($sta['mac']);
                if ($mac===null) return $fail('INVALID_STATION_MAC');
                foreach ($known[$mac]??[] as $id) {
                    $matched[$id]=true;
                }
            }
        }
        $ids=array_keys($matched);
        sort($ids,SORT_STRING);
        return [
            'ok'=>true, 'matchedDeviceIDs'=>$ids,
            'error'=>'', 'stations'=>$stationCount
        ];
    }

    /**
     * @param array<string,mixed> $snapshots APID=>{at:int,ok:bool,matchedDeviceIDs:[]}
     * @return array<string,int> phone DeviceID => last credible sighting timestamp
     */
    public static function currentMatches(array $snapshots, int $now, int $ttl): array
    {
        $found=[];
        foreach ($snapshots as $entry) {
            if (!is_array($entry) || empty($entry['ok'])
                || !is_int($entry['at']??null)) continue;
            $at=$entry['at'];
            if ($at<=0 || $at>$now+5 || $now-$at>max(10,$ttl)) continue;
            if (!is_array($entry['matchedDeviceIDs']??null)) continue;
            foreach ($entry['matchedDeviceIDs'] as $id) {
                if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$id)) {
                    continue;
                }
                $found[$id]=max($found[$id]??0,$at);
            }
        }
        return $found;
    }
}
