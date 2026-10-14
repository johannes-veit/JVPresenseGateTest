<?php
declare(strict_types=1);

/**
 * Metadata-only finder for installed, existing indoor light and TV statuses.
 * Recommendations are NEVER automatically linked; do not trust a name as an
 * action/feedback signal before choosing a real VariableID in SensorSources.
 */
final class JVIndoorHintInventory
{
    public static function select(array $entries,bool $limited=false):array
    {
        $list=[];$excluded=0;
        $targets=[
            'og_bad_fenster'=>'OG Bad Fenster',
            'og_flur_fenster'=>'OG Flur Fenster',
            'eg_gaeste_wc'=>'EG Gäste WC',
            'og_ankleide_rgb'=>'OG Ankleide RGB'
        ];
        foreach($entries as $item){
            if(!is_array($item)||!is_int($item['variableId']??null)
                ||$item['variableId']<=0||!in_array($item['type']??null,[0,1],true))
                continue;
            $name=(string)($item['variableName']??'');
            $parent=(string)($item['parent']??'');
            $grand=(string)($item['grandparent']??'');
            $path=trim($grand.' '.$parent.' '.$name);
            if(JVIndoorPresenceHints::isExterior($path)){$excluded++;continue;}
            if(preg_match('/bewegungsmelder|gus|praesenz|präsenz|reach|erreichbar|'
                .'befehl|bedienung|aktion|kommando|timer|sollwert|temperatur|'
                .'leistung|zaehler|zähler|ping|heizen|kühlen|kuehlen|'
                .'jalousie|rollladen|rolladen|schiebetor|freigabe/iu',$path))continue;
            $stateLeaf=preg_match('/^(?:status(?:\s*\d+)?|wert|intensität|intensitaet|'
                .'helligkeit|level|dimmwert|power|an|ein|ein\/aus|zustand|'
                .'licht|rgb|brightness)(?:\s*\d+)?$/iu',$name)===1;
            $target=null;
            foreach($targets as $id=>$label){
                if(self::norm($path)!==''&&str_contains(self::norm($path),self::norm($label))){
                    $target=$id;break;
                }
            }
            $tv=preg_match('/(?:\btv\b|fernseher|samsung\s*(?:q|tv)|'
                .'the\s*frame)/iu',$path)===1;
            $general=preg_match('/(?:licht|beleuchtung|leuchte|lampe|'
                .'strahler|spots|decke|fensterlicht)/iu',$path)===1;
            if(!$target&&!$tv&&!$general)continue;
            if(!$stateLeaf && !preg_match('/(?:status|intensität|intensitaet|'
                .'helligkeit|dimmwert|power)/iu',$name))continue;
            $kind=$tv?'TV_POWER':'LIGHT_INTERIOR';
            $score=$target?0:($tv?1:2);
            $list[]=[
                'variableId'=>$item['variableId'],
                'type'=>$item['type']===0?'Boolean':'Integer',
                'name'=>$name,'parent'=>$parent,'grandparent'=>$grand,
                'recommendedKind'=>$kind,
                'target'=>$target,
                'reviewPriority'=>$score===0?'NAMED_ROOM':($score===1?'TV':'OTHER_INDOOR'),
                'hintOnly'=>true,'isVerifiedStatusOutput'=>false,
                'isAutomaticallyLinked'=>false
            ];
        }
        usort($list,static fn($a,$b)=>($a['reviewPriority']==='NAMED_ROOM'?0:
            ($a['reviewPriority']==='TV'?1:2))<=>
            ($b['reviewPriority']==='NAMED_ROOM'?0:
            ($b['reviewPriority']==='TV'?1:2))
            ?:($a['variableId']<=>$b['variableId']));
        return [
            'schema'=>1,'authority'=>'INDOOR_LIGHT_TV_CANDIDATES_METADATA_ONLY',
            'candidateCount'=>count($list),'listedCandidates'=>array_slice($list,0,80),
            'candidateListLimited'=>count($list)>80,'symconScanLimited'=>$limited,
            'excludedExteriorHits'=>$excluded,
            'configuredSourcesChanged'=>false,'externalDevicesChanged'=>false,
            'allCandidatesRequireVerifiedStatusVariable'=>true,
            'personPresenceMayBeChanged'=>false,'propertyEmptyMayBeCertified'=>false
        ];
    }

    private static function norm(string $x):string
    {
        $x=mb_strtolower($x);
        return strtr($x,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','/'=>' ','-'=>' ']);
    }
}
