<?php
declare(strict_types=1);

/**
 * STRICT observational replay of P03's existing textual protocol.
 *
 * A P03 proof verifies an ORDERED TWO-MAST WALKING DIRECTION; it does NOT
 * certify that exactly one person crossed. Neither it nor a street IVS event
 * proves who crossed or that the property is empty.
 *
 * No camera access, Symcon reads, writes, or P03 event registration here.
 */
final class JVP03ProofLogReplay
{
    public const SCHEMA = 1;
    private const MAX_BYTES = 262144;
    private const MAX_ENTRIES = 1024;

    public static function parse(string $raw): array
    {
        $result = [
            'schema'=>self::SCHEMA,
            'authority'=>'DIRECTION_ONLY_SHADOW',
            'personCountCertified'=>false,
            'identityCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'p03'=>[
                'homeToWork'=>0,
                'workToHome'=>0,
                'commissioningClaim'=>false,
                'commissioningReplayed'=>false,
                'lastProofLocalTime'=>null,
                'directions'=>[]
            ],
            'street'=>[
                'out'=>'UNKNOWN', 'in'=>'UNKNOWN',
                'directionMappingCertified'=>false,
                'steps'=>0
            ],
            'warnings'=>[]
        ];
        if (strlen($raw) > self::MAX_BYTES) {
            $result['warnings'][]='PROTOCOL_TOO_LARGE_OR_TRUNCATED';
            return $result;
        }
        // Symcon exports can concatenate [timestamp] blocks without newlines.
        // Split only at full dated record boundaries, not within JSON.
        $entries=preg_split(
            '/(?=\[20\d\d-\d\d-\d\d \d\d:\d\d:\d\d\])/',
            $raw, -1, PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($entries) || count($entries)>self::MAX_ENTRIES) {
            $result['warnings'][]='PROTOCOL_ENTRIES_INVALID';
            return $result;
        }
        $proofKeys=[];
        $streetSteps=[];
        $commissionClaims=[];
        foreach ($entries as $entry) {
            if (!preg_match('/^\[(20\d\d-\d\d-\d\d \d\d:\d\d:\d\d)\]\s*(.*)$/sD',
                trim($entry),$m)) continue;
            $at=$m[1];
            $payload=trim($m[2]);
            if (preg_match('/^P03 PROOF (VERIFIED|STRONG_VERIFIED) '
                .'(HOME_TO_LAGER|LAGER_TO_HOME) via '
                .'(P03_2CAM_JV_THEN_WORK|P03_2CAM_WORK_THEN_JV)(?:\s|$)/D',
                $payload,$proof)) {
                $action=$proof[2];
                $via=$proof[3];
                if (($action==='HOME_TO_LAGER' && $via!=='P03_2CAM_JV_THEN_WORK')
                    || ($action==='LAGER_TO_HOME' && $via!=='P03_2CAM_WORK_THEN_JV')) {
                    $result['warnings'][]='P03_DIRECTION_ROUTE_MISMATCH';
                    continue;
                }
                $key=$at.'|'.$action.'|'.$via;
                if (isset($proofKeys[$key])) continue; // exact log replay
                $proofKeys[$key]=true;
                if ($action==='HOME_TO_LAGER') $result['p03']['homeToWork']++;
                else $result['p03']['workToHome']++;
                $result['p03']['lastProofLocalTime']=$at;
                $result['p03']['directions'][]=[
                    'localTime'=>$at,'direction'=>$action,
                    'source'=>'P03_2CAM','count'=>'UNKNOWN'
                ];
                continue;
            }
            if (preg_match('/^P03 COMMISSIONING COMPLETE: OUT=(\d+), IN=(\d+)\b/',
                $payload,$claim)) {
                $commissionClaims[]=['out'=>(int)$claim[1],'in'=>(int)$claim[2]];
                continue;
            }
            if (preg_match('/^P03 STRASSE LERNEN ([1-4])\/4 (OUT|IN) '
                .'Kamera-Richtung=(LeftToRight|RightToLeft)\b/',
                $payload,$street)) {
                $step=(int)$street[1];
                $event=['step'=>$step,'direction'=>$street[2],'camera'=>$street[3]];
                if (isset($streetSteps[$step]) && $streetSteps[$step]!==$event) {
                    $result['warnings'][]='STREET_CONFLICTING_LEARNING';
                }
                $streetSteps[$step]=$event;
                continue;
            }
        }
        $result['p03']['commissioningClaim']=count($commissionClaims)>0;
        foreach ($commissionClaims as $claim) {
            if ($claim['out']>=2 && $claim['in']>=2
                && $result['p03']['homeToWork']>=$claim['out']
                && $result['p03']['workToHome']>=$claim['in']) {
                $result['p03']['commissioningReplayed']=true;
                break;
            }
        }
        if ($result['p03']['commissioningClaim']
            && !$result['p03']['commissioningReplayed']) {
            $result['warnings'][]='P03_COMMISSIONING_NOT_FULLY_REPLAYED';
        }
        $result['street']['steps']=count($streetSteps);
        if (count($streetSteps)===4 && array_keys($streetSteps)===[1,2,3,4]) {
            $ok=$streetSteps[1]['direction']==='OUT'
                && $streetSteps[2]['direction']==='IN'
                && $streetSteps[3]['direction']==='OUT'
                && $streetSteps[4]['direction']==='IN'
                && $streetSteps[1]['camera']===$streetSteps[3]['camera']
                && $streetSteps[2]['camera']===$streetSteps[4]['camera']
                && $streetSteps[1]['camera']!==$streetSteps[2]['camera'];
            if ($ok && !$result['warnings']) {
                $result['street']['out']=$streetSteps[1]['camera'];
                $result['street']['in']=$streetSteps[2]['camera'];
                $result['street']['directionMappingCertified']=true;
            } else {
                $result['warnings'][]='STREET_DIRECTION_UNRESOLVED';
            }
        }
        return $result;
    }
}
