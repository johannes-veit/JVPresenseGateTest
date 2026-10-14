<?php
declare(strict_types=1);

/**
 * Weak, anonymous evidence from existing INDOOR light and TV power outputs.
 * Motion-controlled lights may be driven by the SAME GUS; they are never
 * counted as independent witnesses. ON does not prove a human is there.
 */
final class JVIndoorPresenceHints
{
    public static function evaluate(array $readings,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>'INDOOR_DEVICE_HINTS_ONLY',
            'sourceCount'=>0,'readableCount'=>0,'onCount'=>0,
            'lightsOn'=>[],'tvOn'=>[],
            'latestOnChangeAt'=>null,
            'hintLevel'=>'NONE','sources'=>[],
            'motionControlledLightsAreNotIndependentGUS'=>true,
            'offDoesNotProveAbsence'=>true,
            'personCountCertified'=>false,'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,'alarmMayBeControlled'=>false,
            'warnings'=>[]
        ];
        if($now<=0){$out['warnings'][]='INVALID_CLOCK';return $out;}
        foreach($readings as $id=>$row){
            if(!is_string($id)||!is_array($row)||!in_array(
                $row['kind']??null,['LIGHT_INTERIOR','TV_POWER'],true))continue;
            $out['sourceCount']++;
            if(($row['zone']??null)!=='HOME'){
                $out['warnings'][]='NOT_IN_HOME_ZONE:'.$id;continue;
            }
            $name=(string)($row['name']??$id);
            if(self::isExterior($name)){
                $out['warnings'][]='EXTERIOR_SOURCE_REJECTED:'.$id;continue;
            }
            $state=$row['state']??null;
            if(!in_array($state,['ACTIVE','INACTIVE'],true)){
                $out['warnings'][]='UNKNOWN_SOURCE:'.$id;continue;
            }
            $out['readableCount']++;
            $at=$row['changedAt']??null;
            if(!is_int($at)||$at<=0||$at>$now+5)$at=null;
            $isOn=$state==='ACTIVE';
            $out['sources'][$id]=[
                'name'=>$name,'kind'=>$row['kind'],
                'on'=>$isOn,'lastValueChangeAt'=>$at,
                'isRecentlySwitchedOn'=>$isOn&&$at!==null&&$now-$at<=600
            ];
            if(!$isOn)continue;
            $out['onCount']++;
            if($row['kind']==='LIGHT_INTERIOR')$out['lightsOn'][]=$id;
            else $out['tvOn'][]=$id;
            if($at!==null&&($out['latestOnChangeAt']===null
                ||$at>$out['latestOnChangeAt']))$out['latestOnChangeAt']=$at;
        }
        $out['hintLevel']=$out['onCount']>0?'WEAK_INDOOR_DEVICE_ON':'NONE';
        $out['warnings']=array_values(array_unique($out['warnings']));
        return $out;
    }

    public static function isExterior(string $path):bool
    {
        return preg_match(
            '/(?:außen|aussen|outdoor|terrasse|balkon|garten|hof|hoflicht|einfahrt|'
            .'straße|strasse|lagerplatz|werkstatt|garage|carport|fassade|'
            .'eingangsbeleuchtung|wegleuchte|außenbeleuchtung|aussenbeleuchtung)/iu',
            $path
        )===1;
    }
}
