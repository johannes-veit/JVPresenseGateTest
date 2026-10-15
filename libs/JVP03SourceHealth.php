<?php
declare(strict_types=1);

/**
 * Conservative, read-only classification of EXISTING P03 Aux observer health.
 * Nothing here connects to cameras, reads a password, restarts a socket,
 * changes a smart plan or modifies any presence state.
 */
final class JVP03SourceHealth
{
    private const ROLES=['JV_LEFT','WORK_LEFT'];

    public static function evaluate(array $observations,string $proofState,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>'P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY',
            'mastObservers'=>[],
            'liveMasts'=>0,
            'requiredMasts'=>2,
            'bothMastsLive'=>false,
            'mainP03ProofBlocked'=>stripos($proofState,'BLOCKIERT')!==false
                ||stripos($proofState,'abgebrochen')!==false,
            'finding'=>'UNKNOWN',
            'actionHint'=>'CHECK_EXISTING_P03_AUX_DIAGNOSTICS_READ_ONLY',
            'existingModulesOrSocketsRestarted'=>false,
            'camerasContacted'=>false,'cameraSettingsChanged'=>false,
            'peopleCount'=>'UNKNOWN',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false
        ];
        foreach(self::ROLES as $role){
            $item=is_array($observations[$role]??null)?$observations[$role]:[];
            $matched=($item['guidVerified']??false)===true
                && ($item['role']??null)===$role
                && ($item['host']??null)===($role==='JV_LEFT'
                    ?'192.168.107.95':'192.168.107.94');
            $diag=is_array($item['diagnostic']??null)?$item['diagnostic']:[];
            $streamOK=$item['streamOK']??null;
            $rxAge=$diag['rxAge']??null;
            $hasRxAge=is_int($rxAge)&&$rxAge>=0&&$rxAge<=86400*7;
            $authBlocked=($diag['authBlocked']??false)===true;
            $authPending=($diag['authPending']??false)===true;
            $streaming=$diag['streaming']??null;
            $state='UNVERIFIED_OBSERVER';
            if($matched){
                if($authBlocked)$state='AUTH_BLOCKED';
                elseif($streamOK===false||$streaming===false)
                    $state='STREAM_DOWN';
                elseif($hasRxAge&&$rxAge>90)$state='STALE_CAMERA_RX';
                elseif($streamOK===true&&$streaming===true
                    &&$hasRxAge&&$rxAge<=90&&!$authPending)
                    $state='LIVE_RX';
                else $state='INSUFFICIENT_LIVE_DIAGNOSTICS';
            }
            if($state==='LIVE_RX')$out['liveMasts']++;
            $out['mastObservers'][$role]=[
                'instanceId'=>is_int($item['instanceId']??null)
                    ?$item['instanceId']:0,
                'sourceVerified'=>$matched,'role'=>$role,
                'streamState'=>$state,
                'streamOK'=>is_bool($streamOK)?$streamOK:null,
                'lastCameraRxAgeSeconds'=>$hasRxAge?$rxAge:null,
                'authenticatedOrStreamHealthIssue'=>$authBlocked||$authPending,
                'parentSocketOpen'=>is_bool($diag['parentOpen']??null)
                    ?$diag['parentOpen']:null,
                'observerConfigReady'=>is_bool($diag['configReady']??null)
                    ?$diag['configReady']:null,
                // A LastIVSEvent time without full date cannot certify
                // recent unique humans. Never use it as headcount.
                'humanCountVerified'=>false
            ];
        }
        $out['bothMastsLive']=$out['liveMasts']===2;
        if($out['mainP03ProofBlocked']){
            $out['finding']=$out['bothMastsLive']
                ?'P03_MAIN_BLOCKED_DESPITE_LIVE_AUX_STREAMS'
                :'P03_MAIN_BLOCKED_CHECK_AUX_STREAMS';
        }elseif($out['bothMastsLive']){
            $out['finding']='BOTH_AUX_STREAMS_LIVE_NO_PERSON_COUNT_PROOF';
        }else{
            $out['finding']='ONE_OR_BOTH_AUX_STREAMS_UNCONFIRMED';
        }
        return $out;
    }
}
