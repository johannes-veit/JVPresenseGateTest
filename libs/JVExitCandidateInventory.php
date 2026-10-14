<?php
declare(strict_types=1);

/**
 * Metadata-only, read-only suggestions for existing property boundary STATUS
 * variables. Never bind by name; neither names nor signal states certify
 * polarity, human direction, presence, property coverage or emptiness.
 *
 * In particular LCN controller room names (e.g. "OG Bad Tür") and the mere
 * availability of a Shelly endpoint are NOT boundary contact signals.
 */
final class JVExitCandidateInventory
{
    private const MAX_RESULTS=80;
    private const BROAD_NAME=
        '/(?:garage|garagentor|rolltor|sektionaltor|haustür|haustuer|'.
        'eingangstür|eingangstuer|türkontakt|tuerkontakt|'.
        'torkontakt|türöffnung|einfahrt|zufahrt|hoftor|gartentor|'.
        'schiebetor|flügeltor|pforte|torantrieb|fahrzeug|'.
        'fahrzeugerkennung|türschloss|türsensor|kontakt|tür|tuer|\\btor\\b)/iu';

    private static function matches(string $regex,string $value):bool
    {
        return preg_match($regex,$value)===1;
    }

    public static function select(array $items,bool $scanLimited=false):array
    {
        $candidates=[];$seen=[];$broad=0;
        foreach($items as $item) {
            if(!is_array($item)
                || !is_int($item['variableId']??null)
                || $item['variableId']<=0
                || !in_array(($item['type']??null),[0,1],true))continue;
            $id=$item['variableId'];
            if(isset($seen[$id]))continue;
            $seen[$id]=true;

            $name=trim((string)($item['variableName']??''));
            $parent=trim((string)($item['parent']??''));
            $grand=trim((string)($item['grandparent']??''));
            $path=$grand.' / '.$parent.' / '.$name;
            // Ignore separate observers, WLAN and own Shadow outputs.
            if(self::matches(
                '/(?:WLAN|WiFi|Wi-Fi|Identity\\s*Shadow|Presence\\s*Gate\\s*Test)/iu',
                $path))continue;
            if(!self::matches(self::BROAD_NAME,$path))continue;
            $broad++;

            // A state-like leaf is mandatory; e.g. Erreichbar, Cloud Status,
            // Test bereit, Intensität and Referenzfahrt are not contact states.
            $stateLeaf=self::matches(
                '/^(?:status(?:\\s*\\d+)?|eingangstatus(?:\\s*\\d+)?|'.
                'kontakt(?:status)?|zustand|stellung|position|'.
                'endlage(?:\\s*(?:auf|zu))?|geöffnet|geschlossen|offen|'.
                'auf|zu|(?:fahrzeug|person)\\s+erkannt|'.
                '.*(?:tür|tuer|tor|fahrzeug|garage).*(?:kontakt|status|'.
                'erkannt|offen|geöffnet|geschlossen)|'.
                '(?:kontakt|status).*(?:tür|tuer|tor|garage).*)$/iu',
                $name);
            if(!$stateLeaf)continue;
            // These are separate functions even if a physical door/gate name
            // appears in their room or device ancestry.
            if(self::matches(
                '/(?:\\b(?:licht|led|bewegungsmelder|jalousie|rollladen|'.
                'heizung|kühlung|kuehlung|cloud|temperatur|dimmer|'.
                'relais|ausgang|bedienung|befehl|steuerbefehl|'.
                'night|nachtfreigabe|dahua|kamera)\\b)/iu',
                $parent.' '.$grand))continue;
            // Generic LCN device "EG Schlafen Tür" is not a real physical
            // contact. Require a meaningful exit/vehicle name, not bare Tür.
            $gateSubject=self::matches(
                '/(?:schiebetor|garagentor|hoftor|gartentor|rolltor|'.
                'sektionaltor|flügeltor|fluegeltor|torantrieb|torkontakt|'.
                'pforte|zufahrt|einfahrt|\\btor\\b|\\bgarage\\b)/iu',
                $name.' '.$parent);
            $contactSubject=self::matches(
                '/(?:haustür|haustuer|eingangstür|eingangstuer|nebentür|'.
                'nebentuer|personentür|personentuer|außentür|aussentür|'.
                'türkontakt|tuerkontakt|türsensor|tuersensor)/iu',
                $name.' '.$parent);
            $vehicleSubject=self::matches(
                '/(?:fahrzeug|kennzeichenerkennung)/iu',$name.' '.$parent);
            $gateContext=self::matches(
                '/(?:schiebetor|garagentor|hoftor|gartentor|rolltor|'.
                'sektionaltor|flügeltor|fluegeltor|torantrieb|pforte|'.
                'zufahrt|einfahrt|\\btor\\b|\\bgarage\\b)/iu',$grand);
            $contactContext=self::matches(
                '/(?:haustür|haustuer|eingangstür|eingangstuer|nebentür|'.
                'nebentuer|personentür|personentuer|außentür|aussentür|'.
                'türkontakt|tuerkontakt)/iu',$grand);
            $vehicleContext=self::matches(
                '/(?:fahrzeug|kennzeichenerkennung)/iu',$grand);

            if(!$gateSubject && !$contactSubject && !$vehicleSubject
                && !$gateContext && !$contactContext && !$vehicleContext)
                continue;

            $kind=$vehicleSubject||$vehicleContext?'VEHICLE'
                :($contactSubject||$contactContext?'CONTACT':'GATE');
            $isInput=self::matches(
                '/^(?:input\\s*:?\\s*\\d+|eingang\\s*\\d+)$/iu',$parent)
                && self::matches('/^eingangstatus(?:\\s*\\d+)?$/iu',$name);
            $isEndstop=self::matches(
                '/^endlage\\s*(?:auf|zu)$/iu',$parent)
                && self::matches('/^(?:status|kontakt|zustand|auf|zu)$/iu',$name);
            $directContact=$contactSubject
                && self::matches('/^(?:kontakt(?:status)?|status|zustand|'.
                    'geöffnet|geschlossen|offen)$/iu',$name);
            $namedSensor=self::matches(
                '/(?:türkontakt|tuerkontakt|torkontakt|türsensor|tuersensor)/iu',
                $name.' '.$parent);
            $deviceOutput=self::matches('/shelly|relay|relais|output/iu',$parent)
                && !$gateSubject && !$contactSubject && !$vehicleSubject;
            if($isInput || $isEndstop || $directContact || $namedSensor) {
                $priority=1;
                $basis=$isInput?'INPUT_STATUS_NAME_ONLY':
                    ($isEndstop?'ENDSTOP_STATUS_NAME_ONLY':
                    'CONTACT_NAME_ONLY');
            } elseif($deviceOutput) {
                $priority=3;
                $basis='DEVICE_OUTPUT_FUNCTION_UNKNOWN';
            } else {
                $priority=2;
                $basis='BOUNDARY_STATUS_NAME_ONLY';
            }
            $candidates[]=[
                'variableId'=>$id,
                'type'=>$item['type']===0?'Boolean':'Integer',
                'name'=>$name,'parent'=>$parent,'grandparent'=>$grand,
                'reviewPriority'=>[1=>'HIGH',2=>'MEDIUM',3=>'CHECK_FUNCTION'][$priority],
                'signalKindHint'=>$kind,
                'candidateBasis'=>$basis,
                'hintOnly'=>true,
                'directionVerified'=>false,
                'polarityVerified'=>false
            ];
        }
        $priorityOrder=['HIGH'=>1,'MEDIUM'=>2,'CHECK_FUNCTION'=>3];
        usort($candidates,static fn($a,$b)=>
            ($priorityOrder[$a['reviewPriority']]<=>$priorityOrder[$b['reviewPriority']])
            ?:($a['variableId']<=>$b['variableId']));
        $count=count($candidates);
        return [
            'schema'=>1,
            'authority'=>'READ_ONLY_EXIT_SOURCE_CANDIDATES',
            'configurationChanged'=>false,
            'devicesChanged'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'candidateCount'=>$count,
            'legacyBroadMatchCount'=>$broad,
            'excludedBroadMatches'=>max(0,$broad-$count),
            'listedCandidates'=>array_slice($candidates,0,self::MAX_RESULTS),
            'candidateListLimited'=>$count>self::MAX_RESULTS,
            'symconScanLimited'=>$scanLimited,
            'candidatesAreNotVerifiedExitRoutes'=>true
        ];
    }
}
