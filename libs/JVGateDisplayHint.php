<?php
declare(strict_types=1);

/**
 * Reads already validated, existing Shelly/CS320 end-position inputs.
 * Prior validated control mapping: S1=ZU, S2=AUF. Unknown/stale source paths
 * are rejected by JVGateSignalAudit; raw position is a display hint only.
 * An opened/closed gate NEVER implies a person entered/exited property.
 */
final class JVGateDisplayHint
{
    public static function evaluate(?array $audit):array
    {
        $out=[
            'schema'=>1,'authority'=>'EXISTING_SCHIEBETOR_DISPLAY_HINT_ONLY',
            'source'=>'EXISTING_S1_ZU_S2_AUF',
            'endZuVariableId'=>56808,'endAufVariableId'=>40678,
            'positionHint'=>'UNKNOWN','endZu'=>null,'endAuf'=>null,
            'mapping'=>'S1_TRUE_S2_FALSE_ZU__S1_FALSE_S2_TRUE_AUF',
            'sourceMappingFromExistingGateDisplay'=>true,
            'signalPathAndBooleanVerified'=>false,
            'exitDirectionCertified'=>false,'personCountCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,'alarmMayBeControlled'=>false,
            'gateMayBeControlled'=>false
        ];
        if(!is_array($audit)||($audit['authority']??'')!=='READ_ONLY_GATE_SIGNAL_SNAPSHOTS'
            ||!is_array($audit['signals']??null))return $out;
        $map=[];
        foreach($audit['signals'] as $s){
            if(!is_array($s)||!in_array($s['variableId']??null,[56808,40678],true)
                ||!in_array($s['readState']??'',['OK','VALUE_ONLY_UNTIMED'],true)
                ||!is_bool($s['currentValue']??null))continue;
            // Require the known original input paths. A cloned/renamed
            // variable from another module must not be interpreted as S1/S2.
            if($s['variableId']===56808
                &&(($s['parent']??'')!=='Endlage ZU'||($s['name']??'')!=='Eingangstatus 1'))continue;
            if($s['variableId']===40678
                &&(($s['parent']??'')!=='input:2'||($s['name']??'')!=='Eingangstatus 2'))continue;
            $map[$s['variableId']]=$s['currentValue'];
        }
        if(!array_key_exists(56808,$map)||!array_key_exists(40678,$map))return $out;
        $zu=$map[56808];$auf=$map[40678];
        $out['signalPathAndBooleanVerified']=true;
        $out['endZu']=$zu;$out['endAuf']=$auf;
        $out['positionHint']=$zu&&$auf?'CONTRADICTORY_ENDSTOPS':
            ($zu?'ZU':($auf?'AUF':'ZWISCHENSTELLUNG_ODER_FAHRT'));
        return $out;
    }
}
