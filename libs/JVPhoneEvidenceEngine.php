<?php
declare(strict_types=1);

/**
 * Pure phone-device evidence evaluator for IP-Symcon 9.
 * NEVER infers a person's presence, identity or absence from phone data.
 * No Wi-Fi network calls, camera calls, credentials or P03 writes.
 */
final class JVPhoneEvidenceEngine
{
    public const SCHEMA = 1;

    /** @return array{people:array,devices:array,errors:array} */
    public static function validateConfiguration(array $people, array $devices): array
    {
        $errors = [];
        $profileMap = [];
        foreach ($people as $row) {
            if (!is_array($row)) {
                $errors[] = 'Personenprofil ist kein Objekt';
                continue;
            }
            $id = trim((string)($row['PersonID'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)
                || isset($profileMap[$id])) {
                $errors[] = 'Ungültige oder doppelte Personen-ID';
                continue;
            }
            $profileMap[$id] = [
                'id' => $id, 'name' => trim((string)($row['Name'] ?? '')) ?: $id,
                'enabled' => (bool)($row['Enabled'] ?? true)
            ];
        }
        if (!$profileMap) $errors[] = 'Keine gültigen Personenprofile';
        $deviceMap = [];
        $wifiSources = [];
        $geoSources = [];
        $macs = [];
        foreach ($devices as $row) {
            if (!is_array($row)) {
                $errors[] = 'Gerätezeile ist kein Objekt';
                continue;
            }
            $id = trim((string)($row['DeviceID'] ?? ''));
            $personId = trim((string)($row['PersonID'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)
                || isset($deviceMap[$id])) {
                $errors[] = 'Ungültige oder doppelte Geräte-ID';
                continue;
            }
            if (!isset($profileMap[$personId])) {
                $errors[] = 'Gerät ohne bekanntes Personenprofil: '.$id;
                continue;
            }
            $enabled = (bool)($row['Enabled'] ?? true);
            $wifi = (int)($row['WiFiVarID'] ?? 0);
            $geo = (int)($row['GeoVarID'] ?? 0);
            if ($wifi < 0 || $geo < 0 || ($wifi > 0 && $wifi === $geo)) {
                $errors[] = 'Ungültige Quellenzuordnung: '.$id;
                continue;
            }
            if ($enabled && $wifi > 0) {
                if (isset($wifiSources[$wifi]) || isset($geoSources[$wifi])) {
                    $errors[] = 'WLAN-Variable mehrfach verwendet: '.$wifi;
                }
                $wifiSources[$wifi] = $id;
            }
            if ($enabled && $geo > 0) {
                if (isset($geoSources[$geo]) || isset($wifiSources[$geo])) {
                    $errors[] = 'GPS-Variable mehrfach verwendet: '.$geo;
                }
                $geoSources[$geo] = $id;
            }
            $mac = strtolower(str_replace('-', ':', trim((string)($row['MAC'] ?? ''))));
            if ($mac !== '' && !preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D', $mac)) {
                $errors[] = 'Ungültige WLAN-MAC bei: '.$id;
            }
            if ($enabled && $mac !== '' && isset($macs[$mac])) {
                $errors[] = 'WLAN-MAC zwei Geräten zugeordnet: '.$id;
            }
            if ($enabled && $mac !== '') $macs[$mac] = $id;
            $deviceMap[$id] = [
                'id'=>$id, 'personId'=>$personId,
                'name'=>trim((string)($row['Name'] ?? '')) ?: $id,
                'enabled'=>$enabled,
                'wifiVarID'=>$wifi, 'geoVarID'=>$geo,
                'mac'=>$mac
            ];
        }
        return [
            'people'=>array_values($profileMap),
            'devices'=>array_values($deviceMap),
            'errors'=>$errors
        ];
    }

    /**
     * Inputs are read-only snapshots, indexed by device ID:
     * ['wifi'=>['value'=>bool,'updatedAt'=>unix time],
     *  'geo'=>['value'=>'PROPERTY'|'OUTSIDE'|'UNKNOWN','updatedAt'=>unix time]].
     * Missing/stale/invalid values can NEVER mean a person has left.
     */
    public static function evaluate(
        array $people, array $devices, array $inputs,
        int $now, int $wifiTtl = 180, int $geoTtl = 900
    ): array {
        $config = self::validateConfiguration($people,$devices);
        $report = [
            'schema'=>self::SCHEMA,
            'time'=>$now,
            'authority'=>'PHONE_OBSERVATIONS_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'errors'=>$config['errors'],
            'people'=>[],
            'devices'=>[]
        ];
        // Fail closed on ANY duplicated ID/source, not merely the affected row.
        if ($config['errors']) return $report;
        foreach ($config['devices'] as $device) {
            $d = $inputs[$device['id']] ?? [];
            $d = is_array($d) ? $d : [];
            $wifi = self::source($d['wifi']??null, $now, $wifiTtl, 'wifi');
            $geo = self::source($d['geo']??null, $now, $geoTtl, 'geo');
            $state = 'PHONE_UNKNOWN';
            if ($device['enabled']) {
                if ($wifi === 'CONNECTED' && $geo === 'OUTSIDE') {
                    $state = 'PHONE_CONFLICT';
                } elseif ($wifi === 'CONNECTED' || $geo === 'PROPERTY') {
                    $state = 'PHONE_PROPERTY_INDICATION';
                } elseif ($geo === 'OUTSIDE') {
                    $state = 'PHONE_OUTSIDE_INDICATION';
                }
            } else {
                $state = 'DISABLED';
                $wifi = 'DISABLED';
                $geo = 'DISABLED';
            }
            $report['devices'][]=[
                'id'=>$device['id'], 'personId'=>$device['personId'],
                'name'=>$device['name'], 'status'=>$state,
                'wifi'=>$wifi, 'geofence'=>$geo
            ];
        }
        foreach ($config['people'] as $person) {
            $states=[];
            foreach ($report['devices'] as $device) {
                if ($device['personId']===$person['id'] && $device['status']!=='DISABLED') {
                    $states[]=$device['status'];
                }
            }
            $state='PHONE_UNKNOWN';
            if (!$person['enabled']) {
                $state='DISABLED';
            } elseif (in_array('PHONE_CONFLICT',$states,true)
                || (in_array('PHONE_PROPERTY_INDICATION',$states,true)
                    && in_array('PHONE_OUTSIDE_INDICATION',$states,true))) {
                $state='PHONE_CONFLICT';
            } elseif (in_array('PHONE_PROPERTY_INDICATION',$states,true)) {
                $state='PHONE_PROPERTY_INDICATION';
            } elseif (in_array('PHONE_OUTSIDE_INDICATION',$states,true)) {
                $state='PHONE_OUTSIDE_INDICATION';
            }
            $report['people'][]=[
                'id'=>$person['id'], 'name'=>$person['name'],
                'phoneStatus'=>$state, 'personLocation'=>'UNKNOWN',
                'phoneCount'=>count($states)
            ];
        }
        return $report;
    }

    /** @param mixed $source */
    private static function source($source, int $now, int $ttl, string $type): string
    {
        if ($source === null) return 'UNCONFIGURED';
        if (!is_array($source) || !isset($source['updatedAt'])
            || !is_numeric($source['updatedAt'])) return 'INVALID';
        $at=(int)$source['updatedAt'];
        if ($at<=0 || $at>$now+5) return 'INVALID';
        if ($now-$at>max(10,$ttl)) return 'STALE';
        $value=$source['value']??null;
        if ($type==='wifi') {
            return is_bool($value) ? ($value?'CONNECTED':'NOT_CONNECTED') : 'INVALID';
        }
        return is_string($value) && in_array($value,['PROPERTY','OUTSIDE','UNKNOWN'],true)
            ? $value : 'INVALID';
    }
}
