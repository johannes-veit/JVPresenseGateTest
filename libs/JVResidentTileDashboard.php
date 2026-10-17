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
        array $house,int $now,array $depart=[]):array
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
        return [
            'schema'=>1,
            'authority'=>'CURRENT_STATUS_ONLY_SAFE_HTML_TILE',
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
                .'<div>Zu Fuß / Auto / Filzener Weg: '
                .'nicht personenbezogen nachgewiesen</div>'
                .'</div></details>';
        }
        $parts[]='<div style="font-size:.83em;opacity:.8;margin-top:8px">'
            .'<div>P03: '.$esc((string)($data['p03Anonymized']??'Keine Daten')).'</div>'
            .'<div>LCN: '.$esc((string)($data['lcnAnonymized']??'Keine Daten')).'</div>'
            .'<div style="margin-top:5px">Anwesenheit/Abwesenheit nach '
            .'Smartphone-Hinweisen; kein sicherer Personennachweis. '
            .'Bei fehlender Bestätigung keine automatische Abwesenheit. '
            .'Keine Freigabe für Alarm oder Schaltungen.</div>'
            .'</div></section>';
        return implode('',$parts);
    }

    private static function formatTime(int $at):string
    {
        return (new DateTimeImmutable('@'.$at))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))
            ->format('d.m. H:i:s');
    }
}
