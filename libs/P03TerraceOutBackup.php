<?php
declare(strict_types=1);

/**
 * Second independent OUT source when ONE mast stair IVS was missed.
 * Real 2026-10-11 walk: Terrace LeftToRight before JV_LEFT, then WORK_LEFT.
 * A directional Human CrossLine is mandatory for terrace OUT; its undirected
 * SMD Boolean alone must NEVER invent a departure.
 *
 * This returns only TEST candidates and does not update occupancy/zones.
 */
final class P03TerraceOutBackup
{
    public const JV_MAX_SEC=15.0;
    public const WORK_MAX_SEC=50.0;
    public const SETTLE_SEC=5.0;
    public const MAST_HISTORY_SEC=45.0;
    public const TERRACE_HISTORY_SEC=100.0;

    /**
     * @param array<int,array<string,mixed>> $mast
     * @param array<int,array<string,mixed>> $terrace
     * @return array{status:string,mastId:?string,terraceId:?string,path:string,gap:float,reason:string}
     */
    public static function evaluate(
        array $mast,array $terrace,float $now,float $primaryWindow=30.0
    ):array
    {
        $fail=static fn(string $state,string $why):array=>[
            'status'=>$state,'mastId'=>null,'terraceId'=>null,
            'path'=>'','gap'=>0.0,'reason'=>$why
        ];
        if ($now<=0.0) return $fail('UNKNOWN','INVALID_CLOCK');
        $masts=array_values(array_filter($mast,static fn($e):bool=>
            is_array($e) && empty($e['used'])
            && in_array((string)($e['source']??''),['WORK_LEFT','JV_LEFT'],true)
            && trim((string)($e['id']??''))!==''
            && is_numeric($e['ts']??null)
            && (float)$e['ts']>0 && (float)$e['ts']<=$now
            && $now-(float)$e['ts']<=self::MAST_HISTORY_SEC));
        $signals=array_values(array_filter($terrace,static fn($e):bool=>
            is_array($e) && empty($e['used'])
            && (string)($e['kind']??'')==='IVS'
            && (string)($e['direction']??'')==='LeftToRight'
            && trim((string)($e['id']??''))!==''
            && is_numeric($e['ts']??null)
            && (float)$e['ts']>0.0 && (float)$e['ts']<=$now
            && $now-(float)$e['ts']<=self::TERRACE_HISTORY_SEC));
        if (!$masts || !$signals) return $fail('UNKNOWN','MISSING_OUT_EVIDENCE');
        $candidates=[];
        foreach($signals as $signal) {
            $t=(float)$signal['ts'];
            // A near-time opposite direction for the same passage vetoes OUT.
            $conflict=false;
            foreach($terrace as $other) {
                if (($other['direction']??null)==='RightToLeft'
                    && isset($other['ts']) && is_numeric($other['ts'])
                    && abs((float)$other['ts']-$t)<=5.0) {
                    $conflict=true;
                    break;
                }
            }
            if ($conflict) continue;
            foreach($masts as $mastEvent) {
                $m=(float)$mastEvent['ts'];
                $src=(string)$mastEvent['source'];
                $gap=$m-$t;
                if ($gap<=0.0 || $gap>($src==='JV_LEFT'?self::JV_MAX_SEC:self::WORK_MAX_SEC))
                    continue;
                $other=$src==='JV_LEFT'?'WORK_LEFT':'JV_LEFT';
                // If both mast IVS starts are available, the primary two-mast
                // direction must decide; optional terrace must not compete.
                $secondMast=false;
                foreach($masts as $candidate) {
                    if (($candidate['source']??'')!==$other) continue;
                    if (abs((float)$candidate['ts']-$m)<=30.0
                        || abs((float)$candidate['ts']-$t)<=30.0) {
                        $secondMast=true;
                        break;
                    }
                }
                if ($secondMast) continue;
                $candidates[]=[
                    'status'=>$now<max($m+max(0.0,$primaryWindow),
                        $m+self::SETTLE_SEC)?'PENDING':'VERIFIED',
                    'mastId'=>(string)$mastEvent['id'],
                    'terraceId'=>(string)$signal['id'],
                    'path'=>$src==='JV_LEFT'
                        ?'TERRACE_OUT_IVS_THEN_JV_LEFT_STAIR'
                        :'TERRACE_OUT_IVS_THEN_WORK_LEFT_STAIR',
                    'gap'=>round($gap,3),
                    'reason'=>'TERRACE_LEFT_TO_RIGHT_PLUS_ONE_STAIR_IVS_TEST_ONLY',
                    'endTs'=>$m
                ];
            }
        }
        if (!$candidates) return $fail('UNKNOWN','NO_SAFE_OUT_PAIR');
        usort($candidates,static fn($a,$b)=>$b['endTs']<=>$a['endTs']);
        $best=$candidates[0];
        foreach($candidates as $c) {
            if (($c['mastId']!==$best['mastId'] || $c['terraceId']!==$best['terraceId'])
                && abs($c['endTs']-$best['endTs'])<=self::WORK_MAX_SEC) {
                return $fail('CONTRADICTION','MULTIPLE_POSSIBLE_OUT_PAIRS');
            }
        }
        unset($best['endTs']);
        return $best;
    }
}
