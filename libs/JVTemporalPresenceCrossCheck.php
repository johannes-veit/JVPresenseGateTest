<?php
declare(strict_types=1);

/**
 * Conservative, time-aligned diagnostics of ALREADY EXISTING P03 raw
 * street/Human log, existing read-only phone MAC contact snapshots, and
 * indoor LCN movement. Does not reconstruct a confirmed P03 proof from
 * raw street IVS and NEVER assign any camera crossing to a named resident.
 *
 * Existing 60s SHADOW Refresh() supplies snapshots. No Symcon calls,
 * sockets, phone/wifi actions, camera changes, or new output variables.
 */
final class JVTemporalPresenceCrossCheck
{
    private const WINDOW=86400;
    private const MAX_LOG_BYTES=65536;
    private const MAX_LINES=1500;
    private const RAW_PAIR_SECONDS=50;
    private const P03_MODE_MAX_AGE=180;

    public static function evaluate(
        string $p03Protocol, ?string $p03SocketDiagnosis,
        array $timeline,array $wlan,array $localActivity,
        array $johannesGeo,array $carinaGeo,array $workWiFi,
        bool $streetDirectionCertified,int $now
    ):array {
        $out=[
            'schema'=>1,
            'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
            'status'=>'NO_RECENT_CAMERA_EVIDENCE',
            'p03OperatingMode'=>'UNKNOWN_NOT_VERIFIED',
            'p03ModeSnapshotAgeSeconds'=>null,
            'p03ModeTrustedFromFreshDiagnosis'=>false,
            'rawHumanWhileTestAndProductionOff24h'=>0,
            'rawStreetIVS24h'=>['OUT'=>0,'IN'=>0],
            'rawMastHuman24h'=>['JV_LEFT'=>0,'WORK_LEFT'=>0],
            'streetDirectionCertified'=>$streetDirectionCertified,
            'lastStreetIVS'=>null,
            'recentRawStreetIVS'=>[],
            'possibleStreetToJvSequences'=>[],
            'sequenceIsNotVerifiedTreppenProof'=>true,
            'liveVerifiedP03Transfers24h'=>['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0],
            'hasLiveVerifiedP03Proof'=>false,
            'phones'=>[],
            'lcn'=>['lastHouseMotionAt'=>null,'nearestStreetSeconds'=>null,
                'movementIsAnonymous'=>true],
            'geofences'=>['johannesEnabled'=>($johannesGeo['enabled']??false)===true,
                'carinaEnabled'=>($carinaGeo['enabled']??false)===true,
                'sharedRadiusDoesNotDifferentiateHomeAndWork'=>true],
            'workSsidEnabled'=>($workWiFi['enabled']??false)===true,
            'wlanLastContactIsNotJoinOrLeaveTime'=>true,
            'streetCrossingIsNotHouseLagerProof'=>true,
            'rawHumanIsNotIdentifiedPerson'=>true,
            'rawIvSCannotBookPersonHomeOrAway'=>true,
            'mayChangeExternalPresence'=>false,'mayArmAlarm'=>false,
            'warnings'=>[]
        ];
        if($now<=0 || strlen($p03Protocol)>self::MAX_LOG_BYTES+8192){
            $out['status']='INVALID_CLOCK_OR_PROTOCOL_SIZE';
            $out['warnings'][]='PROTOCOL_UNTRUSTED';
            return $out;
        }
        $verified=$timeline['directionProofs24h']??null;
        if(($timeline['schema']??null)===1
            &&($timeline['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY'
            &&is_array($verified)){
            foreach(['HOME_TO_LAGER','LAGER_TO_HOME'] as $d){
                $v=$verified[$d]??null;
                if(is_int($v)&&$v>=0&&$v<=10000)
                    $out['liveVerifiedP03Transfers24h'][$d]=$v;
            }
        }
        $out['hasLiveVerifiedP03Proof']=array_sum(
            $out['liveVerifiedP03Transfers24h'])>0;

        // The existing P03 read-only socket diagnostic explicitly contains
        // TEST and PRODUKTION. Its timestamp is required: an old "AUS"
        // cannot override a later manual production start.
        if(is_string($p03SocketDiagnosis)
            &&strlen($p03SocketDiagnosis)<=65536
            &&preg_match('/Zeit:\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/',
                $p03SocketDiagnosis,$dateMatch)
            &&preg_match('/TEST:\s*(AUS|AKTIV)\s*\|\s*PRODUKTION:\s*(AUS|AKTIV)/u',
                $p03SocketDiagnosis,$modeMatch)){
            $at=self::timestamp($dateMatch[1]);
            if($at!==null &&$at<=$now+5 &&$at>=$now-self::P03_MODE_MAX_AGE){
                $out['p03ModeTrustedFromFreshDiagnosis']=true;
                $out['p03ModeSnapshotAgeSeconds']=max(0,$now-$at);
                $out['p03OperatingMode']=$modeMatch[2]==='AKTIV'
                    ?'PRODUCTION_ACTIVE'
                    :($modeMatch[1]==='AKTIV'?'TEST_ACTIVE':'TEST_AND_PRODUCTION_OFF');
            }else{
                $out['warnings'][]='P03_MODE_DIAGNOSIS_STALE_OR_INVALID';
            }
        }else{
            $out['warnings'][]='P03_MODE_DIAGNOSIS_UNAVAILABLE';
        }
        $streets=[];$humans=['JV_LEFT'=>[],'WORK_LEFT'=>[]];
        // P03 truncates its own Protocol (~55kB); only parse bounded input.
        // No old commissioning proof is replayed as a new transition.
        $tail=substr($p03Protocol,-self::MAX_LOG_BYTES);
        preg_match_all(
            '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*([^\[\r\n]*)/u',
            $tail,$matches,PREG_SET_ORDER
        );
        foreach(array_slice($matches,-self::MAX_LINES) as $line){
            $at=self::timestamp((string)$line[1]);
            if($at===null ||$at<$now-self::WINDOW ||$at>$now+5)continue;
            $message=(string)$line[2];
            if(preg_match('/P03 STRASSE ROH\s+\d{2}:\d{2}:\d{2}\s+\|\s+WORK_LEFT RuleID5\s+\|\s+Human START\s+\|\s+Richtung=(RightToLeft|LeftToRight)/u',$message,$m)){
                $direction=$m[1]==='RightToLeft'?'OUT':'IN';
                $streets[]=['at'=>$at,'direction'=>$direction,
                    'cameraDirection'=>$m[1],'source'=>'WORK_LEFT_RULE5_HUMAN_RAW',
                    'verifiedHomeLagerTransfer'=>false];
                $out['rawStreetIVS24h'][$direction]++;
            }elseif(preg_match('/P03 HUMAN ROH (JV_LEFT|WORK_LEFT) Zaehler=\d+/u',
                $message,$m)){
                $camera=$m[1];
                $humans[$camera][]=$at;
                $out['rawMastHuman24h'][$camera]++;
                if(str_contains($message,'Test/Produktion AUS'))
                    $out['rawHumanWhileTestAndProductionOff24h']++;
            }
        }
        usort($streets,static fn($a,$b)=>$b['at']<=>$a['at']);
        $out['recentRawStreetIVS']=array_slice($streets,0,12);
        $out['lastStreetIVS']=$streets[0]??null;
        // A raw street crossing plus a nearby JV_LEFT human event is a
        // useful sequence to investigate, but does not show the TWO mast
        // Treppen-IVS proofs and certainly not a resident's identity.
        if($streetDirectionCertified){
            foreach(array_slice($streets,0,30) as $street){
                $nearest=null;
                foreach($humans['JV_LEFT'] as $humanAt){
                    $gap=$street['direction']==='OUT'
                        ?$street['at']-$humanAt
                        :$humanAt-$street['at'];
                    if($gap<0||$gap>self::RAW_PAIR_SECONDS)continue;
                    if($nearest===null||$gap<$nearest)$nearest=$gap;
                }
                if($nearest!==null){
                    $out['possibleStreetToJvSequences'][]=[
                        'streetAt'=>$street['at'],
                        'direction'=>$street['direction'],
                        'jvHumanLagSeconds'=>$nearest,
                        'status'=>'RAW_SEQUENCE_ONLY_NOT_VERIFIED_TRANSFER',
                        'resident'=>'UNKNOWN'
                    ];
                }
                if(count($out['possibleStreetToJvSequences'])>=8)break;
            }
        }
        $last=$streets[0]['at']??null;
        foreach(['johannes','carina'] as $person){
            $p=$wlan['people'][$person]??[];
            $wlanValid=($wlan['schema']??null)===1
                &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
                &&($wlan['enabled']??false)===true
                &&($p['validatedExistingVariablePair']??false)===true;
            $at=$wlanValid?($p['lastMacContactAt']??null):null;
            $fresh=$wlanValid&&($p['recentMacEvidence']??false)===true
                &&is_int($at)&&$at>0&&$at<=$now+5;
            $work=($workWiFi['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
                &&($workWiFi['enabled']??false)===true
                &&($workWiFi['people'][$person]['workRecent']??false)===true;
            $out['phones'][$person]=[
                'lastConfirmedHomeWlanMacAt'=>$fresh?$at:null,
                'homeWlanPhoneConfirmedNow'=>$fresh,
                'companyWlanPhoneConfirmedNow'=>$work,
                'secondsFromLastRawStreetEvent'=>$fresh&&is_int($last)
                    ?$at-$last:null,
                'timestampMeaning'=>'PERIODIC_LAST_MAC_CONFIRMATION_NOT_WIFI_JOIN',
                'correspondsToThisIVSIndividual'=>'NOT_ESTABLISHED',
                'residentHomeOrAwayCertified'=>false
            ];
        }
        $lcnAt=$localActivity['lastMotionAt']??null;
        if(($localActivity['authority']??null)==='MOTION_EDGE_HISTORY_ONLY'
            &&is_int($lcnAt)&&$lcnAt>0&&$lcnAt<=$now+5){
            $out['lcn']['lastHouseMotionAt']=$lcnAt;
            $out['lcn']['nearestStreetSeconds']=is_int($last)?$lcnAt-$last:null;
        }
        if(!$out['hasLiveVerifiedP03Proof']
            &&$out['p03OperatingMode']==='TEST_AND_PRODUCTION_OFF'
            &&($out['rawHumanWhileTestAndProductionOff24h']>0
                ||array_sum($out['rawStreetIVS24h'])>0)){
            $out['status']='P03_PROOF_OFF_RAW_IVS_PRESENT';
            $out['warnings'][]='P03_TEST_AND_PRODUCTION_OFF_NO_TRANSFER_BOOKING';
        }elseif($out['hasLiveVerifiedP03Proof']){
            $out['status']='LIVE_P03_DIRECTION_PROOFS_EXIST_IDENTITY_UNKNOWN';
        }elseif(array_sum($out['rawStreetIVS24h'])>0
            ||array_sum($out['rawMastHuman24h'])>0){
            $out['status']='RAW_CAMERA_ACTIVITY_WITHOUT_VERIFIED_P03_PROOF';
        }
        if(!$out['geofences']['johannesEnabled']
            ||!$out['geofences']['carinaEnabled'])
            $out['warnings'][]='GEOFENCE_SHADOW_INPUT_DISABLED';
        if(!$out['workSsidEnabled'])
            $out['warnings'][]='FIRMEN_SSID_LIVE_SOURCE_DISABLED';
        return $out;
    }

    private static function timestamp(string $time):?int
    {
        try{
            $d=DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',$time,new DateTimeZone('Europe/Berlin'));
            return $d&&$d->format('Y-m-d H:i:s')===$time
                ?$d->getTimestamp():null;
        }catch(Throwable $ignored){return null;}
    }
}
