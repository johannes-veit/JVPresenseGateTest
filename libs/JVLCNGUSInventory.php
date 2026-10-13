<?php
declare(strict_types=1);

/**
 * One-click import of the exact 15 LCN movement status outputs verified
 * in the user's SymBox inventory 2026-10-13.
 *
 * This helper only proposes JSON source configuration for the CURRENT
 * observer. It never touches cameras, existing LCN instances, P03/P05,
 * actuators, actual PresenceCore occupancy or external devices.
 */
final class JVLCNGUSInventory
{
    public const SCHEMA = 1;
    public const KNOWN = [
        ['id'=>42452, 'unit'=>26568, 'name'=>'Bewegungsmeder EG Wohnen Kamin', 'source'=>'eg_wohnen_kamin', 'kind'=>'MOTION_KAMIN'],
        ['id'=>45510, 'unit'=>13062, 'name'=>'Bewegungsmelder EG Bad', 'source'=>'eg_bad', 'kind'=>'MOTION'],
        ['id'=>43605, 'unit'=>32814, 'name'=>'Bewegungsmelder EG Büro', 'source'=>'eg_buero', 'kind'=>'MOTION'],
        ['id'=>39155, 'unit'=>19163, 'name'=>'Bewegungsmelder EG Flur', 'source'=>'eg_flur', 'kind'=>'MOTION'],
        ['id'=>47271, 'unit'=>12662, 'name'=>'Bewegungsmelder EG Gäste WC', 'source'=>'eg_gaeste_wc', 'kind'=>'MOTION'],
        ['id'=>11701, 'unit'=>34015, 'name'=>'Bewegungsmelder EG Schlafen', 'source'=>'eg_schlafen', 'kind'=>'MOTION'],
        ['id'=>51040, 'unit'=>53795, 'name'=>'Bewegungsmelder EG Wohnen / Küche', 'source'=>'eg_wohnen_kueche', 'kind'=>'MOTION_KITCHEN'],
        ['id'=>51583, 'unit'=>52391, 'name'=>'Bewegungsmelder OG Ankleide', 'source'=>'og_ankleide', 'kind'=>'MOTION'],
        ['id'=>25969, 'unit'=>44672, 'name'=>'Bewegungsmelder OG Ankleide Decke', 'source'=>'og_ankleide_decke', 'kind'=>'MOTION'],
        ['id'=>16468, 'unit'=>47236, 'name'=>'Bewegungsmelder OG Bad', 'source'=>'og_bad', 'kind'=>'MOTION'],
        ['id'=>30215, 'unit'=>37776, 'name'=>'Bewegungsmelder OG Bad Dusche', 'source'=>'og_bad_dusche', 'kind'=>'MOTION'],
        ['id'=>28009, 'unit'=>49288, 'name'=>'Bewegungsmelder OG Bad Waschtisch', 'source'=>'og_bad_waschtisch', 'kind'=>'MOTION'],
        ['id'=>22933, 'unit'=>56863, 'name'=>'Bewegungsmelder OG Flur', 'source'=>'og_flur', 'kind'=>'MOTION'],
        ['id'=>38792, 'unit'=>41644, 'name'=>'Bewegungsmelder OG Schlafen 1', 'source'=>'og_schlafen_1', 'kind'=>'MOTION'],
        ['id'=>37461, 'unit'=>17361, 'name'=>'Bewegungsmelder OG Schlafen 2', 'source'=>'og_schlafen_2', 'kind'=>'MOTION']
    ];

    public const LCN_UNIT_GUID='{2D871359-14D8-493F-9B01-26432E3A710F}';

    /**
     * @param array<int,array> $inventory Read-only metadata, keyed by variable ID
     *    'variableId' int, 'unitId' int, 'name' original parent instance string,
     *    'moduleGUID' str, 'variableName'='Status', 'variableType'=0.
     * @param array<int,array> $existing Current observer's source config.
     * @return array{ok:bool, rows:array, errors:array, count:int, unchanged:bool}
     */
    public static function propose(array $inventory, array $existing): array
    {
        $errors=[];
        $indexed=[];
        foreach ($inventory as $row) {
            if (!is_array($row) || !is_int($row['variableId']??null)) {
                $errors[]='INVALID_INVENTORY_ROW';continue;
            }
            $id=$row['variableId'];
            if (isset($indexed[$id])) {
                $errors[]='DUPLICATE_INVENTORY_ID:'.$id;continue;
            }
            $indexed[$id]=$row;
        }
        $presets=[];
        $knownIds=[];
        $knownSources=[];
        foreach (self::KNOWN as $known) {
            $id=$known['id'];
            $knownIds[$id]=true;
            $knownSources[$known['source']]=true;
            $row=$indexed[$id]??null;
            if (!is_array($row)
                || ($row['unitId']??null)!==$known['unit']
                || ($row['name']??null)!==$known['name']
                || strcasecmp((string)($row['moduleGUID']??''),self::LCN_UNIT_GUID)!==0
                || ($row['variableName']??null)!=='Status'
                || ($row['variableType']??null)!==0) {
                $errors[]='SOURCE_NOT_VERIFIED:'.$known['source'].'#'.$id;
                continue;
            }
            $presets[]=[
                'Enabled'=>true,
                'SourceID'=>$known['source'],
                'Name'=>$known['name'],
                'Kind'=>$known['kind'],
                'Zone'=>'HOME',
                'VariableID'=>$id
            ];
        }
        if (!array_is_list($existing) || count($existing)>64) {
            $errors[]='INVALID_EXISTING_CONFIG';
        }
        $unrelated=[];
        $customIds=[];
        $customSources=[];
        foreach ($existing as $row) {
            if(!is_array($row)) {$errors[]='INVALID_EXISTING_ROW';continue;}
            $id=$row['VariableID']??null;
            $source=$row['SourceID']??null;
            if(!is_int($id) || !is_string($source)) {
                $errors[]='INVALID_EXISTING_ROW';continue;
            }
            // An existing preset may be imported/refreshed only when its
            // source key AND real variable ID are the original pair.
            if (isset($knownIds[$id]) || isset($knownSources[$source])) {
                $matched=false;
                foreach (self::KNOWN as $known) {
                    if($id===$known['id'] && $source===$known['source']) {
                        $matched=true;break;
                    }
                }
                if(!$matched) $errors[]='SOURCE_CONFLICT:'.$source.'#'.$id;
                continue;
            }
            if (isset($customIds[$id]) || isset($customSources[$source])) {
                $errors[]='DUPLICATE_CUSTOM_SOURCE:'.$source;
                continue;
            }
            $customIds[$id]=true;
            $customSources[$source]=true;
            $unrelated[]=$row; // preserve user LIGHT/GATE/VEHICLE preferences
        }
        if(count($presets)+count($unrelated)>64) $errors[]='SOURCE_LIMIT';
        if ($errors) return ['ok'=>false,'rows'=>[],'errors'=>array_values(array_unique($errors)),'count'=>0,'unchanged'=>false];

        $merged=array_merge($presets,$unrelated);
        // Compare to existing config using semantic source IDs. The button
        // is fully idempotent once sources have been imported.
        $old=[$existing];
        $unchanged=($merged===$existing);
        return [
            'ok'=>true,'rows'=>$merged,'errors'=>[],
            'count'=>count($presets),
            'unchanged'=>$unchanged
        ];
    }
}
