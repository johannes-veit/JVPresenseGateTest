<?php
declare(strict_types=1);

/**
 * Alternative P03 IN proof candidate when one of the TWO mast stair-IVS
 * sensors misses a valid crossing. The optional terrace person alert is
 * correlated with a REAL Human RuleID4 stair event on a trusted mast.
 *
 * The physical direction calibration is provisional: these are isolated
 * SHADOW/TEST candidates only, never production occupancy evidence.
 *
 * Case A: WORK_LEFT human stair IVS -> TERRACE human status, 0..50 s.
 * Case B: JV_LEFT human stair IVS -> TERRACE human status, 0..15 s.
 * Additional Human TERRACE CrossLine/RightToLeft strengthens this match,
 * but is never required. LeftToRight blocks any IN candidate.
 */
final class P03TerraceInBackup
{
    public const WORK_MAX_SEC=50.0;
    public const JV_MAX_SEC=15.0;
    public const SETTLE_SEC=5.0;
    // Include the FULL 50s path plus the 5s settle time and scheduling
    // headroom. 40s previously rejected a valid WORK→Terrace 50s pair.
    public const MAST_HISTORY_SEC=self::WORK_MAX_SEC+self::SETTLE_SEC+10.0;

    /**
     * @param array<int,array<string,mixed>> $mast
     * @param array<int,array<string,mixed>> $terrace
     * @return array{status:string,mastId:?string,terraceId:?string,path:string,gap:float,reason:string}
     */
    public static function evaluate(
        array $mast,array $terrace,float $now
    ):array {
        $empty=static fn(string $state,string $reason):array=>[
            'status'=>$state,'mastId'=>null,'terraceId'=>null,
            'path'=>'','gap'=>0.0,'reason'=>$reason
        ];
        if ($now<=0.0) return $empty('UNKNOWN','INVALID_CLOCK');
        $masts=array_values(array_filter($mast,static fn($e):bool=>
            is_array($e) && empty($e['used'])
            && in_array((string)($e['source']??''),['WORK_LEFT','JV_LEFT'],true)
            && trim((string)($e['id']??''))!==''
            && isset($e['ts']) && is_numeric($e['ts'])
            && (float)$e['ts']>0.0 && (float)$e['ts']<=$now
            && $now-(float)$e['ts']<=self::MAST_HISTORY_SEC));
        $signals=array_values(array_filter($terrace,static fn($e):bool=>
            is_array($e) && empty($e['used'])
            && trim((string)($e['id']??''))!==''
            && in_array((string)($e['kind']??''),['PERSON','IVS'],true)
            && isset($e['ts']) && is_numeric($e['ts'])
            && (float)$e['ts']>0.0 && (float)$e['ts']<=$now
            && $now-(float)$e['ts']<=35.0
            && in_array($e['direction']??null,[null,'RightToLeft','LeftToRight'],true)));
        if (!$signals || !$masts) return $empty('UNKNOWN','MISSING_SENSOR');
        usort($signals,static fn($a,$b)=>(float)$b['ts']<=>(float)$a['ts']);
        $viable=[];
        foreach($signals as $signal) {
            // A physical OUT crossing must never be used as an IN fallback.
            if (($signal['direction']??null)==='LeftToRight') continue;
            $t=(float)$signal['ts'];
            // Even if the camera emits SMD immediately and IVS a few
            // seconds later, an opposite-direction IVS VETOES the SMD
            // fallback. Never ignore contradictory available direction.
            $opposite=false;
            foreach($signals as $otherSignal) {
                if (($otherSignal['direction']??null)==='LeftToRight'
                    && abs((float)$otherSignal['ts']-$t)<=5.0) {
                    $opposite=true;
                    break;
                }
            }
            if ($opposite) continue;
            foreach($masts as $mastEvent) {
                $src=(string)$mastEvent['source'];
                $m=(float)$mastEvent['ts'];
                $gap=$t-$m;
                $max=$src==='WORK_LEFT'?self::WORK_MAX_SEC:self::JV_MAX_SEC;
                if ($gap<=0.0 || $gap>$max) continue;

                // A second fresh, unconsumed mast stair event is a possible
                // full 2CAM proof or opposite trajectory. Leave it to the
                // primary 2CAM engine; no backup on ambiguous traffic.
                $other=$src==='WORK_LEFT'?'JV_LEFT':'WORK_LEFT';
                $ambiguous=false;
                foreach($masts as $candidate) {
                    if (($candidate['source']??'')!==$other) continue;
                    if (abs((float)$candidate['ts']-$t)<=30.0
                        || abs((float)$candidate['ts']-$m)<=30.0) {
                        $ambiguous=true;
                        break;
                    }
                }
                if ($ambiguous) continue;
                $viable[]=[
                    'status'=>$now<$t+self::SETTLE_SEC?'PENDING':'VERIFIED',
                    'mastId'=>(string)$mastEvent['id'],
                    'terraceId'=>(string)$signal['id'],
                    'path'=>$src==='WORK_LEFT'
                        ?'WORK_LEFT_STAIR_THEN_TERRACE_IN'
                        :'JV_LEFT_STAIR_THEN_TERRACE_IN',
                    'gap'=>round($gap,3),
                    'reason'=>'TERRACE_PERSON_AFTER_ONE_VALID_STAIR_IVS_TEST_ONLY',
                    'signalTs'=>$t
                ];
            }
        }
        if (!$viable) return $empty('UNKNOWN','NO_SAFE_IN_PAIR');
        // A single optional terrace signal cannot prove two different people
        // or two alternative camera trajectories at once.
        usort($viable,static fn($a,$b)=>$b['signalTs']<=>$a['signalTs']);
        $best=$viable[0];
        foreach($viable as $v) {
            if ($v['terraceId']===$best['terraceId']
                && $v['mastId']!==$best['mastId']) {
                return $empty('CONTRADICTION','MULTIPLE_MAST_CANDIDATES');
            }
        }
        unset($best['signalTs']);
        return $best;
    }

    /** Strictly match one of the observer's accepted human IVS starts. */
    public static function qualifiedTerraceIvs(
        array $entry,array $rule
    ):?string {
        if (($entry['code']??'')!=='CrossLineDetection'
            || strtolower((string)($entry['action']??''))!=='start'
            || ($entry['decision']??'')!=='STAIR_HUMAN_START_COUNTED'
            || ($entry['rejectionReason']??'')!=='MATCHED_FILTER'
            || (string)($entry['ruleName']??'')!==(string)($rule['name']??'')
            || !is_numeric($rule['id']??null)) {
            return null;
        }
        $numericId=false;
        foreach(['cfgRuleId','ruleIdUpper','ruleIdLower','ruleId'] as $key) {
            $actual=$entry[$key]??null;
            if ($actual===null||$actual==='') continue;
            if (!is_numeric($actual)||(int)$actual!==(int)$rule['id'])
                return null;
            $numericId=true;
        }
        if (!$numericId) return null;
        $classification=strtolower(trim((string)($entry['classification']??'')));
        if ($classification!=='human' && !($classification===''
            && !empty($rule['humanOnly']))) return null;
        $direction=(string)($entry['direction']??'');
        return in_array($direction,['RightToLeft','LeftToRight'],true)
            ? $direction : null;
    }
}
