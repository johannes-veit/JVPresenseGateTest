<?php
declare(strict_types=1);

/**
 * Read-only incremental replay of the EXISTING P03 Protocol String variable.
 * Previously completed commissioning proofs are a baseline, NEVER current
 * transfers.  A P03 camera proof is a directional event, NOT one person.
 *
 * This class does not access Symcon or P03 itself; callers only feed the
 * already existing P03 log string. No writes, actions, network or hardware.
 */
final class JVP03IncrementalTimeline
{
    public const SCHEMA=1;
    private const MAX_LOG_BYTES=262144;
    private const MAX_EVENTS=256;
    private const MAX_NEW_BATCH=128;
    private const WINDOW=86400;
    private const KEEP_LOG_BYTES=65536;

    public static function evaluate(string $raw,array $prior,int $now):array
    {
        if ($now<=0) {
            // An invalid clock is not permission to mutate durable state.
            return [
                'report'=>self::emptyReport(['INVALID_CLOCK_OR_LOG_SIZE']),
                'next'=>$prior
            ];
        }
        if (strlen($raw)>self::MAX_LOG_BYTES) {
            // A rejected oversized P03 read is a continuity break. The
            // previous implementation returned the OLD durable cursor,
            // allowing historical P03 proof rows to reappear on a later
            // healthy read. Keep only the bounded last-raw anchor, never
            // the former epoch's camera proof history. Subsequent append
            // is eligible only if explicitly anchored to this exact tail.
            return [
                'report'=>self::emptyReport([
                    'INVALID_CLOCK_OR_LOG_SIZE',
                    'P03_SOURCE_LOG_TOO_LARGE'
                ]),
                'next'=>[
                    'initialized'=>true,
                    'lastRaw'=>substr($raw,-self::KEEP_LOG_BYTES),
                    'events'=>[]
                ]
            ];
        }
        $initialized=($prior['initialized']??false)===true;
        $lastRaw=$prior['lastRaw']??null;
        $history=$prior['events']??[];
        if(!is_array($history)||!array_is_list($history)
            || count($history)>self::MAX_EVENTS
            || ($initialized && !is_string($lastRaw))) {
            $initialized=false;$lastRaw=null;$history=[];
        }
        $warnings=[];
        $continuity='OK';
        $fresh=[];
        $historicBaseline=!$initialized;
        if (!$initialized) {
            $continuity='BASELINE_ONLY';
        } elseif ($raw===$lastRaw) {
            // Normal repeated 60-second reads without new proof log entries.
        } else {
            $delta=null;
            if (str_starts_with($raw,$lastRaw)) {
                $delta=substr($raw,strlen($lastRaw));
            } elseif ($lastRaw!=='') {
                // P03 trims long Protocol to its latest ~55k bytes.
                // Anchor at the prior raw TAIL; a missing anchor is a
                // discontinuity, not a license to replay old commissionings.
                $n=min(2048,strlen($lastRaw));
                $tail=substr($lastRaw,-$n);
                if($n>=64) {
                    $pos=strpos($raw,$tail);
                    // An anchor appearing twice does not identify a
                    // unique log cut position. Do not choose the first
                    // arbitrary occurrence and fabricate an event replay.
                    if($pos!==false
                        &&strpos($raw,$tail,$pos+1)===false) {
                        $delta=substr($raw,$pos+$n);
                        $continuity='TAIL_OVERLAP_AFTER_ROTATION';
                    }
                }
            }
            if($delta===null) {
                $continuity='LOG_GAP_OR_RESET';
                $warnings[]='P03_LOG_GAP_OR_RESET';
            } else {
                $tokens=preg_split(
                    '/(?=\[20\d\d-\d\d-\d\d \d\d:\d\d:\d\d\])/',
                    $delta,-1,PREG_SPLIT_NO_EMPTY
                );
                if (!is_array($tokens)||count($tokens)>512) {
                    $warnings[]='P03_LOG_BATCH_INVALID';
                } else {
                    foreach($tokens as $token) {
                        if(!preg_match(
                            '/^\s*\[(20\d\d-\d\d-\d\d \d\d:\d\d:\d\d)\]\s*'
                            .'P03 PROOF (VERIFIED|STRONG_VERIFIED) '
                            .'(HOME_TO_LAGER|LAGER_TO_HOME) via '
                            .'(P03_2CAM_JV_THEN_WORK|P03_2CAM_WORK_THEN_JV)(?:\s|$)/D',
                            trim($token),$m
                        )) continue;
                        $direction=$m[3];$route=$m[4];
                        if (($direction==='HOME_TO_LAGER'
                            && $route!=='P03_2CAM_JV_THEN_WORK')
                            ||($direction==='LAGER_TO_HOME'
                                && $route!=='P03_2CAM_WORK_THEN_JV')) {
                            $warnings[]='P03_DIRECTION_ROUTE_MISMATCH';
                            continue;
                        }
                        $at=self::timestamp($m[1]);
                        if($at===null||$at<=$now-self::WINDOW
                            ||$at>$now+10) {
                            $warnings[]='P03_EVENT_TIME_UNTRUSTED';
                            continue;
                        }
                        $fresh[]=[
                            'at'=>$at,'localTime'=>$m[1],
                            'direction'=>$direction,'route'=>$route,
                            'source'=>'P03_TWO_MAST_VERIFIED'
                        ];
                    }
                }
            }
        }
        // A reset, unreadable oversized batch or truncated proof
        // stream makes PREVIOUS camera events from another source epoch
        // unsuitable for present-day phone correlation. Never combine
        // old retained proofs with a fresh unanchored P03 chronology.
        if($continuity==='LOG_GAP_OR_RESET'
            ||in_array('P03_LOG_BATCH_INVALID',$warnings,true)){
            $history=[];
            $fresh=[];
        }
        $entries=[];
        foreach($history as $event) {
            if(!is_array($event)
                || !is_int($event['at']??null)
                || $event['at']<=$now-self::WINDOW
                || $event['at']>$now+10
                || !in_array(($event['direction']??''),[
                    'HOME_TO_LAGER','LAGER_TO_HOME'
                ],true)
                || !is_string($event['localTime']??null)
                || !is_string($event['route']??null)
                || ($event['source']??null)!=='P03_TWO_MAST_VERIFIED'
                || (($event['direction']==='HOME_TO_LAGER'
                    &&$event['route']!=='P03_2CAM_JV_THEN_WORK')
                    ||($event['direction']==='LAGER_TO_HOME'
                    &&$event['route']!=='P03_2CAM_WORK_THEN_JV')))continue;
            $key=$event['localTime'].'|'.$event['direction'].'|'.$event['route'];
            $entries[$key]=$event;
        }
        $newProofs=0;
        $acceptedNew=[];
        foreach($fresh as $event) {
            $key=$event['localTime'].'|'.$event['direction'].'|'.$event['route'];
            if(isset($entries[$key])) {
                // Multiple same-second identical routes could be different
                // people, but P03 does not certify that. Count once.
                $warnings[]='P03_SAME_SECOND_PROOF_AMBIGUOUS';
                continue;
            }
            $entries[$key]=$event;
            $acceptedNew[]=$event;
            $newProofs++;
        }
        $newBatchOverflow=count($acceptedNew)>self::MAX_NEW_BATCH;
        if($newBatchOverflow){
            $warnings[]='P03_NEW_PROOF_BATCH_OVERFLOW';
            // More proofs than the bounded replay can safely publish:
            // do not keep a *partial* camera sequence across the gap.
            $entries=[];
            $acceptedNew=[];
            $newProofs=0;
        }
        usort($acceptedNew,static fn($a,$b)=>
            ($a['at']<=>$b['at'])?:strcmp($a['direction'],$b['direction']));
        usort($entries,static fn($a,$b)=>($b['at']<=>$a['at'])
            ?:strcmp($a['direction'],$b['direction']));
        if(count($entries)>self::MAX_EVENTS) {
            $warnings[]='P03_EVENT_HISTORY_LIMITED';
            $entries=array_slice($entries,0,self::MAX_EVENTS);
        }
        // Compact, time-only view of all retained P03 proofs for an
        // externally supplied field-observation comparison. The ordinary
        // 15-entry display and durable cursor remain unchanged.
        // One anonymous P03 proof NEVER counts identifiable people.
        $comparisonTimeline=[];
        foreach($entries as $entry){
            $comparisonTimeline[]=[
                'at'=>$entry['at'],
                'direction'=>$entry['direction'],
                'source'=>'P03_TWO_MAST_VERIFIED'
            ];
        }
        $out=0;$in=0;
        foreach($entries as $event) {
            if($event['direction']==='HOME_TO_LAGER')$out++;
            else $in++;
        }
        $report=[
            'schema'=>self::SCHEMA,
            'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personCountCertified'=>false,
            'identityCertified'=>false,
            'historicCommissioningReplayedAsLive'=>false,
            'baselineOnly'=>$historicBaseline,
            'logContinuity'=>$continuity,
            'newDirectionProofsThisRead'=>$newProofs,
            'newlyVerifiedDirectionProofs'=>$newBatchOverflow
                ?[]:$acceptedNew,
            'newProofBatchOverflow'=>$newBatchOverflow,
            'directionProofs24h'=>['HOME_TO_LAGER'=>$out,
                'LAGER_TO_HOME'=>$in],
            'lastDirectionAt'=>($entries[0]['at']??null),
            'recentDirectionProofs'=>array_slice($entries,0,15),
            'verifiedDirectionTimeline24h'=>$comparisonTimeline,
            'verifiedTimeline24hCount'=>count($comparisonTimeline),
            'verifiedTimelineTruncated'=>in_array(
                'P03_EVENT_HISTORY_LIMITED',$warnings,true),
            'warnings'=>array_values(array_unique($warnings))
        ];
        return ['report'=>$report,'next'=>[
            'initialized'=>true,
            'lastRaw'=>strlen($raw)>self::KEEP_LOG_BYTES
                ?substr($raw,-self::KEEP_LOG_BYTES):$raw,
            'events'=>$entries
        ]];
    }

    private static function timestamp(string $localTime):?int
    {
        try {
            $date=DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',$localTime,new DateTimeZone('Europe/Berlin')
            );
            if(!$date || $date->format('Y-m-d H:i:s')!==$localTime)
                return null;
            $at=$date->getTimestamp();
            // At autumn CET fallback, a local 02:xx occurs twice.
            // P03 writes no UTC offset; never guess which camera
            // crossing occurred. Ignore ambiguous wall clock proofs.
            $tz=new DateTimeZone('Europe/Berlin');
            $transitions=$tz->getTransitions($at-86400,$at+86400);
            if(is_array($transitions)){
                $prev=null;
                foreach($transitions as $tr){
                    $cur=$tr['offset']??null;$ts=$tr['ts']??null;
                    if(is_int($prev)&&is_int($cur)&&is_int($ts)
                        &&$cur<$prev){
                        $from=gmdate('Y-m-d H:i:s',$ts+$cur);
                        $to=gmdate('Y-m-d H:i:s',$ts+$prev);
                        if($localTime>=$from&&$localTime<$to)
                            return null;
                    }
                    if(is_int($cur))$prev=$cur;
                }
            }
            return $at;
        }catch(Throwable $e) {return null;}
    }

    private static function emptyReport(array $warnings):array
    {
        return [
            'schema'=>self::SCHEMA,
            'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personCountCertified'=>false,
            'identityCertified'=>false,
            'historicCommissioningReplayedAsLive'=>false,
            'baselineOnly'=>true,
            'logContinuity'=>'UNKNOWN',
            'newDirectionProofsThisRead'=>0,
            'newlyVerifiedDirectionProofs'=>[],
            'newProofBatchOverflow'=>false,
            'directionProofs24h'=>['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0],
            'lastDirectionAt'=>null,'recentDirectionProofs'=>[],
            'verifiedDirectionTimeline24h'=>[],
            'verifiedTimeline24hCount'=>0,'verifiedTimelineTruncated'=>false,
            'warnings'=>$warnings
        ];
    }
}
