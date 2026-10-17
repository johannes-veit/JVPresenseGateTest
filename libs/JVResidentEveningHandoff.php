<?php
declare(strict_types=1);

/**
 * One pasteable, human-readable evidence handoff, always regenerated from
 * already checked, local SHADOW reports. No passwords, tokens, IP addresses,
 * coordinates, BSSID/MAC, raw device logs or surveillance images.
 *
 * This deliberately NEVER upgrades a phone hint or anonymous IVS/GUS to
 * human identification, physical absence or permission to arm devices.
 */
final class JVResidentEveningHandoff
{
    public const SCHEMA=1;
    private const MAX_TEXT_BYTES=13000;

    public static function build(
        array $tile,array $visu,array $wlan,array $work,
        array $jGeo,array $cGeo,array $p03,array $timing,
        array $mast,array $house,array $activity,array $local,
        array $boundary,array $gate,array $warnings,
        int $now,bool $enabled
    ):string {
        $validTile=($tile['schema']??null)===1
            &&($tile['authority']??null)==='CURRENT_STATUS_ONLY_SAFE_HTML_TILE';
        $validVisu=($visu['schema']??null)===1
            &&($visu['authority']??null)==='RESIDENT_PHONE_VISU_HINTS_READ_ONLY';
        $wlanVerified=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        $ssidVerified=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
            &&($work['enabled']??false)===true;
        $p03Verified=($p03['schema']??null)===1
            &&($p03['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY';
        $timingVerified=($timing['schema']??null)===1
            &&($timing['authority']??null)==='READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY';
        $mastVerified=($mast['schema']??null)===1
            &&($mast['authority']??null)==='P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY';
        $houseVerified=($house['schema']??null)===1
            &&($house['authority']??null)==='ANONYMOUS_HOME_OBSERVATION_ONLY';
        $activityVerified=($activity['schema']??null)===1
            &&($activity['authority']??null)==='MOTION_EDGE_HISTORY_ONLY';
        $localVerified=($local['schema']??null)===1
            &&($local['authority']??null)==='LOCAL_SENSOR_HINTS_ONLY';
        $geoJA=($jGeo['schema']??null)===1
            &&($jGeo['authority']??null)==='EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY';
        $geoCA=($cGeo['schema']??null)===1
            &&($cGeo['authority']??null)==='EXISTING_CARINA_GEOFENCY_READ_ONLY';

        $lines=[];
        $add=static function(string $value)use(&$lines):void{
            $lines[]=mb_substr(str_replace(["\r","\n"],' ',$value),0,230,'UTF-8');
        };
        $add('=== TESTBERICHT AN CHATGPT – ANWESENHEIT ===');
        $add('Schema 1 | SHADOW v0.8.58 Build 139 | Symcon #32969');
        $add('Messzeit: '.self::date($now).' (Europe/Berlin)');
        $add('Modul: '.($enabled?'SHADOW AKTIV (nur lesen)':'SHADOW DEAKTIVIERT'));
        $add('Einmaliger IST-Zustand, kein Verlauf mehrerer Statuswechsel.');
        $add('WICHTIG: Smartphones != gesicherte Person; fehlende Signale != Abwesenheit.');
        $add('');

        $needs=[];
        foreach(['johannes'=>'Johannes','carina'=>'Carina'] as $person=>$label){
            $t=$validTile&&is_array($tile['people'][$person]??null)
                ?$tile['people'][$person]:[];
            $v=$validVisu&&is_array($visu['people'][$person]??null)
                ?$visu['people'][$person]:[];
            $w=$wlanVerified&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $s=$ssidVerified&&is_array($work['people'][$person]??null)
                ?$work['people'][$person]:[];
            $g=$person==='johannes'?$jGeo:$cGeo;
            $gValid=($person==='johannes'?$geoJA:$geoCA)
                &&($g['enabled']??false)===true
                &&($g['validatedSource']??false)===true;
            $decision=self::code($v['decision']??'UNKNOWN');
            $summary=self::code($t['status']??'NICHT BESTÄTIGT');
            $wState=self::code($w['state']??'SOURCE_NOT_VERIFIED');
            $wBool=is_bool($w['wlanBoolean']??null)
                ?($w['wlanBoolean']?'AN':'AUS'):'NICHT PRUEFBAR';
            $lastAt=$w['lastMacContactAt']??null;
            $lastAge=$w['lastMacContactAgeSeconds']??null;
            $ssidState=self::code($s['status']??'NOT_CONFIGURED_OR_UNVERIFIED');
            $ssidAge=$s['ageSeconds']??null;
            $sourceVerified=($s['sourceVerified']??false)===true;
            $sourceFresh=$sourceVerified
                &&is_int($ssidAge)&&$ssidAge>=0&&$ssidAge<=300;
            $geoState=$gValid?self::code($g['state']??'UNKNOWN')
                :'NO_VALIDATED_LIVE_SOURCE';
            $geoAge=$gValid?($g['lastEventAgeSeconds']??null):null;

            $add('--- '.$label.' ---');
            $add('KACHEL: '.$summary.' | Entscheidung: '.$decision);
            $add('Quelle/Grund: '.self::code($v['deviceEvidenceReason']??'UNKNOWN'));
            $add('Handy-Erfassung: '.self::date($v['deviceEvidenceAt']??null)
                .' | zuletzt bekannt: '.self::date($v['lastKnownDeviceAt']??null));
            $add('Heim-WLAN: ID '.($person==='johannes'?'#19980/#26497':'#38430/#10760')
                .' | geprüfte Quelle: '.self::yes(($w['validatedExistingVariablePair']??false)===true)
                .' | Boolean: '.$wBool.' | Status: '.$wState);
            $add('Letzter ECHTER MAC-Kontakt: '.self::date($lastAt)
                .' | Alter: '.self::age($lastAge)
                .' | Kachelfrische-Grenze: 300 s (WLAN-Puffer: 660 s)');
            $add('Persönliche SSID: Quelle '.self::yes($sourceVerified)
                .' | '.($sourceFresh?'AKTUELL <=300 s':'NICHT AKTUELL')
                .' | Status: '.$ssidState.' | Alter: '.self::age($ssidAge));
            $add('Geofence: Quelle '.self::yes($gValid)
                .' | '. $geoState
                .' | letztes Ereignis: '.self::date($g['lastEventAt']??null)
                .' | Alter: '.self::age($geoAge)
                .' | Kreis umfasst HAUS UND FIRMA');
            $add('Widerspruch Heim-/Firmen-WLAN: '
                .self::yes(($v['wifiConflict']??false)===true)
                .' | altes Gegensignal gesperrt: '
                .self::yes(($v['rejectedOlderOppositeZoneSource']??false)===true));
            $add('Kamera-Zuordnung: NICHT PERSONENBEZOGEN | Auto/Fussweg: NICHT NACHGEWIESEN');
            if(($w['validatedExistingVariablePair']??false)!==true)
                $needs[]=$label.':WLAN_QUELLE_NICHT_VERIFIZIERT';
            if(!$sourceVerified)
                $needs[]=$label.':AKTUELLE_SSID_MELDUNG_FEHLT';
            elseif(!$sourceFresh)$needs[]=$label.':SSID_HEARTBEAT_AELTER_300S';
            if(!$gValid)$needs[]=$label.':GEOFENCE_QUELLE_NICHT_VERIFIZIERT';
            if($decision==='CONFLICT')$needs[]=$label.':WLAN_WIDERSPRUCH';
            $add('');
        }
        $add('--- KAMERAS / LCN / ROUTE ---');
        $mode=$timingVerified
            ?self::code($timing['p03OperatingMode']??'UNKNOWN')
            :'NOT_VERIFIED';
        $add('P03 #53879: Modus '.$mode
            .' | Modusmeldung frisch: '
            .self::yes($timingVerified
                &&($timing['p03ModeTrustedFromFreshDiagnosis']??false)===true));
        $proofs=$p03Verified&&is_array($p03['directionProofs24h']??null)
            ?$p03['directionProofs24h']:[];
        $add('VERIFIZIERTE LIVE-P03-ZWEIMAST-UEBERGAENGE/24h: '
            .'Haus->Lager '.self::number($proofs['HOME_TO_LAGER']??null)
            .' | Lager->Haus '.self::number($proofs['LAGER_TO_HOME']??null)
            .' | Letzter '.self::date($p03Verified
                ?($p03['lastDirectionAt']??null):null));
        $raw=$timingVerified&&is_array($timing['rawStreetIVS24h']??null)
            ?$timing['rawStreetIVS24h']:[];
        $add('ROHE Strassen-IVS/24h (kein Hauswechsel!): IN '
            .self::number($raw['IN']??null).' / OUT '
            .self::number($raw['OUT']??null).' | letzte: '
            .self::date($timing['lastStreetIVS']['at']??null));
        $add('P03 Zwei-Mast-Observer: beide LIVE '
            .self::yes($mastVerified &&($mast['bothMastsLive']??false)===true)
            .' | Hauptbeweis blockiert '
            .self::yes($mastVerified&&($mast['mainP03ProofBlocked']??false)===true));
        if($mastVerified)foreach(['JV_LEFT','WORK_LEFT'] as $camera){
            $m=$mast['mastObservers'][$camera]??[];
            $add('Kamera '.$camera.': '.self::code($m['streamState']??'UNKNOWN')
                .' | RX-Alter '.self::age($m['lastCameraRxAgeSeconds']??null));
        }
        $add('LCN GUS (erforderlich 15): aktuell verifiziert '
            .($houseVerified?self::number($house['observedHomeGUS']??null):'?')
            .' | Sensoren/Gesamtzustand OK: '
            .self::yes($houseVerified&&($house['sourcesHealthy']??false)===true));
        $add('Letzte Hausbewegung: '
            .self::date($activityVerified?($activity['lastMotionAt']??null):null)
            .' | Alter: '.self::age($houseVerified
                ?($house['lastHomeMotionAgeSeconds']??null):null)
            .' | Zuordnung zu Johannes/Carina: UNBEKANNT');
        $add('Tor/Fahrzeug-Grenzsignale: '
            .self::number($boundary['configuredBoundarySignals']??null)
            .' zugeordnet | Schiebetor-Hinweis: '
            .self::code($gate['positionHint']??'UNKNOWN'));
        $add('Filzener Weg / Lagerplatz: Person+Route+zu Fuss/Auto derzeit NICHT BEWEISBAR.');
        if(!$p03Verified||$mode==='TEST_AND_PRODUCTION_OFF'
            ||$mode==='UNKNOWN_NOT_VERIFIED')
            $needs[]='P03_KEIN_VERIFIZIERTER_LIVE_BETRIEB';
        if(!$houseVerified||($house['sourcesHealthy']??false)!==true)
            $needs[]='LCN_HAUSQUELLEN_NICHT_VOLLSTAENDIG';
        $needs[]='ROUTE_AUTO_FUSSWEG_NICHT_PERSONENBEZOGEN_BEWIESEN';
        $needs[]='MENSCHLICHE_ABWESENHEIT_UND_HAUS_LEER_NICHT_ZERTIFIZIERT';

        $add('');
        $add('--- HEUTE ABEND: PRUEFERGEBNIS + OFFENE PUNKTE ---');
        $add('Diagnose vollständig aus lesbaren Quellen: '
            .self::yes($validTile&&$validVisu&&$wlanVerified
                &&$timingVerified&&$p03Verified&&$localVerified));
        $add('Erkannter IST-Zustand: '
            .self::code($tile['people']['johannes']['status']??'UNBEKANNT')
            .' / '.self::code($tile['people']['carina']['status']??'UNBEKANNT'));
        foreach(array_slice(array_values(array_unique($needs)),0,16) as $i=>$reason)
            $add('OFFEN '.($i+1).': '.$reason);
        $safeWarnings=[];
        foreach(array_slice($warnings,0,6) as $item)
            if(is_string($item))$safeWarnings[]=self::code($item);
        $add('Weitere SHADOW-Warnungen: '
            .($safeWarnings?implode('; ',$safeWarnings):'KEINE / NUR BEGRENZTE PROTOKOLLPRUEFUNG'));
        $add('ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN');
        $add('An ChatGPT senden: DIESE EINE VARIABLE komplett kopieren oder mehrere Screenshots.');
        $add('Keine weiteren Passwoerter, Roh-GPS-Daten oder Kamerakonfigurationen mitsenden.');
        $add('=== ENDE TESTBERICHT ===');

        $text=implode("\n",$lines);
        if(strlen($text)>self::MAX_TEXT_BYTES)
            return mb_strcut($text,0,self::MAX_TEXT_BYTES-130,'UTF-8')
                ."\n[BERICHT GEKUERZT – NUR SAFE DIAGNOSTIK]\n=== ENDE TESTBERICHT ===";
        return $text;
    }

    private static function date(mixed $at):string
    {
        if(!is_int($at)||$at<=0||$at>4102444800)return 'KEIN ZEITSTEMPEL';
        return (new DateTimeImmutable('@'.$at))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))
            ->format('d.m.Y H:i:s');
    }

    private static function age(mixed $n):string
    {
        return is_int($n)&&$n>=0&&$n<=31536000
            ?$n.' s ('.(int)floor($n/60).' min)':'UNBEKANNT';
    }
    private static function number(mixed $n):string
    {
        return is_int($n)&&$n>=0&&$n<=1000000?(string)$n:'?';
    }
    private static function yes(bool $yes):string
    {
        return $yes?'JA':'NEIN';
    }
    private static function code(mixed $code):string
    {
        if(!is_string($code))return 'UNKNOWN';
        $code=mb_substr($code,0,110,'UTF-8');
        return preg_match('/^[\pL\pN _.\/:-]*$/u',$code)===1
            ?$code:'NICHT_VERIFIZIERT';
    }
}
