<?php
declare(strict_types=1);

/**
 * Carina iPhone Geofency, existing Symcon module only. The app produces
 * event-backed Boolean location flags and a Unix Timestamp. None proves
 * the person is inside the house or outside the property.
 */
final class JVCarinaGeofencyHints
{
    public const GEOFENCY_GUID='{0100CA50-D2DA-11E4-8830-0800200C9A66}';
    public const MAX_EVENT_AGE_SECONDS=21600;

    /** Pure metadata inventory; no state values or coordinates accepted. */
    public static function inventory(array $rows,bool $partial,int $count):array
    {
        $out=[];$deviceKeys=[];
        foreach(array_slice($rows,0,8000) as $row){
            if(!is_array($row)
                ||!is_int($row['id']??null)
                ||!is_int($row['deviceID']??null)
                ||!is_int($row['rootID']??null)
                ||!is_string($row['deviceName']??null)
                ||!is_string($row['name']??null)
                ||($row['rootGUID']??null)!==self::GEOFENCY_GUID
                ||!in_array(($row['type']??null),[0,1],true))continue;
            $id=$row['id'];$device=$row['deviceID'];$root=$row['rootID'];
            if($id<=0||$device<=0||$root<=0
                ||$id===$device||$device===$root)continue;
            $name=$row['name'];$type=$row['type'];
            if(!($type===1&&$name==='Timestamp')
                &&!($type===0
                    &&!in_array($name,['Latitude','Longitude',
                        'CurrentLatitude','CurrentLongitude','Motion',
                        'Distance','WifiBSSD','WifiSSID'],true)))continue;
            $out[]=[
                'variableId'=>$id,'type'=>$type===0?'Boolean':'Integer',
                'name'=>$name,'deviceID'=>$device,
                'deviceName'=>$row['deviceName'],'geofencyInstanceID'=>$root,
                'likelyCarina'=>stripos($row['deviceName'],'carina')!==false,
                'locationStatusNotPersonProof'=>true
            ];
            $deviceKeys[$root.':'.$device]=true;
        }
        usort($out,static fn($a,$b)=>[$a['deviceName'],$a['variableId']]
            <=>[$b['deviceName'],$b['variableId']]);
        return [
            'schema'=>1,'authority'=>'CARINA_GEOFENCY_METADATA_ONLY',
            'listedCandidates'=>array_slice($out,0,80),
            'candidateCount'=>count($out),
            'deviceCount'=>count($deviceKeys),
            'listLimited'=>count($out)>80,
            'scanLimited'=>$partial,
            'scannedVariables'=>$count,
            'allDataMetadataOnly'=>true,
            'phoneAppsModified'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false
        ];
    }

    /**
     * Explicit opt-in with either an exact existing device/zone/timestamp
     * selection, or exactly one automatically resolved "Carina"+"Zuhause"
     * location pair. Never infer the device owner from a source value.
     */
    public static function select(?array $inventory,int $deviceID,
        int $zoneID,int $timestampID,string $homeName):array
    {
        $out=['status'=>'INVENTORY_REQUIRED','selected'=>null];
        if(!is_array($inventory)
            ||($inventory['schema']??null)!==1
            ||($inventory['authority']??null)!=='CARINA_GEOFENCY_METADATA_ONLY'
            ||($inventory['scanLimited']??true)!==false
            ||($inventory['listLimited']??true)!==false
            ||!is_array($inventory['listedCandidates']??null))return $out;
        if($homeName===''||strlen($homeName)>120)return [
            'status'=>'INVALID_HOME_ZONE_NAME','selected'=>null
        ];
        $groups=[];
        foreach($inventory['listedCandidates'] as $row){
            if(!is_array($row)||!is_int($row['deviceID']??null)
                ||!is_int($row['variableId']??null)
                ||!is_int($row['geofencyInstanceID']??null))continue;
            $root=$row['geofencyInstanceID'];
            $device=$row['deviceID'];
            $key=$root.':'.$device;
            if(!isset($groups[$key]))$groups[$key]=[
                'rootID'=>$root,'deviceID'=>$device,
                'deviceName'=>$row['deviceName'],'geofence'=>[],
                'timestamp'=>[],'likelyCarina'=>$row['likelyCarina']===true
            ];
            if($row['type']==='Boolean'
                &&$row['name']===$homeName)
                $groups[$key]['geofence'][]=$row['variableId'];
            if($row['type']==='Integer' &&$row['name']==='Timestamp')
                $groups[$key]['timestamp'][]=$row['variableId'];
        }
        $manual=($deviceID>0||$zoneID>0||$timestampID>0);
        if($manual &&($deviceID<=0||$zoneID<=0||$timestampID<=0))
            return ['status'=>'MANUAL_IDS_INCOMPLETE','selected'=>null];
        $candidates=[];
        foreach($groups as $group){
            $zones=array_values(array_unique($group['geofence']));
            $times=array_values(array_unique($group['timestamp']));
            if(count($zones)!==1||count($times)!==1)continue;
            if($manual){
                if($group['deviceID']!==$deviceID
                    ||$zones[0]!==$zoneID
                    ||$times[0]!==$timestampID)continue;
            }elseif(!$group['likelyCarina'])continue;
            $candidates[]=[
                'rootID'=>$group['rootID'],
                'deviceID'=>$group['deviceID'],
                'deviceName'=>$group['deviceName'],
                'zoneID'=>$zones[0],'zoneName'=>$homeName,
                'timestampID'=>$times[0]
            ];
        }
        if(count($candidates)!==1)
            return ['status'=>$manual?'MANUAL_IDS_NOT_VERIFIED'
                :'NO_UNIQUE_CARINA_HOME_LOCATION','selected'=>null];
        return ['status'=>'SELECTED_VERIFIED_METADATA',
            'selected'=>$candidates[0]];
    }

    public static function evaluate(array $snapshot,int $now,bool $enabled,
        string $selectStatus=''):array
    {
        $report=[
            'schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
            'enabled'=>$enabled,
            'state'=>$enabled?'SOURCE_NOT_VERIFIED':'DISABLED',
            'selectionStatus'=>$selectStatus,
            'zoneName'=>null,
            'geofenceReportedInside'=>null,
            'lastEventAt'=>null,'lastEventAgeSeconds'=>null,
            'freshEventMaxAgeSeconds'=>self::MAX_EVENT_AGE_SECONDS,
            'validatedSource'=>false,
            'insideRecent'=>false,'outsideRecent'=>false,
            'phoneIsNotPerson'=>true,
            'eventCouldBeDelayedOrMissed'=>true,
            'radiusMeters'=>'NOT_VERIFIED',
            'coordinatesRead'=>false,
            'foreignModulesModified'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false,
            'warnings'=>[]
        ];
        if(!$enabled)return $report;
        if($now<=0
            ||($snapshot['verified']??null)!==true
            ||!is_bool($snapshot['inside']??null)
            ||!is_int($snapshot['timestamp']??null)
            ||!is_string($snapshot['zoneName']??null)){
            $report['warnings'][]='NO_VERIFIED_FRESH_EXISTING_SOURCE';
            return $report;
        }
        $report['validatedSource']=true;
        $report['zoneName']=$snapshot['zoneName'];
        $report['geofenceReportedInside']=$snapshot['inside'];
        $at=$snapshot['timestamp'];
        if($at<=0||$at>$now+5){
            $report['state']='INVALID_LAST_EVENT_TIMESTAMP';
            $report['warnings'][]='EVENT_TIMESTAMP_INVALID';
            return $report;
        }
        $age=max(0,$now-$at);
        $report['lastEventAt']=$at;
        $report['lastEventAgeSeconds']=$age;
        if($age>self::MAX_EVENT_AGE_SECONDS){
            $report['state']=$snapshot['inside']
                ?'STALE_INSIDE_NOT_CURRENT_LOCATION'
                :'STALE_OUTSIDE_NOT_PERSON_AWAY';
            return $report;
        }
        if($snapshot['inside']){
            $report['state']='RECENT_INSIDE_HOME_GEOFENCE_DEVICE_HINT';
            $report['insideRecent']=true;
        }else{
            $report['state']='RECENT_OUTSIDE_HOME_GEOFENCE_NOT_PERSON_AWAY';
            $report['outsideRecent']=true;
        }
        return $report;
    }
}
