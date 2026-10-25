<?php
declare(strict_types=1);

/**
 * Compact native Symcon Tile Visualization HTML content.
 *
 * This is the ONLY requested extra String/HTMLBox variable. Everything
 * below is derived from already verified, read-only device observations.
 *
 * Human identity, route across the public road, walking vs. driving and
 * property-empty status are NOT established from anonymous P03/GUS/IVS.
 * The displayed HOME/WORK/NOT CONFIRMED is a PHONE observation only.
 */
final class JVResidentTileDashboard
{
    public static function evaluate(array $visu,array $wlan,array $work,array $p03,
        array $house,int $now,array $depart=[],array $rooms=[],array $fusion=[],array $routes=[],array $journey=[]):array
    {
        $people=[];
        $valid=($visu['schema']??null)===1
            &&($visu['authority']??null)==='RESIDENT_PHONE_VISU_HINTS_READ_ONLY';
        $wlanValid=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        $workValid=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
            &&($work['enabled']??false)===true;
        foreach(['johannes'=>'Johannes','carina'=>'Carina'] as $key=>$name){
            $v=$valid&&is_array($visu['people'][$key]??null)
                ?$visu['people'][$key]:[];
            $decision=$v['decision']??'UNKNOWN';
            $d=($depart['authority']??null)==='PHONE_DEPARTURE_CORRELATION_READ_ONLY'
                &&is_array($depart['people'][$key]??null)
                ?$depart['people'][$key]:[];
            $likely=$decision!=='HOME_PHONE_CONFIRMED'
                &&$decision!=='WORK_PHONE_CONFIRMED'
                &&$decision!=='CONFLICT'
                &&($d['assessment']??null)==='LIKELY_PHONE_DEPARTED';
            $status=match($decision){
                'HOME_PHONE_CONFIRMED'=>'ANWESEND',
                'WORK_PHONE_CONFIRMED'=>'ABWESEND',
                default=>$likely?'ABWESEND (WAHRSCHEINLICH)':'NICHT BESTÄTIGT'
            };
            $qualifier=match($decision){
                'HOME_PHONE_CONFIRMED'=>'Handy im Heim-WLAN erkannt',
                'WORK_PHONE_CONFIRMED'=>'Handy im Firmen-WLAN erkannt',
                'LAST_KNOWN_UNCONFIRMED'=>'Letzter WLAN-Ort, nicht mehr aktuell',
                'CONFLICT'=>'Widersprüchliche WLAN-Meldungen',
                default=>$likely
                    ?'Heim-WLAN nicht mehr bestätigt, danach Geofence OUT – Handy wahrscheinlich weggefahren'
                    :'Kein aktueller eindeutiger WLAN-Nachweis'
            };
            $wp=$wlanValid&&is_array($wlan['people'][$key]??null)
                ?$wlan['people'][$key]:[];
            $sp=$workValid&&is_array($work['people'][$key]??null)
                ?$work['people'][$key]:[];
            $macAt=$v['homeMacAt']??null;
            $ssidAt=$v['homeSSIDAt']??null;
            $firmAt=$v['workSSIDAt']??null;
            $last=$v['deviceEvidenceAt']??null;
            if(!is_int($last)||$last<=0||$last>$now+5)$last=null;
            $lastKnown=$v['lastKnownDeviceAt']??null;
            if(!is_int($lastKnown)||$lastKnown<=0||$lastKnown>$now+5
                ||$lastKnown<$now-86400)$lastKnown=null;
            $geoAt=($v['geofenceEventFresh']??false)===true
                &&is_int($v['geofenceLastEventAt']??null)
                &&($v['geofenceLastEventAt']??0)>0
                &&($v['geofenceLastEventAt']??0)<=$now+5
                ?$v['geofenceLastEventAt']:null;
            $geoKind=in_array(($v['geofenceLastEventKind']??null),
                ['IN','OUT'],true)?$v['geofenceLastEventKind']:null;
            $geoIsLatest=!$likely
                &&!in_array($decision,[
                    'HOME_PHONE_CONFIRMED','WORK_PHONE_CONFIRMED','CONFLICT'
                ],true)
                &&$geoAt!==null&&$geoKind!==null
                &&$geoAt>($last??$lastKnown??0);
            $when=$likely&&is_int($d['eventAt']??null)
                ?$d['eventAt']:($geoIsLatest?$geoAt:($last??$lastKnown));
            $hint=$likely
                ?'Letztes bestätigtes Geofence-OUT (Handy)'
                :($geoIsLatest
                    ?($geoKind==='IN'
                        ?'Letztes Geofence-IN (Haus/Firma, nicht Hauseingang)'
                        :'Letztes Geofence-OUT (Handy)')
                    :(in_array($decision,[
                        'HOME_PHONE_CONFIRMED','WORK_PHONE_CONFIRMED'
                    ],true)
                        ?'Letzte bestätigte WLAN-Erfassung'
                        :'Letzter gültiger WLAN-Hinweis'));
            if($likely){
                $qualifier='Heim-WLAN nicht mehr bestätigt, danach Geofence OUT – Handy wahrscheinlich weggefahren';
            }elseif($geoIsLatest &&$geoKind==='IN'){
                $qualifier='Handy wieder im gemeinsamen Haus-/Firmenbereich; Zuhause nicht bestätigt';
            }elseif($geoIsLatest &&$geoKind==='OUT'){
                $qualifier='Handy meldete Geofence-OUT; ohne weitere Nachweise keine bestätigte Abwesenheit';
            }
            $f=($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
                &&is_array($fusion['people'][$key]??null)
                ?$fusion['people'][$key]:[];
            $lastEvent=$f['lastEvent']??null;
            $allowed=[
                'PHONE_GEOFENCE_OUT_OBSERVED'=>'Handy meldete Geofence-OUT (Haus/Firma)',
                'PHONE_GEOFENCE_IN_OBSERVED'=>'Handy meldete Geofence-IN (Haus/Firma)',
                'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC'=>'Handy nach OUT/IN wieder im Heim-WLAN',
                'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP'=>'WLAN-Kontakt nach Messluecke, keine Heimkehr bewiesen'
            ];
            $eventCode=is_array($lastEvent)?($lastEvent['code']??null):null;
            $lastEventAt=is_array($lastEvent)?($lastEvent['at']??null):null;
            $safePhoneEvent=is_string($eventCode)
                &&isset($allowed[$eventCode])
                &&is_int($lastEventAt)
                &&$lastEventAt>0&&$lastEventAt<=$now+5
                &&$lastEventAt>=$now-86400;
            $people[$key]=[
                'name'=>$name,
                'status'=>$status,
                'decision'=>$decision,
                'qualifier'=>$qualifier,
                'at'=>$when,
                'timeMeaning'=>$hint,
                'wlanHome'=>is_int($macAt)
                    &&$macAt>0&&$macAt<=$now+5
                    ?'Heim-WLAN bestätigt '.self::formatTime($macAt)
                        .(is_int($wp['lastMacContactAgeSeconds']??null)
                            &&$wp['lastMacContactAgeSeconds']>=270
                            ?' (nächste WLAN-Prüfung abwarten)':'')
                    :(($wp['validatedExistingVariablePair']??false)===true
                        &&is_int($wp['lastMacContactAt']??null)
                        &&$wp['lastMacContactAt']>0
                        &&$wp['lastMacContactAt']<=$now
                        ?'Heim-WLAN letzter Kontakt '.self::formatTime(
                            $wp['lastMacContactAt']).' (nicht aktuell)'
                        :'Kein frischer Heim-WLAN-Kontakt'),
                'phoneSsid'=>($sp['sourceVerified']??false)===true
                    ?(string)($sp['ssid']??''):'',
                'ssidStatus'=>($sp['status']??null)==='WORK_FIRMEN_WLAN_PHONE'
                    ?'Firmen-WLAN erkannt':(($sp['status']??null)==='HOME_SSID_PHONE_HINT'
                        ?'Heim-SSID gemeldet':'Keine aktuelle eindeutige SSID'),
                // A geo event may be many hours old and the shared
                // 150-m circle covers BOTH home and workplace. Never
                // portray a persisted "Zuhause" flag as current location.
                'geofenceContext'=>($v['geofenceEventFresh']??false)===true
                    ?(match($v['geofenceInsideSharedHomeWorkZone']??null){
                        true=>'Geofence meldete Haus-/Firmenbereich',
                        false=>'Geofence meldete außerhalb des gemeinsamen Bereichs',
                        default=>'Geofence-Ereignis unklar'
                    }).($geoAt!==null
                        ?' um '.self::formatTime($geoAt):'')
                        .(is_int($v['geofenceLastEventAgeSeconds']??null)
                        ?' (vor '.(int)floor($v['geofenceLastEventAgeSeconds']/60).' Min.)'
                        :'')
                    :(($v['geofenceInsideSharedHomeWorkZone']??null)!==null
                        ?'Geofence zuletzt gemeldet, aber veraltet – kein Live-Standort'
                        :'Geofence: keine geprüfte Meldung'),
                'departureAssessment'=>$likely?'LIKELY_PHONE_DEPARTED':'NOT_ESTABLISHED',
                'p03DepartureAnonymous'=>($d['p03AnonymousExitNearby']??false)===true,
                'departureContext'=>$likely
                    ?'Handy vermutlich außerhalb von Haus/Firma'
                        .((($d['p03AnonymousExitNearby']??false)===true)
                            ?' · passender Treppen-OUT (Person unbekannt)':'')
                    :'Kein ausreichend belegter Abfahrtsweg',
                'lastPersonalPhoneEvent'=>$safePhoneEvent
                    ?$allowed[$eventCode].' · '.self::formatTime($lastEventAt)
                    :'Kein neuer bestaetigter Handy-Zustandswechsel',
                'phoneFoundNotHumanCertified'=>true,
                'travelMode'=>'NICHT NACHGEWIESEN',
                'travelRoute'=>'NICHT NACHGEWIESEN',
                'mayTriggerAbsenceAutomation'=>false,
            ];
        }
        $p03Text='Keine aktuelle verifizierte Kamera-Richtung';
        if(($p03['schema']??null)===1
            &&($p03['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY'){
            $recent=$p03['recentDirectionProofs']??[];
            if(is_array($recent))foreach($recent as $proof){
                if(!is_array($proof)
                    ||($proof['source']??null)!=='P03_TWO_MAST_VERIFIED'
                    ||!is_int($proof['at']??null)
                    ||$proof['at']>$now+5
                    ||$proof['at']<$now-1800)continue;
                $direction=match($proof['direction']??null){
                    'HOME_TO_LAGER'=>'Haus → Lagerplatz',
                    'LAGER_TO_HOME'=>'Lagerplatz → Haus',
                    default=>null};
                if($direction!==null){
                    $p03Text=$direction.' um '.self::formatTime($proof['at'])
                        .' · Person nicht identifiziert';
                    break;
                }
            }
        }
        $houseText='Bewegungsmelder: keine aktuelle bestätigte Bewegung';
        if(($house['authority']??null)==='ANONYMOUS_HOME_OBSERVATION_ONLY'
            &&($house['sourcesHealthy']??false)===true
            &&is_int($house['lastHomeMotionAgeSeconds']??null)
            &&$house['lastHomeMotionAgeSeconds']>=0
            &&$house['lastHomeMotionAgeSeconds']<=300)
            $houseText='Hausbewegung erkannt (Person unbekannt)';
        $roomSummary=[];
        if(($rooms['authority']??null)==='LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY'
            &&($rooms['coverageStatus']??null)==='AVAILABLE_ANONYMOUS_EDGES'){
            foreach([
                'officeGuestWcReturn'=>'Büro → Flur → Gäste-WC → Flur → Büro',
                'kitchenToUpperDressing'=>'Küche → OG Flur → Ankleide'
            ] as $key=>$label){
                if(is_int($rooms[$key]['at']??null))
                    $roomSummary[]=$label.' (bis '.self::formatTime($rooms[$key]['at']).')';
            }
        }
        $routeText='Keine vollständig gepaarte P03-Hin-/Rückweg-Folge';
        if(($routes['authority']??null)==='ANONYMOUS_ROUTE_EPISODES_READ_ONLY'
            &&is_array($routes['lastEpisode']??null)){
            $ep=$routes['lastEpisode'];
            $outAt=$ep['outAt']??null;
            $inAt=$ep['inAt']??null;
            if(($ep['kind']??null)==='ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE'
                &&is_int($outAt)&&is_int($inAt)
                &&$outAt>0&&$outAt<$inAt&&$inAt<=$now+5
                &&$inAt>=$now-86400){
                $routeText='Haus → Lager '.self::formatTime($outAt)
                    .' · Lager → Haus '.self::formatTime($inAt)
                    .' (anonyme Reihenfolge, Person nicht festgestellt)';
            }
        }
        return [
            'schema'=>1,
            'authority'=>'CURRENT_STATUS_ONLY_SAFE_HTML_TILE',
            'roomPathsAnonymous'=>$roomSummary,
            'anonymousRouteEpisodeText'=>$routeText,
            'residentPhoneRouteAssociation'=>self::safeJourneyText($journey),
            'people'=>$people,
            'p03Anonymized'=>$p03Text,
            'lcnAnonymized'=>$houseText,
            'dataIsPhoneBasedNotHumanCertificate'=>true,
            'noHistoricalMultiStatusLog'=>true,
            'walkingDrivingAndFilzenerWegRouteUnverified'=>true,
            'mayArmAlarm'=>false,
            'mayTriggerAbsenceActions'=>false
        ];
    }

    /**
     * Daily-use Symcon HTMLBox: precisely two residents and one concise
     * state per phone. All complete explanation remains in TestReport and
     * EvidenceJSON. Does not create IP-Symcon objects.
     *
     * Source outage MUST NOT reuse a saved HOME/WORK last-known zone as
     * confirmed current presence. A phone is never a verified human.
     */
    public static function compactState(array $data,int $now):array
    {
        $trusted=($data['schema']??null)===1
            &&($data['authority']??null)==='CURRENT_STATUS_ONLY_SAFE_HTML_TILE';
        $result=[
            'schema'=>1,'authority'=>'COMPACT_PHONE_TILE_READ_ONLY',
            'people'=>[],'currentPhoneMaxAgeSeconds'=>300,
            'absenceIsHumanCertified'=>false,
            'houseEmptyCertified'=>false,
            'mayArmAlarm'=>false,'mayChangeOtherModules'=>false
        ];
        $away=$trusted&&is_array($data['householdAwayIndicator']??null)
            &&($data['householdAwayIndicator']['schema']??null)===1
            &&($data['householdAwayIndicator']['authority']??null)
                ==='HOUSEHOLD_AWAY_VISUAL_HINT_ONLY'
            &&($data['householdAwayIndicator']['status']??null)==='LIKELY_AWAY'
            &&($data['householdAwayIndicator']['likelyAway']??false)===true
            &&($data['householdAwayIndicator']['geofenceOutside']['johannes']??false)===true
            &&($data['householdAwayIndicator']['geofenceOutside']['carina']??false)===true
            &&($data['householdAwayIndicator']['mayArmAlarm']??true)===false;
        foreach(['johannes'=>'Johannes','carina'=>'Carina'] as $person=>$name){
            $row=$trusted&&is_array($data['people'][$person]??null)
                ?$data['people'][$person]:[];
            $decision=(string)($row['decision']??'UNKNOWN');
            $time=$row['at']??null;
            $fresh=is_int($time)&&$now>0&&$time>0
                &&$time<=$now+5&&$time>=$now-300;
            $status='Unklar';$reason='Kein aktueller eindeutiger Nachweis';
            $style='unknown';
            if($fresh&&$decision==='HOME_PHONE_CONFIRMED'){
                $status='Zuhause';$style='home';
                $reason='Heim-WLAN · Handy bestätigt';
            }elseif($fresh&&$decision==='WORK_PHONE_CONFIRMED'){
                $status='In Firma';$style='work';
                $reason='Firmen-WLAN · Handy bestätigt';
            }elseif($away
                &&in_array($decision,[
                    'UNKNOWN','LAST_KNOWN_UNCONFIRMED'
                ],true)
                &&($row['status']??null)!=='ANWESEND'){
                $status='Abwesend';$style='away';
                $reason='Vermutet · beide Handy-OUT, Haus/Licht/TV ruhig';
            }elseif($decision!=='HOME_PHONE_CONFIRMED'
                &&$decision!=='WORK_PHONE_CONFIRMED'
                &&$decision!=='CONFLICT'
                &&($row['departureAssessment']??null)==='LIKELY_PHONE_DEPARTED'
                &&($row['status']??null)==='ABWESEND (WAHRSCHEINLICH)'
                &&is_int($time)&&$time>0&&$time<=$now+5
                &&$time>=$now-1800){
                $status='Abwesend';$style='away';
                $reason='Vermutet · Handy-Abreisehinweis';
            }elseif($decision==='CONFLICT'){
                $reason='Widersprüchliche WLAN-Signale';
            }elseif($decision==='LAST_KNOWN_UNCONFIRMED'){
                $reason='Letzter Standort veraltet';
            }
            $result['people'][$person]=[
                'name'=>$name,'status'=>$status,'style'=>$style,
                'reason'=>$reason,
                'at'=>$fresh||($style==='away'&&!$away)?$time:null,
                'humanPresenceOrAbsenceCertified'=>false,
                'safeForAlarmActions'=>false
            ];
        }
        return $result;
    }

    /**
     * Replace the earlier expandable engineering dashboard by one compact
     * native HTMLBox in the regular visualization. The legacy detailed
     * renderer remains available for regression/debug but is not published.
     */
    public static function renderCompact(array $data,int $now):string
    {
        $state=self::compactState($data,$now);
        $esc=static fn(string $s):string=>
            htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $palette=[
            'home'=>['#174e32','#d9f5e4'],
            'work'=>['#154b76','#dcebfc'],
            'away'=>['#684216','#fff0d1'],
            'unknown'=>['#49515b','#ecedf0']
        ];
        $parts=[
            '<section style="box-sizing:border-box;width:100%;font-family:inherit;padding:3px 1px">',
            '<div style="font-weight:650;font-size:1.04em;margin:1px 0 8px">Bewohnerstatus</div>'
        ];
        foreach(['johannes','carina'] as $person){
            $row=$state['people'][$person];
            [$ink,$background]=$palette[$row['style']];
            $parts[]='<div style="display:flex;align-items:center;gap:8px;'
                .'justify-content:space-between;min-width:0;padding:9px 7px;'
                .'border-top:1px solid #88888833">'
                .'<span style="font-weight:600;white-space:nowrap">'
                .$esc($row['name']).'</span>'
                .'<span style="min-width:0;text-align:right">'
                .'<span style="display:inline-block;border-radius:7px;padding:4px 9px;'
                .'background:'.$background.';color:'.$ink.';font-weight:650">'
                .$esc($row['status']).'</span>'
                .'<span style="display:block;font-size:.72em;opacity:.78;'
                .'margin-top:3px;line-height:1.3">'
                .$esc($row['reason']).'</span></span></div>';
        }
        $parts[]='<div style="font-size:.71em;opacity:.65;'
            .'margin:7px 4px 0">Handy-Hinweise · keine automatische Alarmfreigabe</div>'
            .'</section>';
        return implode('',$parts);
    }

    public static function render(array $data):string
    {
        $esc=static fn(string $t)=>htmlspecialchars($t,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $parts=['<section style="font:inherit;box-sizing:border-box;max-width:100%;padding:4px 2px">',
            '<div style="font-size:1.05em;font-weight:650;margin-bottom:8px">Anwesenheit</div>'];
        foreach(['johannes','carina'] as $key){
            $row=is_array($data['people'][$key]??null)
                ?$data['people'][$key]:null;
            if($row===null)continue;
            $st=(string)($row['status']??'NICHT BESTÄTIGT');
            $color=$st==='ANWESEND'?'#1f8b55'
                :(str_starts_with($st,'ABWESEND')?'#aa6424':'#777b82');
            $name=$esc((string)$row['name']);
            $status=$esc($st);
            $qualifier=$esc((string)($row['qualifier']??''));
            $event=$esc((string)($row['timeMeaning']??'Erfassung'));
            $time=is_int($row['at']??null)
                ?self::formatTime($row['at']):'Keine Erfassung';
            $ssid=(string)($row['phoneSsid']??'');
            $wifiInfo=(string)($row['wlanHome']??'Kein WLAN-Hinweis');
            $ssidInfo=(string)($row['ssidStatus']??'Keine SSID-Meldung');
            if($ssid!=='' &&strlen($ssid)<=64)
                $ssidInfo.=' ('.$ssid.')';
            // <details>/<summary> work natively without scripting and
            // provide an expandable row inside one Symcon HTMLBox tile.
            $parts[]='<details style="border:1px solid #85858555;border-radius:10px;'
                .'margin:7px 0;padding:7px 10px">'
                .'<summary style="cursor:pointer;list-style:disclosure-closed;'
                .'font-weight:600;line-height:1.65">'
                .$name.': <span style="color:'.$color.'">'.$status.'</span>'
                .'</summary>'
                .'<div style="font-size:.91em;line-height:1.65;padding:8px 2px 2px">'
                .'<div>'.$qualifier.'</div>'
                .'<div>'.$esc((string)($row['departureContext']??'')).'</div>'
                .'<div>'.$esc($wifiInfo).'</div>'
                .'<div>'.$esc($ssidInfo).'</div>'
                .'<div>'.$esc((string)($row['geofenceContext']??'' )).'</div>'
                .'<div>'.$event.': '.$esc($time).'</div>'
                .'<div>Letztes Handy-Ereignis: '.$esc((string)(
                    $row['lastPersonalPhoneEvent']??'Kein Ereignis')).'</div>'
                .'<div>Zu Fuß / Auto / Filzener Weg: '
                .'nicht personenbezogen nachgewiesen</div>'
                .'</div></details>';
        }
        $parts[]='<div style="font-size:.83em;opacity:.8;margin-top:8px">';
        foreach(array_slice($data['roomPathsAnonymous']??[],0,2) as $roomPath)
            $parts[]='<div>LCN Raumfolge (Person unbekannt): '.$esc((string)$roomPath).'</div>';
        $parts[]='<div>Weg-/Handy-Abgleich: '.$esc((string)(
            $data['residentPhoneRouteAssociation']??'Nicht verfügbar')).'</div>';
        $parts[]='<div>Wegfolge: '.$esc((string)(
            $data['anonymousRouteEpisodeText']??'Keine Daten')).'</div>';
        $parts[]='<div>P03: '.$esc((string)($data['p03Anonymized']??'Keine Daten')).'</div>'
            .'<div>LCN: '.$esc((string)($data['lcnAnonymized']??'Keine Daten')).'</div>'
            .'<div style="margin-top:5px">Anwesenheit/Abwesenheit nach '
            .'Smartphone-Hinweisen; kein sicherer Personennachweis. '
            .'Bei fehlender Bestätigung keine automatische Abwesenheit. '
            .'Keine Freigabe für Alarm oder Schaltungen.</div>'
            .'</div></section>';
        return implode('',$parts);
    }

    private static function safeJourneyText(array $journey):string
    {
        if(($journey['authority']??null)!=='READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS'
            ||($journey['schema']??null)!==1)
            return 'Quellennachweis fehlt';
        $status=$journey['status']??null;
        if($status==='ONE_PHONE_TIMELINE_COMPATIBLE_NOT_IDENTIFIED'){
            $person=$journey['candidatePerson']??null;
            $label=$person==='johannes'?'Johannes':($person==='carina'?'Carina':null);
            return $label!==null
                ?$label.'-Handy zeitlich passend; Kamera-Person NICHT identifiziert'
                :'Handy zeitlich passend; Identitaet unbekannt';
        }
        return match($status){
            'MULTIPLE_PHONE_TIMELINES_CANNOT_ATTRIBUTE_ROUTE'=>
                'Mehrere Handys passen zeitlich; keine Zuordnung',
            'ANONYMOUS_ROUTE_NO_PERSONAL_PHONE_SEQUENCE'=>
                'Anonymer Weg ohne belegte persoenliche Handyfolge',
            'NO_RECENT_VERIFIED_ROUTE_EPISODE'=>
                'Keine aktuelle vollstaendige Wegfolge',
            default=>'Keine verlaessliche Weg-/Handy-Zuordnung'
        };
    }

    private static function formatTime(int $at):string
    {
        return (new DateTimeImmutable('@'.$at))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))
            ->format('d.m. H:i:s');
    }
}
