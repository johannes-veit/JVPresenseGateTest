<?php
declare(strict_types=1);

/**
 * Strict, read-only selection of existing Boolean "Status" light variables
 * from the explicit user-triggered metadata inventory. Never treats
 * controllers, brightness, shutters, windows or TV guesses as verified lights.
 * Light ON is a weak anonymous device hint, not a human occupancy proof.
 */
final class JVIndoorAutoReadHints
{
    public static function select(?array $inventory,array $excludedIDs=[]):array
    {
        if(!is_array($inventory)
            ||($inventory['schema']??null)!==1
            ||($inventory['authority']??null)!=='INDOOR_LIGHT_TV_CANDIDATES_METADATA_ONLY'
            ||($inventory['symconScanLimited']??true)!==false
            ||($inventory['candidateListLimited']??true)!==false
            ||!is_array($inventory['listedCandidates']??null))return [];
        $excluded=array_fill_keys(array_map('intval',$excludedIDs),true);
        $result=[];$used=[];
        foreach(array_slice($inventory['listedCandidates'],0,80) as $row){
            if(!is_array($row)||!is_int($row['variableId']??null))continue;
            $id=$row['variableId'];
            if($id<=0||isset($used[$id])||isset($excluded[$id])
                ||($row['type']??null)!=='Boolean'
                ||($row['name']??null)!=='Status'
                ||($row['recommendedKind']??null)!=='LIGHT_INTERIOR'
                ||($row['isAutomaticallyLinked']??true)!==false
                ||($row['isVerifiedStatusOutput']??true)!==false)continue;
            $parent=$row['parent']??null;
            $grand=$row['grandparent']??null;
            if(!is_string($parent)||!is_string($grand)
                ||!in_array($grand,['Licht','Licht RGB'],true)
                ||preg_match('/^(?:RGB )?(?:EG|OG) [\\p{L}\\d][\\p{L}\\d\\s.\\-]*$/u',$parent)!==1
                ||JVIndoorPresenceHints::isExterior($parent.' '.$grand))continue;
            if(($grand==='Licht RGB')!==str_starts_with($parent,'RGB '))continue;
            $used[$id]=true;
            $result[]=['variableId'=>$id,'name'=>'Status',
                'parent'=>$parent,'grandparent'=>$grand];
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $snapshots */
    public static function evaluate(array $selected,array $snapshots,int $now):array
    {
        $readings=[];$invalid=0;
        foreach($selected as $row){
            $id=$row['variableId'];$s=$snapshots[$id]??null;
            if(!is_array($s)||($s['valid']??false)!==true
                ||!is_bool($s['value']??null)
                ||!is_int($s['changedAt']??null)
                ||$s['changedAt']<0||$s['changedAt']>$now+5){
                $invalid++;continue;
            }
            $readings['auto_indoor_'.$id]=[
                'name'=>$row['parent'],
                'kind'=>'LIGHT_INTERIOR','zone'=>'HOME',
                'state'=>$s['value']?'ACTIVE':'INACTIVE',
                'changedAt'=>$s['changedAt']>0?$s['changedAt']:null,
                'weakDeviceStatusOnly'=>true
            ];
        }
        return [
            'schema'=>1,'authority'=>'READ_ONLY_EXISTING_INDOOR_STATUS_HINTS',
            'candidateCount'=>count($selected),
            'readableCount'=>count($readings),
            'rejectedCount'=>$invalid,
            'readings'=>$readings,
            'noNewSymconObjects'=>true,
            'statusIsNotIndependentPersonDetection'=>true,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false
        ];
    }
}
