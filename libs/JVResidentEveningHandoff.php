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
        int $now,bool $enabled,array $departure=[],array $rooms=[],array $arrival=[],array $contactHistory=[],array $fusion=[],array $routeEpisodes=[],array $journey=[],array $readiness=[],array $integrity=[]
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
        $add('Schema 1 | SHADOW v0.8.71 Build 152 | Symcon #32969');
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

            $a=($arrival['authority']??null)==='PHONE_ARRIVAL_CORRELATION_READ_ONLY'
                ?($arrival['people'][$person]??[]):[];
            $add('--- '.$label.' ---');
            $add('KACHEL: '.$summary.' | Entscheidung: '.$decision);
            $add('WLAN-KONTAKTHISTORIE: '.self::code($contactHistory['people'][$person]['status']??'NICHT_ERFASST'));
            $add('LETZTE WLAN-WIEDERERKENNUNG: '.self::date(
                $contactHistory['people'][$person]['lastRecoveredHomeMacAt']??null)
                .' | vorherige MAC-Kontaktluecke: '.self::age(
                $contactHistory['people'][$person]['lastObservedRecoveryGapSeconds']??null)
                .' | KEIN Ankunftsnachweis');
            $add('Geofence OUT/IN + neuer MAC in diesem Zyklus: '.self::yes(
                ($contactHistory['people'][$person]['phoneReturnAfterObservedOutIn']??false)===true));
            $fp=($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
                &&is_array($fusion['people'][$person]??null)
                ?$fusion['people'][$person]:[];
            $evt=is_array($fp['lastEvent']??null)?$fp['lastEvent']:[];
            $add('EREIGNISKERN: '.self::code($fp['state']??'NO_STATE')
                .' | Letztes Handy-Ereignis: '.self::code($evt['code']??'KEINES')
                .' | Zeit: '.self::date($evt['at']??null));
            $add('HANDY-VERLAUF/24h: '.self::number(
                $fp['eventHistoryCount']??null)
                .' qualifizierte Ereignisse (maximal 24 pro Handy, keine Identitaet)');
            if(is_array($fp['eventHistory']??null)){
                $validTypes=[
                    'PHONE_GEOFENCE_OUT_OBSERVED','PHONE_GEOFENCE_IN_OBSERVED',
                    'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC',
                    'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP'
                ];
                foreach(array_slice($fp['eventHistory'],0,3) as $hEvent){
                    if(!is_array($hEvent)
                        ||!in_array($hEvent['code']??null,$validTypes,true)
                        ||!is_int($hEvent['at']??null)
                        ||$hEvent['at']<=0||$hEvent['at']>$now+5)continue;
                    $add('HANDY-EVENT/24h: '.self::code($hEvent['code'])
                        .' | '.self::date($hEvent['at'])
                        .' | nur Telefon-Indiz');
                }
            }
            $add('Handy-Ereignis ist KEIN Nachweis der Person oder einer Autofahrt.');
            $add('ANKUNFT: '.self::code($a['assessment']??'NOT_ESTABLISHED')
                .' | Grund: '.self::code($a['reason']??'NO_DATA')
                );
            $add('ANKUNFT-QUELLEN: letzte Haus-WLAN-MAC: '.self::date($a['homeMacAt']??null)
                .' | P03 IN (anonym): '.self::date($a['p03AnonymousEntryAt']??null)
                .' | Geofence IN: '.self::date($a['geoInAt']??null)
                .' | AUTO: NICHT NACHGEWIESEN');
            // Human-readable meanings are authored by our own tile
            // evaluator. Allow only these known fixed labels, not any text
            // supplied by phone, WLAN, camera or camera diagnostics.
            $knownTileMeanings=[
                'Letztes bestätigtes Geofence-OUT (Handy)',
                'Letztes Geofence-IN (Haus/Firma, nicht Hauseingang)',
                'Letztes Geofence-OUT (Handy)',
                'Letzte bestätigte WLAN-Erfassung',
                'Letzter gültiger WLAN-Hinweis'
            ];
            $tileMeaning=in_array(($t['timeMeaning']??null),
                $knownTileMeanings,true)?$t['timeMeaning']:'NICHT VERIFIZIERT';
            $add('KACHEL letzte relevante Erfassung: '
                .$tileMeaning.' | '.self::date($t['at']??null));
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
            $trip=($departure['authority']??null)
                ==='PHONE_DEPARTURE_CORRELATION_READ_ONLY'
                ?($departure['people'][$person]??[]):[];
            $add('Abreise-Korrelation (Handy): '
                .self::code($trip['assessment']??'NICHT GEPRUEFT')
                .' | Grund: '.self::code($trip['reason']??'UNKNOWN')
                .' | Zeit: '.self::date($trip['eventAt']??null));
            $add('Treppen-OUT nur anonym passend: '
                .self::yes(($trip['p03AnonymousExitNearby']??false)===true)
                .' | Auto/Fussweg: NICHT NACHGEWIESEN');
            $add('Kamera-Zuordnung: NICHT PERSONENBEZOGEN');
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
        $fp03=($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
            &&is_array($fusion['p03']??null)?$fusion['p03']:[];
        $lastAnon=is_array($fp03['lastAnonymousProof']??null)
            ?$fp03['lastAnonymousProof']:[];
        $add('EREIGNISKERN P03: letzter anonymer Uebergang '
            .self::code($lastAnon['direction']??'KEINER')
            .' | '.self::date($lastAnon['at']??null)
            .' | neue seit letztem SHADOW-Durchlauf: '
            .self::number(is_array($fp03['newAnonymousProofs']??null)
                ?count($fp03['newAnonymousProofs']):null));
        $add('P03 PROTOKOLL-KONTINUITAET: '.self::code(
                $fp03['logContinuity']??'NICHT_VERIFIZIERT')
            .' | Unterbrechung erkannt '.self::yes(
                ($fp03['logInterrupted']??false)===true)
            .' | offene Korridorwege bei Unterbrechung gesperrt');
        $add('P03 BEWEIS-UEBERGABE: Journal vor diesem Durchlauf '
            .self::number($fp03['unackedRouteProofCount']??null)
            .' | Paket '.self::number(
                is_array($fp03['routeDeliveryProofs']??null)
                    ?count($fp03['routeDeliveryProofs']):null)
            .' | Ueberlauf '.self::yes(
                ($fp03['routeJournalOverflow']??false)===true)
            .' | abgelaufen '.self::yes(
                ($fp03['routeJournalExpired']??false)===true));
        $re=($routeEpisodes['authority']??null)==='ANONYMOUS_ROUTE_EPISODES_READ_ONLY'
            ?$routeEpisodes:[];
        $ep=is_array($re['lastEpisode']??null)?$re['lastEpisode']:[];
        $add('WEGFOLGE: '.self::code($ep['kind']??'KEINE_ABGESCHLOSSENE_FOLGE')
            .' | Haus->Lager '.self::date($ep['outAt']??null)
            .' | Lager->Haus '.self::date($ep['inAt']??null));
        $add('WEGFOLGE: Dauer '.self::age($ep['durationSeconds']??null)
            .' | offene Hinwege '.self::number($re['openOutgoingPassages']??null)
            .' | Mehrpersonen-/Identitaetsnachweis NEIN');
        $add('WEGFOLGE KONTEXT: Hausbewegung vorher '
            .self::yes(($ep['anonymousIndoorMotionBeforeOut']??false)===true)
            .' | Hausbewegung danach '
            .self::yes(($ep['anonymousIndoorMotionAfterIn']??false)===true)
            .' | Strassen-Human nahebei '
            .self::yes(($ep['rawStreetHumanNearby']??false)===true));
        $add('FAHRZEUGKONTEXT: '.self::code(
                $re['vehicleSignalCoverage']??'NICHT_VERIFIZIERT')
            .' | Fahrzeugvariable aktiv nahebei '
            .self::yes(($ep['vehicleVariableActiveNearby']??false)===true)
            .' | Auto/Fussweg weiterhin NICHT NACHGEWIESEN');
        $journeyOK=($journey['schema']??null)===1
            &&($journey['authority']??null)
                ==='READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS';
        $candidate=$journeyOK
            ?($journey['candidatePerson']??null):null;
        $candidateLabel=in_array($candidate,['johannes','carina'],true)
            ?strtoupper($candidate):'KEINER';
        $add('WEG-HANDY-KORRELATION: '.self::code(
                $journeyOK?($journey['status']??'NO_STATUS'):'NOT_VERIFIED')
            .' | zeitlich passendes Handy '.self::code($candidateLabel)
            .' | Kameraperson NICHT IDENTIFIZIERT');
        $add('FAHRZEUG-NACHWEIS: '.self::code(
                $journeyOK?($journey['vehicleEvidence']??'NO_DATA'):'NOT_VERIFIED')
            .' | Auto, Fahrtrichtung und Bewohner NICHT BEWIESEN');
        $ready=($readiness['schema']??null)===1
            &&($readiness['authority']??null)==='READ_ONLY_PRESENCE_EVIDENCE_READINESS';
        $add('QUELLENSTATUS: P03 '.self::code($ready
                ?($readiness['p03Mode']??'UNKNOWN'):'NOT_VERIFIED')
            .' | Mastkamera '.self::code($ready
                ?($readiness['mastStreams']??'UNKNOWN'):'NOT_VERIFIED')
            .' | 15 GUS '.self::code($ready
                ?($readiness['roomMotionCoverage']??'UNKNOWN'):'NOT_VERIFIED'));
        $add('NAECHSTE QUELLENLUECKEN: '.self::code($ready
                ?implode(', ',array_slice($readiness['nextEvidencePriority']??[],0,3))
                :'NOT_VERIFIED'));
        $auditOK=($integrity['schema']??null)===1
            &&($integrity['authority']??null)==='SHADOW_INTEGRITY_AUDIT_READ_ONLY';
        $auditIssues=$auditOK &&is_array($integrity['issues']??null)
            ?array_slice($integrity['issues'],0,5):[];
        $add('SELBSTPRUEFUNG: '.self::code($auditOK
                ?($integrity['status']??'UNBEKANNT'):'NICHT_VERIFIZIERT')
            .' | kontrollierte Invarianten: '.self::number($integrity['verifiedChecks']??null)
            .' | P03-Protokoll: '.self::code($auditOK
                ?($integrity['p03LogContinuity']??'UNBEKANNT'):'UNBEKANNT'));
        if($auditIssues!==[])$add('SELBSTPRUEFUNG HINWEISE: '.self::code(
            implode(', ',$auditIssues)));
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
        $add('--- RAUMWEGE AUS LCN-GUS (ANONYM) ---');
        $roomsValid=($rooms['schema']??null)===1
            &&($rooms['authority']??null)==='LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY'
            &&($rooms['coverageStatus']??null)==='AVAILABLE_ANONYMOUS_EDGES';
        $add('Geprüfte Bewegungsimpulse/90min: '
            .($roomsValid?self::number($rooms['roomEdgeCount90m']??null):'?')
            .' | Personenzuordnung: UNBEKANNT');
        foreach([
            'officeGuestWcReturn'=>'Büro > Flur > Gäste WC > Flur > Büro',
            'kitchenToUpperDressing'=>'Küche > OG Flur > Ankleide'
        ] as $kind=>$path){
            $matched=$roomsValid?($rooms[$kind]??null):null;
            $add($path.': '.(is_array($matched)
                ?('REIHENFOLGE ERKANNT '.self::date($matched['startAt']??null)
                    .' bis '.self::date($matched['at']??null)
                    .' | Person nicht identifiziert')
                :'KEINE VOLLSTÄNDIGE FOLGE'));
        }
        if($roomsValid){
            $knownRooms=[
                'EG Büro','EG Flur','EG Gäste-WC','EG Wohnen/Küche',
                'EG Wohnen Kamin','EG Bad','EG Schlafen','OG Flur',
                'OG Ankleide','OG Ankleide Decke','OG Bad',
                'OG Bad Dusche','OG Bad Waschtisch',
                'OG Schlafen 1','OG Schlafen 2'
            ];
            foreach(array_slice($rooms['recentRoomEdges']??[],0,15) as $e){
                if(!is_array($e)||!in_array($e['room']??null,$knownRooms,true))continue;
                $add('RAUMIMPULS: '.self::date($e['at']??null).' | '.$e['room']);
            }
        }
        $add('Kochen: Strom >3000 W derzeit UNGEPRÜFT – keine zugeordnete Leistungsvariable.');
        $add('OG-Ankleide: Bewegung ist kein Beweis für Umziehen.');
        $add('Auto/Terrasse: keine verifizierte Fahrzeug- und Terrassentür-Ereigniskette.');
        $add('Tor/Fahrzeug-Grenzsignale: '
            .self::number($boundary['configuredBoundarySignals']??null)
            .' zugeordnet | Schiebetor-Hinweis: '
            .self::code($gate['positionHint']??'UNKNOWN'));
        $add('Filzener Weg / Lagerplatz: Person+Route+zu Fuss/Auto derzeit NICHT BEWEISBAR.');
        if(!$p03Verified||$mode==='UNKNOWN_NOT_VERIFIED')
            $needs[]='P03_QUELLE_ODER_MODUS_NICHT_VERIFIZIERT';
        elseif($mode==='TEST_AND_PRODUCTION_OFF')
            $needs[]='P03_BEWUSST_INAKTIV_KEINE_NEUEN_ZONENUEBERGAENGE';
        if(!$houseVerified||($house['sourcesHealthy']??false)!==true)
            $needs[]='LCN_HAUSQUELLEN_NICHT_VOLLSTAENDIG';
        $needs[]='ROUTE_AUTO_FUSSWEG_NICHT_PERSONENBEZOGEN_BEWIESEN';
        $needs[]='MENSCHLICHE_ABWESENHEIT_UND_HAUS_LEER_NICHT_ZERTIFIZIERT';

        $add('');
        $add('--- HEUTE ABEND: PRUEFERGEBNIS + OFFENE PUNKTE ---');
        $add('Technischer Diagnosebericht aus vorhandenen Daten: '
            .self::yes($validTile&&$validVisu&&$wlanVerified
                &&$timingVerified&&$p03Verified&&$localVerified)
            .' | Sichere menschliche An-/Abwesenheit: NICHT ZERTIFIZIERT');
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
