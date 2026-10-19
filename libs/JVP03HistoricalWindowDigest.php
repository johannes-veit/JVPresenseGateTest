<?php
declare(strict_types=1);

/**
 * Compact anonymous P03 corridor time-bucket view of already stored 24h
 * proofs for the ONE existing copyable SHADOW report.
 * No person's name, MAC, coordinates, route identity or vehicle metadata.
 */
final class JVP03HistoricalWindowDigest
{
    public static function summarize(array $p03,int $now):array
    {
        $r=['schema'=>1,'authority'=>'P03_HISTORY_BUCKETS_ANONYMOUS_ONLY',
            'status'=>'SOURCE_UNVERIFIED','sourceCount'=>0,
            'fiveMinuteWindows'=>0,'hourlyLines'=>[],
            'baselineOnly'=>false,'historyMayBeIncomplete'=>true,
            'personCountCertified'=>false,'identifiedHumanCertified'=>false,
            'vehicleCertified'=>false,'mayArmAlarm'=>false];
        if($now<=0||($p03['schema']??null)!==1
            ||($p03['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY')
            return $r;
        $full=$p03['verifiedDirectionTimeline24h']??null;
        if(!is_array($full)||!array_is_list($full)
            ||count($full)>256
            ||($p03['verifiedTimeline24hCount']??null)!==count($full)){
            $r['status']='FULL_HISTORY_NOT_EXPOSED';
            return $r;
        }
        $r['baselineOnly']=($p03['baselineOnly']??false)===true;
        $r['historyMayBeIncomplete']=$r['baselineOnly']
            ||($p03['verifiedTimelineTruncated']??false)===true
            ||count($full)===256
            ||!in_array($p03['logContinuity']??null,
                ['OK','TAIL_OVERLAP_AFTER_ROTATION'],true);
        $counts=[];$tz=new DateTimeZone('Europe/Berlin');
        foreach($full as $proof){
            if(!is_array($proof)
                ||($proof['source']??null)!=='P03_TWO_MAST_VERIFIED'
                ||!in_array($proof['direction']??null,
                    ['HOME_TO_LAGER','LAGER_TO_HOME'],true)
                ||!is_int($proof['at']??null)
                ||$proof['at']<=$now-86400||$proof['at']>$now+5)
                continue;
            $at=$proof['at'];
            $window=intdiv($at,300)*300;
            $group=(new DateTimeImmutable('@'.$window))
                ->setTimezone($tz)->format('Y-m-d H P');
            $stamp=(new DateTimeImmutable('@'.$window))
                ->setTimezone($tz)->format('H:i');
            $counts[$group][$window]??=[
                'stamp'=>$stamp,'out'=>0,'in'=>0
            ];
            if($proof['direction']==='HOME_TO_LAGER')
                $counts[$group][$window]['out']++;
            else $counts[$group][$window]['in']++;
            $r['sourceCount']++;
        }
        ksort($counts,SORT_STRING);
        foreach($counts as $hour=>$windows){
            ksort($windows,SORT_NUMERIC);
            $parts=[];
            foreach($windows as $w){
                $parts[]=$w['stamp'].' O'.$w['out'].'/I'.$w['in'];
                $r['fiveMinuteWindows']++;
            }
            // One hour has <=12 five-minute buckets. Maximum width is
            // intentionally below the existing handoff's 230-char line cap.
            $r['hourlyLines'][]=$hour.' | '.implode(' ', $parts);
        }
        $r['status']=$r['baselineOnly']
            ?'HISTORIC_BASELINE_NOT_LIVE_PROOF'
            :($r['historyMayBeIncomplete']
                ?'BOUNDED_HISTORY_NOT_COMPLETE'
                :'RETAINED_ANONYMOUS_HISTORY_24H');
        return $r;
    }
}
