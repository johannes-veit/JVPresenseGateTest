<?php
declare(strict_types=1);

/**
 * Safe commissioning snapshot from ALREADY READ evidence.
 *
 * Never toggles P03, changes cameras or phones, invents a human identity,
 * upgrades a stale SSID, or authorizes absence/alarm controls.
 * The actual multi-camera P03 instance uses JVPresenceP03TerraceTest.
 * Its production method is gated by complete audit, simulation, four
 * verified crossings (two each direction) and two live mast streams.
 */
final class JVPresenceOperationalReadiness
{
    public static function evaluate(array $ssid, array $p03, array $mast, int $now, ?string $p03Diagnostic=null): array
    {
        $out=[
            'schema'=>1,
            'authority'=>'SOURCE_COMMISSIONING_READ_ONLY',
            'checkedAt'=>$now,
            'ssid'=>[],
            'p03'=>[],
            'actions'=>[],
            'mayChangePresence'=>false,
            'mayCertifyEmptyHouse'=>false,
            'maySwitchP03OrCameras'=>false,
            'mayArmAlarm'=>false,
            'newSymconVariables'=>0
        ];
        $ssidValid=($ssid['schema']??null)===1
            &&($ssid['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT';
        foreach(['johannes','carina'] as $person){
            $row=$ssidValid&&is_array($ssid['people'][$person]??null)
                ?$ssid['people'][$person]:[];
            $verified=($row['sourceVerified']??false)===true;
            $age=$row['ageSeconds']??null;
            $validAge=is_int($age)&&$age>=0&&$age<=86400;
            $state=(string)($row['status']??'UNAVAILABLE');
            $fresh=$verified&&$validAge&&$age<=300
                &&in_array($state,[
                    'WORK_FIRMEN_WLAN_PHONE','HOME_SSID_PHONE_HINT',
                    'WORK_OR_PRIVATE_PROPERTY_EXT_UNCERTAIN',
                    'OTHER_OR_UNKNOWN_SSID'
                ],true);
            $reason='PERSONAL_SSID_CURRENT';
            if(!$verified)$reason='PERSONAL_SSID_SOURCE_UNVERIFIED';
            elseif(!$validAge)$reason='PERSONAL_SSID_TIMESTAMP_INVALID';
            elseif(!$fresh)$reason=$age>300
                ?'PERSONAL_SSID_HEARTBEAT_TOO_OLD'
                :'PERSONAL_SSID_VALUE_UNCONFIRMED';
            $out['ssid'][$person]=[
                'sourceVerified'=>$verified,
                'lastUpdateAgeSeconds'=>$validAge?$age:null,
                'currentWithinFiveMinutes'=>$fresh,
                'reason'=>$reason,
                'ssidClass'=>$fresh?$state:'UNKNOWN_STALE_OR_UNVERIFIED',
                'isHumanLocationProof'=>false
            ];
            if(!$fresh){
                $out['actions'][]=strtoupper($person)
                    .':CHECK_PHONE_REPEATED_LIVE_SSID_POST';
            }
        }
        $timingValid=($p03['schema']??null)===1
            &&($p03['authority']??null)==='READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY';
        $mastValid=($mast['schema']??null)===1
            &&($mast['authority']??null)==='P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY';
        $mode=$timingValid&&(string)($p03['p03OperatingMode']??'')!==''
            ?(string)$p03['p03OperatingMode']:'UNKNOWN_NOT_VERIFIED';
        $modeFresh=$timingValid&&($p03['p03ModeTrustedFromFreshDiagnosis']??false)===true;
        if(!$modeFresh)$mode='UNKNOWN_NOT_VERIFIED';
        $bothLive=$mastValid&&($mast['bothMastsLive']??false)===true;
        $blocked=!$mastValid||($mast['mainP03ProofBlocked']??true)===true;
        // Parse only an exact gate emitted by the verified P03 main module.
        // Freshness comes from the already checked timestamp + mode line;
        // unknown/incomplete or tampered diagnostics are not a green light.
        $gate=[
            'verifiedFromFreshDiagnostic'=>false,
            'eligibleForManualProductionEnable'=>false,
            'auditPassed'=>null,'simulationPassed'=>null,
            'homeToLagerCount'=>null,'lagerToHomeCount'=>null,
            'totalVerifiedCount'=>null,'counterSourcesReady'=>null,
            'socketAndMastSourcesReady'=>null,'missing'=>[]
        ];
        if($modeFresh&&is_string($p03Diagnostic)
            &&strlen($p03Diagnostic)<=65536
            &&preg_match('/^P03_VORFREIGABE: AUDIT=(JA|NEIN)'
                .' \\| SIMULATION=(JA|NEIN)'
                .' \\| OUT=(\\d{1,5}) \\| IN=(\\d{1,5})'
                .' \\| GESAMT=(\\d{1,5})'
                .' \\| AUX_ZAEHLER=(JA|NEIN)'
                .' \\| MASTS=(JA|NEIN)'
                .' \\| FREIGABE=(JA|NEIN)\\s*$/m',
                $p03Diagnostic,$g)){
            $audit=$g[1]==='JA';$simulation=$g[2]==='JA';
            $outCount=(int)$g[3];$inCount=(int)$g[4];
            $total=(int)$g[5];$counters=$g[6]==='JA';
            $masts=$g[7]==='JA';$eligible=$g[8]==='JA';
            $derived=$audit&&$simulation&&$outCount>=2
                &&$inCount>=2&&$total>=4&&$counters&&$masts;
            if($eligible===$derived){
                $missing=[];
                if(!$audit)$missing[]='P03_MULTICAMERA_AUDIT';
                if(!$simulation)$missing[]='P03_SIMULATION';
                if($outCount<2)$missing[]='HOME_TO_LAGER_2_REQUIRED';
                if($inCount<2)$missing[]='LAGER_TO_HOME_2_REQUIRED';
                if($total<4)$missing[]='FOUR_VERIFIED_CROSSINGS_REQUIRED';
                if(!$counters)$missing[]='BOTH_AUX_COUNTER_SOURCES';
                if(!$masts)$missing[]='BOTH_MANAGED_MAST_SOCKETS';
                $gate=[
                    'verifiedFromFreshDiagnostic'=>true,
                    'eligibleForManualProductionEnable'=>$eligible,
                    'auditPassed'=>$audit,'simulationPassed'=>$simulation,
                    'homeToLagerCount'=>$outCount,'lagerToHomeCount'=>$inCount,
                    'totalVerifiedCount'=>$total,
                    'counterSourcesReady'=>$counters,
                    'socketAndMastSourcesReady'=>$masts,
                    'missing'=>$missing
                ];
            }
        }
        $ready=$mode==='PRODUCTION_ACTIVE'&&$bothLive&&!$blocked;
        $reason='P03_PRODUCTION_ACTIVE_MONITOR_NEW_PROOFS';
        if($mode==='UNKNOWN_NOT_VERIFIED'){
            $reason='P03_FRESH_MODE_DIAGNOSTIC_MISSING';
            $out['actions'][]='P03:CHECK_MAIN_INSTANCE_FRESH_MODE_READ_ONLY';
        }elseif($mode==='TEST_AND_PRODUCTION_OFF'){
            $reason=$gate['eligibleForManualProductionEnable']
                &&$gate['verifiedFromFreshDiagnostic']
                &&$bothLive&&!$blocked
                ?'P03_PRODUCTION_OFF_ELIGIBLE_FOR_MANUAL_ENABLE'
                :'P03_PRODUCTION_OFF_EXPLICIT';
            $out['actions'][]=$reason==='P03_PRODUCTION_OFF_ELIGIBLE_FOR_MANUAL_ENABLE'
                ?'P03:MANUAL_APPROVAL_AND_ENABLE_IN_MAIN_INSTANCE_ONLY'
                :'P03:CHECK_AUDIT_SIMULATION_TWO_OUT_TWO_IN_BEFORE_ENABLE';
        }elseif($mode==='TEST_ACTIVE'){
            $reason='P03_TEST_ACTIVE_NOT_PRODUCTION';
            $out['actions'][]='P03:FINISH_VERIFIED_CALIBRATION_FIRST';
        }
        if(!$bothLive)$out['actions'][]='P03:CHECK_BOTH_EXISTING_AUX_OBSERVERS';
        if($mastValid&&($mast['mainP03ProofBlocked']??false)===true)
            $out['actions'][]='P03:MAIN_PROOF_BLOCKED_INSPECT_IVS_RULE_BINDING';
        $out['p03']=[
            'operatingMode'=>$mode,
            'modeFresh'=>$modeFresh,
            'bothExistingAuxObserversLive'=>$bothLive,
            'mainProofBlocked'=>$blocked,
            'readyToObserveNewAnonymousRouteProofs'=>$ready,
            'newVerifiedDirectionProofsRequired'=>true,
            'productionEnableFunctionHasVerifiedGuards'=>true,
            'requiredPriorFieldCrossings'=>['HOME_TO_LAGER'=>2,'LAGER_TO_HOME'=>2],
            'productionPreflight'=>$gate,
            'reason'=>$reason,
            'rawStreetIVSIsNotRouteProof'=>true,
            'cameraEventIsNotIdentifiedPerson'=>true
        ];
        return $out;
    }
}
