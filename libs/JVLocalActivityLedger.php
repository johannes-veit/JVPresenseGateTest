<?php
declare(strict_types=1);

/**
 * Passive 24-hour motion EDGE ledger for existing local GUS reports.
 * An edge is NOT one person. Upgrades to corroborated status never
 * increase the edge count. No camera, LCN, network or Symcon write API.
 */
final class JVLocalActivityLedger
{
    public const SCHEMA=1;
    private const WINDOW=86400;
    private const MAX_ENTRIES=2048;
    private const MAX_REPORT=15;
    private const ZONES=['HOME','WORK','PROPERTY_OTHER'];
    private const STATUSES=[
        'SINGLE_UNCERTAIN'=>1,
        'KAMIN_UNCORROBORATED'=>1,
        'KAMIN_KITCHEN_CORROBORATED'=>2,
        'MULTI_SENSOR_CORROBORATED'=>2
    ];

    /**
     * @param array $signals From JVLocalSensorEvidence::evaluate()
     * @param array $readings From same report; maps stable source ID to name, zone
     * @param array $previous Previously committed ledger in the module buffer
     * @return array{report:array,next:array}
     */
    public static function evaluate(
        array $signals,array $readings,array $previous,int $now
    ):array {
        $warnings=[];
        if ($now<=0) {
            return [
                'report'=>self::emptyReport(['INVALID_CLOCK']),
                'next'=>['schema'=>self::SCHEMA,'entries'=>[],'startedAt'=>null]
            ];
        }
        $startedAt=$previous['startedAt']??$now;
        if (!is_int($startedAt) || $startedAt<=0 || $startedAt>$now+5)
            $startedAt=$now;
        // Overflow invalidates claims of continuous 24h coverage until
        // the full affected window has elapsed (also after module restart).
        $capacityTruncatedAt=$previous['capacityTruncatedAt']??null;
        if(!is_int($capacityTruncatedAt)||$capacityTruncatedAt<=0
            ||$capacityTruncatedAt>$now+5
            ||$capacityTruncatedAt<=$now-self::WINDOW)
            $capacityTruncatedAt=null;
        $old=$previous['entries']??[];
        if (($previous['schema']??self::SCHEMA)!==self::SCHEMA
            || !is_array($old) || !array_is_list($old)
            || count($old)>self::MAX_ENTRIES) {
            $warnings[]='CORRUPT_LEDGER_BUFFER';
            // No intact continuous observation can be asserted after
            // corrupt local history. Restart only this anonymous baseline,
            // never erase other resident/P03 state or take external actions.
            $startedAt=$now;
            $capacityTruncatedAt=$now;
            $old=[];
        }
        $registered=[];
        foreach($readings as $id=>$row) {
            if (is_string($id) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$id)
                && is_array($row)
                && in_array($row['zone']??null,self::ZONES,true)
                && is_string($row['name']??null)
                && strlen($row['name'])<=120) {
                $registered[$id]=[
                    'name'=>$row['name'],'zone'=>$row['zone']
                ];
            }
        }
        $entries=[];
        foreach($old as $entry) {
            if(!is_array($entry)
                || !is_string($entry['id']??null)
                || !isset($registered[$entry['id']])
                || !is_int($entry['at']??null)
                || $entry['at']<=$now-self::WINDOW || $entry['at']>$now+5
                || !isset(self::STATUSES[$entry['status']??''])) continue;
            $k=$entry['id'].'@'.$entry['at'];
            $entries[$k]=[
                'id'=>$entry['id'],
                'at'=>$entry['at'],
                'status'=>$entry['status']
            ];
        }
        foreach($signals as $signal) {
            if (!is_array($signal)) {
                $warnings[]='INVALID_MOTION_SIGNAL';
                continue;
            }
            $id=$signal['id']??null;
            $at=$signal['at']??null;
            $status=$signal['status']??null;
            if (!is_string($id) || !isset($registered[$id])
                || !is_int($at) || $at<=$now-self::WINDOW || $at>$now+5
                || !is_string($status) || !isset(self::STATUSES[$status])
                || $at<$startedAt-5) {
                $warnings[]='INVALID_MOTION_SIGNAL';
                continue;
            }
            $k=$id.'@'.$at;
            if (!isset($entries[$k])
                || self::STATUSES[$status]>self::STATUSES[$entries[$k]['status']]) {
                $entries[$k]=['id'=>$id,'at'=>$at,'status'=>$status];
            }
        }
        if(count($entries)>self::MAX_ENTRIES) {
            $warnings[]='LEDGER_CAPACITY_REACHED';
            $capacityTruncatedAt=$now;
            // Keep newest with truthful bounded-window label rather than
            // claiming an exact 24-hour count.
            uasort($entries,static fn($a,$b)=>$b['at']<=>$a['at']);
            $entries=array_slice($entries,0,self::MAX_ENTRIES,true);
        }
        uasort($entries,static fn($a,$b)=>($b['at']<=>$a['at'])
            ?:strcmp($a['id'],$b['id']));
        $totals=['HOME'=>0,'WORK'=>0,'PROPERTY_OTHER'=>0];
        $corroborated=['HOME'=>0,'WORK'=>0,'PROPERTY_OTHER'=>0];
        $perSource=[];
        $last=null;
        $recent=[];
        foreach($entries as $event) {
            $source=$registered[$event['id']];
            $zone=$source['zone'];
            $totals[$zone]++;
            if(self::STATUSES[$event['status']]===2)$corroborated[$zone]++;
            if(!isset($perSource[$event['id']]))$perSource[$event['id']]=[
                'name'=>$source['name'],
                'zone'=>$zone,
                'motionEdges24h'=>0,
                'corroboratedEdges24h'=>0,
                'lastMotionAt'=>null
            ];
            $s=&$perSource[$event['id']];
            $s['motionEdges24h']++;
            if(self::STATUSES[$event['status']]===2)$s['corroboratedEdges24h']++;
            if($s['lastMotionAt']===null)$s['lastMotionAt']=$event['at'];
            unset($s);
            if($last===null)$last=$event['at'];
            if(count($recent)<self::MAX_REPORT)$recent[]=[
                'sourceID'=>$event['id'],
                'name'=>$source['name'],
                'zone'=>$zone,
                'at'=>$event['at'],
                'confidence'=>$event['status']
            ];
        }
        $out=[
            'schema'=>self::SCHEMA,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personCountCertified'=>false,
            'identityCertified'=>false,
            'startedAt'=>$startedAt,
            'windowSeconds'=>self::WINDOW,
            'full24hWindowObserved'=>$now-$startedAt>=self::WINDOW
                &&$capacityTruncatedAt===null
                && !in_array('LEDGER_CAPACITY_REACHED',$warnings,true),
            'capacityTruncatedAt'=>$capacityTruncatedAt,
            'lastMotionAt'=>$last,
            'totalMotionEdges24h'=>array_sum($totals),
            'perZoneMotionEdges24h'=>$totals,
            'perZoneCorroboratedEdges24h'=>$corroborated,
            'perSource'=>$perSource,
            'recentMotionEdges'=>$recent,
            'warnings'=>array_values(array_unique($warnings))
        ];
        return [
            'report'=>$out,
            'next'=>[
                'schema'=>self::SCHEMA,'startedAt'=>$startedAt,
                'capacityTruncatedAt'=>$capacityTruncatedAt,
                'entries'=>array_values($entries)
            ]
        ];
    }

    private static function emptyReport(array $warnings):array {
        return [
            'schema'=>self::SCHEMA,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personCountCertified'=>false,
            'identityCertified'=>false,
            'full24hWindowObserved'=>false,
            'lastMotionAt'=>null,'totalMotionEdges24h'=>0,
            'perZoneMotionEdges24h'=>[],
            'perZoneCorroboratedEdges24h'=>[],
            'perSource'=>[],
            'recentMotionEdges'=>[],
            'warnings'=>$warnings
        ];
    }
}
