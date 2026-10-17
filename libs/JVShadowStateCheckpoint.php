<?php
declare(strict_types=1);

/**
 * Flash-write reduction for frequently polled SHADOW state, without dropping
 * new events. Buffers are runtime-only; persistent attributes are checkpoints.
 * All event/watermark changes are persisted immediately.
 */
final class JVShadowStateCheckpoint
{
    private const CHECKPOINT_SECONDS=600;
    private const OPTIMIZED=['ResidentEventFusion','RouteEpisodeLedger'];

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
        if(!is_array($old)||!is_array($new)
            ||($old['schema']??null)!==1||($new['schema']??null)!==1)
            return true;
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
        if($name==='ResidentEventFusion'){
            foreach(['johannes','carina'] as $person)
                unset($data['people'][$person]['lastObservedAt']);
            unset($data['p03']['lastReadAt']);
        }elseif($name==='RouteEpisodeLedger'){
            unset($data['lastScanAt']);
        }
        return $data;
    }
}
