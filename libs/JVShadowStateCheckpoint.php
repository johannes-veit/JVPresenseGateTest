<?php
declare(strict_types=1);

/**
 * Flash-write reduction for frequently polled SHADOW state, without dropping
 * new events. Buffers are runtime-only; persistent attributes are checkpoints.
 * All event/watermark changes are persisted immediately. Idle P03 and
 * two-resident contact heartbeats join the existing bounded checkpoints.
 * Heartbeat checkpoints are spaced apart to reduce SymBox flash writes;
 * an unexpected restart may conservatively rebaseline observation coverage.
 */
final class JVShadowStateCheckpoint
{
    private const CHECKPOINT_SECONDS=600;
    private const OPTIMIZED=[
        'ResidentEventFusion','RouteEpisodeLedger','LocalActivityLedger',
        'ResidentPhoneContinuity','P03IncrementalTimeline'
    ];

    public static function useRuntimeCache(string $name):bool
    {
        return in_array($name,self::OPTIMIZED,true);
    }

    public static function shouldPersist(
        string $name,string $saved,string $candidate,int $now
    ):bool {
        if($candidate===$saved)return false;
        if(!self::useRuntimeCache($name))return true;
        if($now<=0)return true;
        $old=json_decode($saved,true);
        $new=json_decode($candidate,true);
        if(!is_array($old)||!is_array($new))return true;
        // P03's historical cursor schema has never carried a "schema"
        // field. Validate its ACTUAL legacy shape instead of silently
        // bypassing every proposed heartbeat optimization.
        if($name==='P03IncrementalTimeline'){
            foreach([$old,$new] as $cursor){
                if(($cursor['initialized']??null)!==true
                    ||!is_string($cursor['lastRaw']??null)
                    ||!is_array($cursor['events']??null)
                    ||!array_is_list($cursor['events'])
                    ||count($cursor['events'])>256)
                    return true;
            }
        }elseif(($old['schema']??null)!==1
            ||($new['schema']??null)!==1){
            return true;
        }
        $oldAt=self::checkpointAt($name,$old);
        $newAt=self::checkpointAt($name,$new);
        if($oldAt===null||$newAt===null||$oldAt>$now+5
            ||$newAt>$now+5||$newAt<$oldAt)
            return true;
        // Every 10 minutes persist even heartbeat-only changes. Otherwise
        // the 60min anti-replay continuity check could rebaseline on a restart.
        if($now-$oldAt>=self::CHECKPOINT_SECONDS)return true;
        return self::semanticWithoutHeartbeat($name,$old)
            !==self::semanticWithoutHeartbeat($name,$new);
    }

    private static function checkpointAt(string $name,array $state):?int
    {
        if($name==='P03IncrementalTimeline') {
            $t=$state['lastObservedAt']??null;
            return is_int($t)&&$t>0?$t:null;
        }
        if($name==='ResidentPhoneContinuity') {
            if(!is_array($state['people']??null))return null;
            $at=null;
            foreach(['johannes','carina'] as $person){
                $t=$state['people'][$person]['lastObservedAt']??null;
                if(!is_int($t)||$t<=0)return null;
                $at=$at===null?$t:min($at,$t);
            }
            return $at;
        }
        if($name==='LocalActivityLedger') {
            $t=$state['lastScanAt']??null;
            return is_int($t)&&$t>0?$t:null;
        }
        if($name==='RouteEpisodeLedger') {
            $t=$state['lastScanAt']??null;
            return is_int($t)&&$t>0?$t:null;
        }
        if($name==='ResidentEventFusion'){
            $at=$state['p03']['lastReadAt']??null;
            if(!is_int($at)||$at<=0)return null;
            foreach(['johannes','carina'] as $person){
                $t=$state['people'][$person]['lastObservedAt']??null;
                if(!is_int($t)||$t<=0)return null;
                $at=min($at,$t);
            }
            return $at;
        }
        return null;
    }

    private static function semanticWithoutHeartbeat(string $name,array $data):array
    {
        if($name==='ResidentPhoneContinuity'){
            foreach(['johannes','carina'] as $person)
                unset($data['people'][$person]['lastObservedAt']);
        }elseif($name==='P03IncrementalTimeline'){
            unset($data['lastObservedAt']);
        }elseif($name==='ResidentEventFusion'){
            foreach(['johannes','carina'] as $person)
                unset($data['people'][$person]['lastObservedAt']);
            unset($data['p03']['lastReadAt']);
        }elseif($name==='LocalActivityLedger'){
            // Only the passive once-per-minute poll timestamp is volatile.
            // Every new GUS impulse, source change, overflow and continuity
            // rebaseline still differs semantically and persists immediately.
            unset($data['lastScanAt']);
        }elseif($name==='RouteEpisodeLedger'){
            unset($data['lastScanAt']);
        }
        return $data;
    }
}
