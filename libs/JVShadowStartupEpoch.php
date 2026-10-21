<?php
declare(strict_types=1);

/**
 * First healthy refresh after a process/module restart is a new observation
 * epoch, even when the last FLASH checkpoint is only seconds old.
 *
 * A durable lastObservedAt/lastScanAt is a timestamp of a PREVIOUS process,
 * not evidence that this process watched all source changes since then.
 * Preserve ordinary history for diagnostics where safe; reset only the
 * continuity and source-event baselines needed to avoid replay/phantom proofs.
 *
 * Pure, read-only state transformation; no additional Symcon attributes,
 * user-visible variables, timers, sensors or foreign writes.
 */
final class JVShadowStartupEpoch
{
    public static function fence(string $name,array $prior,bool $coldStart):array
    {
        if(!$coldStart)return $prior;
        switch($name){
            case 'LocalSensorState':
                // An active GUS flag changed during a shutdown is an initial
                // snapshot, not a newly observed VM_UPDATE motion edge.
                return [];
            case 'P03IncrementalTimeline':
                // The existing parser baselines the current entire raw log
                // and never attributes unobserved downtime proofs to NOW.
                $prior['lastObservedAt']=null;
                break;
            case 'LocalActivityLedger':
            case 'RouteEpisodeLedger':
                // Historical GUS entries / last anonymous episode may stay
                // visible, but 24h completeness and open-route continuity
                // must begin a fresh observed window after restart.
                $prior['lastScanAt']=null;
                break;
            case 'ResidentPhoneContinuity':
            case 'ResidentEventFusion':
                // Phone OUT/IN and camera delivery chains cannot span a
                // process restart just because their saved clock is recent.
                foreach(['johannes','carina'] as $person){
                    if(is_array($prior['people'][$person]??null))
                        $prior['people'][$person]['lastObservedAt']=null;
                }
                if($name==='ResidentEventFusion'
                    &&is_array($prior['p03']??null))
                    $prior['p03']['lastReadAt']=null;
                break;
        }
        return $prior;
    }
}
