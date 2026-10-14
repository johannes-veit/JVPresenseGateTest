<?php
declare(strict_types=1);

/**
 * Local Symcon sensor interpretation. NO network I/O, NO LCN commands, NO
 * occupancy changes. Inactive/quiet sensors cannot certify human absence.
 * Uses VariableChanged, not VariableUpdated, for motion edges.
 *
 * A freshly enabled instance establishes a snapshot baseline and deliberately
 * does not manufacture a new "human seen" event from an old active flag.
 */
final class JVLocalSensorEvidence
{
    public const SCHEMA = 1;
    public const KINDS = ['MOTION','MOTION_KAMIN','MOTION_KITCHEN','LIGHT','GATE','VEHICLE','CONTACT'];
    public const ZONES = ['HOME','WORK','PROPERTY_OTHER'];
    private const LIMIT=64;
    private const HISTORY_TTL=180;
    private const CORROBORATION_WINDOW=60;
    private const FRESH_EDGE_TTL=90;

    /**
     * @param array<int,array<string,mixed>> $sources
     *  Each row: SourceID, Name, Kind, Zone, VariableID, Enabled.
     * @return array{sources:array,errors:array}
     */
    public static function validate(array $sources): array
    {
        if (!array_is_list($sources) || count($sources)>self::LIMIT) {
            return ['sources'=>[],'errors'=>['INVALID_SOURCE_LIST']];
        }
        $out=[];$ids=[];$vars=[];$errors=[];
        foreach($sources as $row) {
            if (!is_array($row)) { $errors[]='INVALID_ROW';continue; }
            if (($row['Enabled']??true)!==true) continue;
            $id=$row['SourceID']??null;
            $name=$row['Name']??'';
            $kind=$row['Kind']??null;
            $zone=$row['Zone']??null;
            $var=$row['VariableID']??null;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$id)
                || !is_string($name) || strlen($name)>120
                || !is_string($kind) || !in_array($kind,self::KINDS,true)
                || !is_string($zone) || !in_array($zone,self::ZONES,true)
                || !is_int($var) || $var<=0) {
                $errors[]='INVALID_ROW';
                continue;
            }
            if (($kind==='MOTION_KAMIN'||$kind==='MOTION_KITCHEN') && $zone!=='HOME') {
                $errors[]='INVALID_SPECIAL_ZONE:'.$id;
                continue;
            }
            if (isset($ids[$id]) || isset($vars[$var])) {
                $errors[]='DUPLICATE_SOURCE_OR_VARIABLE:'.$id;
                continue;
            }
            $ids[$id]=true;$vars[$var]=true;
            $out[]=[
                'id'=>$id,'name'=>$name,'kind'=>$kind,'zone'=>$zone,'variableID'=>$var
            ];
        }
        return ['sources'=>$out,'errors'=>$errors];
    }

    /**
     * Snapshots indexed by configured VariableID:
     * ['value'=>bool|int, 'type'=>0|1, 'changedAt'=>Unix seconds].
     * All fields are verified by the adapter; never assume an event without
     * the real source's VariableChanged and a visible active state.
     *
     * Previous state is a lightweight Symcon buffer, not IPS variables.
     * @return array{report:array,next:array}
     */
    public static function evaluate(
        array $sources,array $snapshots,array $previous,int $now
    ): array {
        $config=self::validate($sources);
        $warnings=$config['errors'];
        if ($now<=0) {
            $warnings[]='INVALID_CLOCK';
        }
        if ($warnings) {
            return ['report'=>self::emptyReport($warnings),
                'next'=>['initialized'=>false,'seen'=>[],'history'=>[]]];
        }
        $initialized=($previous['initialized']??false)===true;
        $seen=is_array($previous['seen']??null)?$previous['seen']:[];
        $history=is_array($previous['history']??null)?$previous['history']:[];
        $activeSourceIds=array_fill_keys(array_column($config['sources'],'id'),true);
        $seen=array_intersect_key($seen,$activeSourceIds);
        $history=array_values(array_filter($history,static function($h) use($now,$activeSourceIds):bool {
            return is_array($h) && is_string($h['id']??null)
                && isset($activeSourceIds[$h['id']])
                && is_int($h['at']??null) && ($h['at']>=$now-self::HISTORY_TTL)
                && ($h['at']<=$now+5);
        }));
        $readings=[];
        $lights=[];
        $gates=[];
        $vehicles=[];
        $contacts=[];
        foreach($config['sources'] as $s) {
            $id=$s['id'];$snapshot=$snapshots[$s['variableID']]??null;
            $value=null;$at=0;$valid=false;
            if (is_array($snapshot)
                && is_int($snapshot['type']??null)
                && in_array($snapshot['type'],[0,1],true)
                && is_int($snapshot['changedAt']??null)
                && ($snapshot['changedAt']>0)
                && ($snapshot['changedAt']<=$now+5)
                && (($snapshot['type']===0 && is_bool($snapshot['value']??null))
                    || ($snapshot['type']===1 && is_int($snapshot['value']??null)
                        && $snapshot['value']>=0))) {
                $at=$snapshot['changedAt'];
                $value=$snapshot['type']===0 ? $snapshot['value'] : ($snapshot['value']>0);
                $valid=true;
            }
            $readings[$id]=[
                'name'=>$s['name'],'kind'=>$s['kind'],'zone'=>$s['zone'],
                'state'=>$valid?($value?'ACTIVE':'INACTIVE'):'UNKNOWN',
                'changedAt'=>$valid?$at:null,
                'isFreshMotion'=>false
            ];
            if (!$valid) {
                $warnings[]='INVALID_SNAPSHOT:'.$id;
                continue;
            }
            if ($s['kind']==='LIGHT') $lights[$id]=$value?'ON':'OFF';
            if ($s['kind']==='GATE') $gates[$id]=$value?'ACTIVE':'INACTIVE';
            if ($s['kind']==='VEHICLE') $vehicles[$id]=$value?'ACTIVE':'INACTIVE';
            if ($s['kind']==='CONTACT') $contacts[$id]=$value?'ACTIVE':'INACTIVE';

            // Preserve the current source timestamp to prevent replay on
            // repeated polls / when the motion flag stays ON. If the clock
            // steps backwards or an older update arrives, do not replay it.
            $knownBefore=is_int($seen[$id]??null);
            $lastSeen=$knownBefore?$seen[$id]:0;
            if ($at>$lastSeen) $seen[$id]=$at;
            $isMotion=str_starts_with($s['kind'],'MOTION');
            // Also baseline newly configured sensors on an already running instance.
            if ($isMotion && $initialized && $knownBefore && $value && $at>$lastSeen
                && $at>=$now-self::FRESH_EDGE_TTL) {
                $readings[$id]['isFreshMotion']=true;
                $history[]=[
                    'id'=>$id,'at'=>$at,'kind'=>$s['kind'],'zone'=>$s['zone']
                ];
            }
        }
        // Keep max 64 recent edges; excessive repeated events MUST NOT be
        // silently accepted as full human presence. State is still HINT_ONLY.
        usort($history,static fn($a,$b)=>($a['at']<=>$b['at']));
        if (count($history)>self::LIMIT) {
            $warnings[]='MOTION_HISTORY_OVERFLOW';
            $history=array_slice($history,-self::LIMIT);
        }

        $kitchenByTime=[];
        foreach($history as $h) if($h['kind']==='MOTION_KITCHEN') $kitchenByTime[]=$h['at'];
        $signals=[];
        $latestStrong=[];
        $latestCandidate=[];
        foreach($history as $i=>$h) {
            $status='SINGLE_UNCERTAIN';
            $clockIgnored=false;
            if($h['kind']==='MOTION_KAMIN') {
                $minute=(int)(new DateTimeImmutable('@'.$h['at']))
                    ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('i');
                if($minute>=25 && $minute<=35) {
                    $status='CLOCK_WINDOW_IGNORED';
                    $clockIgnored=true;
                } else {
                    $supported=false;
                    foreach($kitchenByTime as $t) {
                        if(abs($t-$h['at'])<=self::CORROBORATION_WINDOW) {
                            $supported=true;break;
                        }
                    }
                    $status=$supported?'KAMIN_KITCHEN_CORROBORATED':'KAMIN_UNCORROBORATED';
                }
            } else {
                foreach($history as $j=>$other) {
                    if ($j===$i || $h['id']===$other['id']
                        || $h['zone']!==$other['zone']
                        || abs($h['at']-$other['at'])>self::CORROBORATION_WINDOW)
                        continue;
                    if($other['kind']==='MOTION_KAMIN') {
                        $minute=(int)(new DateTimeImmutable('@'.$other['at']))
                            ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('i');
                        // A Kamin motion is only a corroborator if Kitchen
                        // also moved within 60 sec, outside the clock window.
                        if($minute>=25 && $minute<=35
                            || $h['kind']!=='MOTION_KITCHEN') continue;
                    }
                    $status='MULTI_SENSOR_CORROBORATED';
                    break;
                }
            }
            if($clockIgnored) continue; // Ignore known clock artifact
            $signals[]=[
                'id'=>$h['id'],'at'=>$h['at'],'zone'=>$h['zone'],'status'=>$status
            ];
            if(!isset($latestCandidate[$h['zone']]) || $h['at']>$latestCandidate[$h['zone']])
                $latestCandidate[$h['zone']]=$h['at'];
            if(in_array($status,['KAMIN_KITCHEN_CORROBORATED','MULTI_SENSOR_CORROBORATED'],true)) {
                if(!isset($latestStrong[$h['zone']]) || $h['at']>$latestStrong[$h['zone']])
                    $latestStrong[$h['zone']]=$h['at'];
            }
        }
        $zones=[];
        foreach(self::ZONES as $zone) {
            $strong=$latestStrong[$zone]??0;
            $candidate=$latestCandidate[$zone]??0;
            $zones[$zone]=[
                'activity'=>($strong>0 && $strong>=$now-self::HISTORY_TTL)
                    ?'CORROBORATED_MOTION_HINT'
                    :(($candidate>0 && $candidate>=$now-self::HISTORY_TTL)
                        ?'SINGLE_MOTION_HINT':'UNKNOWN'),
                'lastCorroboratedAt'=>$strong?:null,
                'lastCandidateAt'=>$candidate?:null
            ];
        }
        $report=[
            'schema'=>self::SCHEMA,'authority'=>'LOCAL_SENSOR_HINTS_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'baselineInitialized'=>$initialized,
            'sourceCount'=>count($config['sources']),
            'readings'=>$readings,
            'zones'=>$zones,
            'motionSignals'=>$signals,
            // This refers ONLY to configured lighting outputs; it is NEVER
            // 'all house lights off' or occupancy evidence.
            'configuredLights'=>$lights,
            'allConfiguredLightsOff'=>count($lights)>0
                && count($lights)===count(array_filter($config['sources'],
                    static fn($s)=>$s['kind']==='LIGHT'))
                && !in_array('ON',$lights,true),
            'gates'=>$gates,'vehicles'=>$vehicles,'contacts'=>$contacts,
            'warnings'=>array_values(array_unique($warnings))
        ];
        return ['report'=>$report,'next'=>[
            'initialized'=>true,'seen'=>$seen,'history'=>$history
        ]];
    }

    private static function emptyReport(array $warnings):array
    {
        return [
            'schema'=>self::SCHEMA,'authority'=>'LOCAL_SENSOR_HINTS_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'baselineInitialized'=>false,'sourceCount'=>0,
            'readings'=>[],'zones'=>[],
            'motionSignals'=>[],
            'configuredLights'=>[],'allConfiguredLightsOff'=>false,
            'gates'=>[],'vehicles'=>[],'contacts'=>[],
            'warnings'=>$warnings
        ];
    }
}
