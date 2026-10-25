<?php
declare(strict_types=1);
require_once __DIR__.'/JVShadowReportBudget.php';
require_once __DIR__.'/JVP03HistoricalWindowDigest.php';

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
        int $now,bool $enabled,array $departure=[],array $rooms=[],array $arrival=[],array $contactHistory=[],array $fusion=[],array $routeEpisodes=[],array $journey=[],array $readiness=[],array $integrity=[],array $commissioning=[],array $upgrade=[],array $safety=[],array $operational=[],array $sourceCommissioning=[],array $away=[]
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
        $add('Schema 1 | SHADOW v0.8.123 Build 204 | Symcon #32969');
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
            $iosEvent=$person==='carina'
                &&is_array($tile['carinaLastWifiEvent']??null)
                &&($tile['carinaLastWifiEvent']['authority']??null)
                    ==='CARINA_IOS_LAST_WIFI_EVENT_DISPLAY_ONLY'
                ?$tile['carinaLastWifiEvent']:[];
            $iosHold=in_array(($iosEvent['status']??null),[
                'LAST_WIFI_EVENT_HOME','LAST_WIFI_EVENT_WORK'
            ],true);
            if($iosHold){
                $summary=$iosEvent['status']==='LAST_WIFI_EVENT_HOME'
                    ?'ZUHAUSE (letzte iPhone-SSID, kein Live-Beweis)'
                    :'FIRMA (letzte iPhone-SSID, kein Live-Beweis)';
            }
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
            $add('KACHEL QUELLENHERKUNFT: letzter Ort an Quelle gebunden '
                .self::yes(($v['lastKnownBoundToSource']??false)===true)
                .' | Quellen-Neubaseline '
                .self::yes(($v['displaySourceRebaseline']??false)===true)
                .' | Quellenwechsel bedeutet NICHT Ankunft/Abreise');
            $add('WLAN-KONTAKTHISTORIE: '.self::code($contactHistory['people'][$person]['status']??'NICHT_ERFASST'));
            $add('LETZTE WLAN-WIEDERERKENNUNG: '.self::date(
                $contactHistory['people'][$person]['lastRecoveredHomeMacAt']??null)
                .' | vorherige MAC-Kontaktluecke: '.self::age(
                $contactHistory['people'][$person]['lastObservedRecoveryGapSeconds']??null)
                .' | KEIN Ankunftsnachweis');
            $add('Geofence OUT/IN + neuer MAC in diesem Zyklus: '.self::yes(
                ($contactHistory['people'][$person]['phoneReturnAfterObservedOutIn']??false)===true));
            $add('BEOBACHTUNGSVERLAUF: fortlaufend '
                .self::yes(($contactHistory['people'][$person]['observationContinuityVerified']??false)===true)
                .' | seit letzter Auswertung unterbrochen '
                .self::yes(($contactHistory['people'][$person]['observationInterrupted']??true)===true)
                .' | nach Pause nur neue Handy-Ereignisse; niemals menschliche Abwesenheit');
            $add('KONTAKTHISTORIE QUELLENKETTE: WLAN '
                .self::yes(($contactHistory['people'][$person]['macSourceContinuous']??false)===true)
                .' | Geofence '
                .self::yes(($contactHistory['people'][$person]['geoSourceContinuous']??false)===true)
                .' | sichere Neubaseline '
                .self::yes(($contactHistory['people'][$person]['sourceRebaselineRequired']??true)===true)
                .' | kein menschlicher Ankunftsnachweis');
            $fp=($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
                &&is_array($fusion['people'][$person]??null)
                ?$fusion['people'][$person]:[];
            $evt=is_array($fp['lastEvent']??null)?$fp['lastEvent']:[];
            $add('EREIGNISKERN: '.self::code($fp['state']??'NO_STATE')
                .' | Letztes Handy-Ereignis: '.self::code($evt['code']??'KEINES')
                .' | Zeit: '.self::date($evt['at']??null));
            $add('QUELLENBINDUNG: Heim-WLAN fortlaufend '
                .self::yes(($fp['macSourceContinuous']??false)===true)
                .' | Geofence fortlaufend '
                .self::yes(($fp['geoSourceContinuous']??false)===true)
                .' | Objekt-ID-Wechsel/24h '
                .self::yes(($fp['sourceRebindRecent']??false)===true)
                .' | zuletzt '.self::date($fp['lastSourceRebindAt']??null));
            $add('HANDY-VERLAUF/24h: '.self::number(
                $fp['eventHistoryCount']??null)
                .' qualifizierte Ereignisse (maximal 24 pro Handy, keine Identitaet)');
            if(is_array($fp['eventHistory']??null)){
                $validTypes=[
                    'PHONE_GEOFENCE_OUT_OBSERVED','PHONE_GEOFENCE_IN_OBSERVED',
                    'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC',
                    'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP'
                ];
                foreach(array_slice($fp['eventHistory'],0,12) as $hEvent){
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
                .' | '.($sourceFresh?'AKTUELL <=300 s'
                    :($iosHold?'LETZTE IOS-WLAN-MELDUNG (nur bei Wechsel)':'NICHT AKTUELL'))
                .' | Status: '.$ssidState.' | Alter: '.self::age($ssidAge));
            if($person==='carina'){
                $add('CARINA IPHONE-SSID: WLAN-Wechsel statt 4-min-Heartbeat'
                    .' | letzter Event-Stand '.self::code(
                        $iosEvent['status']??'NICHT_VERIFIZIERT')
                    .' | Zeitpunkt '.self::date($iosEvent['eventAt']??null)
                    .' | ALT != LIVE; Geofence/WLAN-Neusignal hat Vorrang');
            }
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
            elseif(!$sourceFresh&&!$iosHold)
                $needs[]=$label.':SSID_HEARTBEAT_AELTER_300S';
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
        // Historical comparison (e.g. an independently observed 10:26
        // departure) requires time-stamped anonymous transitions, not just
        // counts. Show at most ten VERIFIED observations, never identities.
        if($p03Verified&&is_array($p03['recentDirectionProofs']??null)){
            foreach(array_slice($p03['recentDirectionProofs'],0,15) as $proof){
                if(!is_array($proof)
                    ||($proof['source']??null)!=='P03_TWO_MAST_VERIFIED'
                    ||!in_array($proof['direction']??null,
                        ['HOME_TO_LAGER','LAGER_TO_HOME'],true)
                    ||!is_int($proof['at']??null)
                    ||$proof['at']<=0||$proof['at']>$now+5
                    ||$now-$proof['at']>86400)continue;
                $add('P03-UEBERGANG/24h: '
                    .self::code($proof['direction'])
                    .' | '.self::date($proof['at'])
                    .' | anonym, keine Personenzuordnung');
            }
        }
        $p03Digest=JVP03HistoricalWindowDigest::summarize($p03,$now);
        $add('P03-5MIN-STATUS: '.self::code($p03Digest['status'])
            .' | Belege '.self::number($p03Digest['sourceCount'])
            .' | Fenster '.self::number($p03Digest['fiveMinuteWindows'])
            .' | HISTORIE NICHT PERSONENANZAHL');
        foreach($p03Digest['hourlyLines'] as $line)
            $add('P03-5MIN/24h: '.$line);
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
        $add('MEHRPERSONEN-KONFLIKT: letzte unaufloesbare Weggruppe '
            .self::date($re['lastAmbiguousAt']??null)
            .' | Ursache '.self::code($re['lastAmbiguityReason']??'KEINE')
            .' | ungeklaerte Rueckwege '.self::number(
                $re['ambiguousOutstanding']??null)
            .' | keine willkuerliche Zuordnung');
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
        $gateOk=($commissioning['schema']??null)===1
            &&($commissioning['authority']??null)==='PRESENCE_READ_ONLY_COMMISSIONING_GATE';
        $add('MEILENSTEIN QUELLENQUALIFIKATION: '.self::code($gateOk
                ?($commissioning['state']??'UNBEKANNT'):'NICHT_VERIFIZIERT')
            .' | Fahrzeug '.self::code($gateOk
                ?($commissioning['vehicle']??'UNBEKANNT'):'UNBEKANNT'));
        if($gateOk){
            $priorities=is_array($commissioning['actions']??null)
                ?array_slice($commissioning['actions'],0,3):[];
            foreach($priorities as $i=>$priority){
                $add(($i===0?'NAECHSTE QUELLENARBEIT (NUR LESEN): '
                    :'WEITERE QUELLENARBEIT '.($i+1).': ')
                    .self::code($priority));
            }
        }
        $upgradeOK=($upgrade['schema']??null)===1
            &&($upgrade['authority']??null)==='READ_ONLY_SHADOW_UPGRADE_PREFLIGHT';
        $add('UPGRADE-SPEICHERPRUEFUNG: '.self::code(
            $upgradeOK?($upgrade['status']??'UNKNOWN'):'NICHT_VERIFIZIERT')
            .' | vorhandene Speicher '.self::number($upgrade['existingAttributes']??null)
            .' | P03-Warteschlange '.self::number($upgrade['fusionJournalPending']??null)
            .' | keine automatische Loeschung');
        if($upgradeOK &&is_array($upgrade['reviewRequired']??null)
            &&$upgrade['reviewRequired']!==[]){
            // Only fixed local labels, no raw JSON/phone metadata.
            foreach(array_slice($upgrade['reviewRequired'],0,2) as $issue)
                $add('UPGRADE PRUEFPUNKT: '.self::code($issue));
        }
        $safeOK=($safety['schema']??null)===1
            &&($safety['authority']??null)==='READ_ONLY_FINAL_PRESENCE_SAFETY_CASE';
        $add('ENDPRUEFUNG HAUS: '.self::code(
                $safeOK?($safety['homeActivity']??'UNKNOWN'):'NICHT_VERIFIZIERT')
            .' | aktuelle Heim-Handys '.self::number($safety['homePhoneHints']??null)
            .' | Firma-Handys '.self::number($safety['workPhoneHints']??null)
            .' | Haus leer NICHT ZERTIFIZIERT');
        $add('ENDPRUEFUNG AUTO/FUSSWEG: '.self::code(
                $safeOK?($safety['vehicle']??'UNKNOWN'):'NICHT_VERIFIZIERT')
            .' | Fahrer, Fahrtrichtung und Person NICHT BEWIESEN');
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
            $insight=is_array($rooms['activityInsight']??null)
                &&($rooms['activityInsight']['authority']??null)
                    ==='ANONYMOUS_LCN_ROOM_ACTIVITY_CONTEXT_ONLY'
                ?$rooms['activityInsight']:[];
            if($insight!==[]){
                $add('RAUMKONTEXT: '.self::code($insight['coverage']??'UNKNOWN')
                    .' | qualifizierte Impulse 5min '
                    .self::number($insight['events5m']??null)
                    .' / 15min '.self::number($insight['events15m']??null)
                    .' | verschiedene Raeume 15min '
                    .self::number($insight['distinctRooms15m']??null)
                    .' | KEINE PERSONENZAEHLUNG');
                $hot=[];
                foreach(array_slice($insight['hotRooms15m']??[],0,4) as $room){
                    if(!is_array($room))continue;
                    $hot[]=self::code($room['id']??'UNKNOWN')
                        .'='.self::number($room['events']??null);
                }
                $add('RAUMSCHWERPUNKTE/15min: '
                    .($hot!==[]?implode(' | ',$hot):'KEINE AKTUELLE BEWEGUNG')
                    .' | unbekannt, welche Person');
                $add('RAUMZUSAMMENHANG: Buero/Kueche <=30s '
                    .self::yes(($insight['officeKitchenCloseTiming']??false)===true)
                    .' | EG-Flur vor OG-Flur <=180s '
                    .self::yes(($insight['egOgHallSequencePossible']??false)===true)
                    .' | keine sichere gemeinsame Person oder Personenzahl');
                $add('RAUMQUELLENFILTER: Kamin ungeprueft ausgeschlossen '
                    .self::number($insight['weakKaminEdgesExcluded']??null)
                    .' | GUS-Ruecktrigger unter 20s zusammengefasst '
                    .self::number($insight['retriggerEdgesSuppressed']??null));
                foreach(array_slice($insight['recentMotionBursts']??[],0,3) as $burst){
                    if(!is_array($burst))continue;
                    $add('RAUMAKTIVITAETSCLUSTER: '
                        .self::date($burst['startAt']??null).' bis '
                        .self::date($burst['endAt']??null)
                        .' | Raumsensoren '
                        .self::number($burst['distinctRooms']??null)
                        .' | keine individuelle Route');
                }
            }
            $atlas=$rooms['routeAtlas']??[];
            if(is_array($atlas)
                &&($atlas['authority']??null)
                    ==='ANONYMOUS_LCN_TEMPORAL_ROOM_SEQUENCE_ONLY'){
                $add('RAUMWEGE-PRUEFUNG: Vollstaendiger 80er-Abgleich '
                    .self::yes(($atlas['sourceIntegrityTailValidated']??false)===true)
                    .' | Muster-Vorlagen '
                    .self::number($atlas['patternTemplates']??null)
                    .' | Gleichzeitige Sekunden getrennt '
                    .self::yes(($atlas['sameSecondStagesIsolated']??false)===true)
                    .' | Personen-Identitaet UNBEKANNT');
                $add('RAUMWEGE-ATLAS: '.self::code($atlas['status']??'UNKNOWN')
                    .' | erkannte Muster '
                    .self::number($atlas['patternsFound']??null)
                    .' | nur Zeitfolge, Person UNBEKANNT');
                foreach(array_slice($atlas['latestPatterns']??[],0,3) as $pattern){
                    if(!is_array($pattern))continue;
                    $add('RAUMWEG-MUSTER: '.self::code($pattern['pattern']??'UNKNOWN')
                        .' | '.self::date($pattern['startAt']??null)
                        .' bis '.self::date($pattern['endAt']??null)
                        .' | mehrere Personen moeglich');
                }
            }
            $joint=$rooms['jointDepartureReadiness']??[];
            if(is_array($joint)
                &&($joint['authority']??null)
                    ==='JOINT_DEPARTURE_READINESS_NO_PERSON_COUNT_OR_ACTION'){
                $add('DOPPELABREISE: '.self::code($joint['state']??'UNVERIFIED_SOURCES')
                    .' | frische WLAN-Geraete '
                    .self::number($joint['recentVerifiedHandsets']??null)
                    .' | P03-Beweise/24h '
                    .self::number($joint['verifiedAnonymousCorridorProofs24h']??null)
                    .' | ZWEI PERSONEN NICHT BEWIESEN');
                $add('DOPPELABREISE RAUMKONFLIKT: Raumwege mit ueberlagerten Sensoren '
                    .self::number($atlas['patternsWithInterleavedRooms']??null)
                    .' | Anonymitaet/Mehrpersonen-Offenheit: JA');
                $prio=$joint['sourceGapPriorities']??[];
                if(is_array($prio))foreach(array_slice($prio,0,4) as $j=>$reason){
                    $add('DOPPELABREISE-QUELLENLUECKE '.($j+1).': '
                        .self::code($reason));
                }
            }
            $pair=$rooms['sharedDepartureTimeline']??[];
            if(is_array($pair)
                &&($pair['authority']??null)
                    ==='READ_ONLY_SHARED_HANDSET_EXIT_SEQUENCE_NO_IDENTITY'){
                $add('HANDY-DOPPELABREISE/24h: '
                    .self::code($pair['status']??'SOURCES_UNVERIFIED')
                    .' | getrennte Geofence-OUT: '
                    .self::yes(($pair['pairedPhoneAreaOutObserved']??false)===true)
                    .' | Person und Haus-Ausgang: NICHT BEWIESEN');
                $add('HANDY-ZYKLEN/24h: Johannes '
                    .self::number($pair['phoneAreaCycles24h']['johannes']??null)
                    .' / Carina '
                    .self::number($pair['phoneAreaCycles24h']['carina']??null)
                    .' | eins-zu-eins-Paarungen '
                    .self::number($pair['pairedEventsWithin24h']??null)
                    .' | Mehrfach-Zuordnung moeglich '
                    .self::yes(($pair['competingPossiblePairings']??false)===true));
                $add('HANDY-ZONENUEBERSCHNEIDUNG: nahe aber zeitlich getrennte OUT-Zyklen '
                    .self::number($pair['nearButDisjointPhonePeriods']??null)
                    .' | neuestes gemeinsames Handy-Zonenintervall '
                    .self::age($pair['latestPhoneAreaOverlapSeconds']??null)
                    .' | KEINE menschliche Anwesenheit abgeleitet');
                $add('P03-EINMALBELEGUNG: letzte '
                    .self::number($pair['p03RecentPhonePairsChecked']??null)
                    .' Handy-Paare | verschiedene Kamera-Beweise '
                    .self::number($pair['p03UniquePairMatchesRecent']??null)
                    .' | nur frueheres Paar passend '
                    .self::yes(($pair['p03MatchPreviousNotLatest']??false)===true)
                    .' | P03-Mehrdeutigkeit letztes Paar '
                    .self::yes(($pair['p03LatestPairAmbiguous']??false)===true)
                    .' | moegliche Kamerabelege '
                    .self::number($pair['p03LatestPairCandidateCount']??null)
                    .' | P03 Hin/Rueck gleiche Sekunde verworfen '
                    .self::number($pair['p03OppositeDirectionSecondsIgnored']??null)
                    .' | Kameraperson UNBEKANNT');
                $add('HANDY-OUT-DOPPELMELDUNGEN VERWORFEN: Johannes '
                    .self::number($pair['repeatedPhoneOutIgnored']['johannes']??null)
                    .' / Carina '
                    .self::number($pair['repeatedPhoneOutIgnored']['carina']??null)
                    .' | dieselbe Handy-Abreise zaehlt maximal einmal');
                $add('HANDY-DOPPELABREISE-KONTEXT: Rueckkehr-IN nach OUT '
                    .self::yes(($pair['pairedOutSupersededByAreaIn']??false)===true)
                    .' | Johannes-IN '
                    .self::yes(($pair['returnedAfterPairedOut']['johannes']??false)===true)
                    .' / Carina-IN '
                    .self::yes(($pair['returnedAfterPairedOut']['carina']??false)===true)
                    .' | Kein Mensch identifiziert');
                if(($pair['pairedPhoneAreaOutObserved']??false)===true){
                    $add('HANDY-GEMEINSAMES-OUT: '
                        .self::date($pair['pairedOutStartAt']??null)
                        .' bis '.self::date($pair['pairedOutEndAt']??null)
                        .' | Abstand '.self::age($pair['pairedPhoneGapSeconds']??null)
                        .' | Geofence umfasst HAUS und FIRMA');
                    $add('HANDY-P03-ZEITABGLEICH: verifizierte anonyme Treppenfolge nahebei '
                        .self::yes(($pair['verifiedP03NearPair']??false)===true)
                        .' | '.self::date($pair['verifiedP03NearPairAt']??null)
                        .' | KEINE Personenzuordnung');
                }
                $gaps=$pair['sourceGaps']??[];
                if(is_array($gaps))
                    foreach(array_slice($gaps,0,2) as $gap){
                        if(is_string($gap))
                            $add('HANDY-GEMEINSAMES-OUT-QUELLENLUECKE: '
                                .self::code($gap));
                    }
            }
            $overlap=$rooms['phoneRoomOverlapAudit']??[];
            if(is_array($overlap)
                &&($overlap['authority']??null)
                    ==='READ_ONLY_PHONE_AREA_VS_ANONYMOUS_GUS_ACTIVITY'){
                $add('HANDY-RAUM-GEGENCHECK: '
                    .self::code($overlap['status']??'SOURCES_UNVERIFIED')
                    .' | GUS-Impulse waehrend letztem doppelten Handy-OUT '
                    .self::number($overlap['latestPairHomeMotionCount']??null)
                    .' | unterschiedliche Hausmelder '
                    .self::number($overlap['latestPairHomeMotionRooms']??null)
                    .' | Person UNBEKANNT, ohne Impuls HAUS NICHT LEER BEWIESEN');
                $add('HANDY-RAUM-24h: Paare mit anonymer Hausbewegung '
                    .self::number($overlap['pairsWithAnonymousHomeMotion']??null)
                    .' / gepruefte Handy-Paare '
                    .self::number($overlap['scopedPhoneAreaPairs']??null)
                    .' | GUS-Beobachtung 24h lueckenlos '
                    .self::yes(($overlap['continuous24hGusMonitoring']??false)===true));
            }
            $cross=$rooms['crossSourceIntegrityGate']??[];
            if(is_array($cross)
                &&($cross['authority']??null)
                    ==='SHADOW_CROSS_SOURCE_INVARIANT_AUDIT_NO_ACTION'){
                $add('QUELLEN-INTEGRITAET HANDY/P03/GUS: '
                    .self::code($cross['status']??'SOURCES_UNVERIFIED')
                    .' | gepruefte Bedingungen '
                    .self::number($cross['testedInvariants']??null)
                    .' | Widersprueche '
                    .self::number(count(is_array($cross['violations']??null)
                        ?$cross['violations']:[]))
                    .' | ALARM/FREMDSTEUERUNG GESPERRT');
                $add('BEWEISKETTE DIREKTVERGLEICH: P03-Quellprotokoll gegen alle Paare '
                    .self::yes(($cross['p03ProofAssignmentReconciled']??false)===true)
                    .' | echte GUS-Impulse gegen Summen '
                    .self::yes(($cross['rawGusSummaryReconciled']??false)===true)
                    .' | aktive Nachpruefung '
                    .self::yes(($cross['sourceReconciliationActive']??false)===true)
                    .' | Mensch NICHT IDENTIFIZIERT');
                $add('QUELLSPERRE BEI FEHLENDER QUELLE: verbotene Freigabe entdeckt '
                    .self::yes(($cross['sourceContractViolationDuringMissingData']??false)===true)
                    .' | auch bei Smartphone-/P03-Ausfall kein Alarm-/Personenbeweis');
                $add('QUELLEN-INTEGRITAET DETAIL: Handy-Zeitintervalle '
                    .self::yes(($cross['phoneIntervalsStrictlyPositive']??false)===true)
                    .' | P03 letzter Zeitbeleg '
                    .self::yes(($cross['latestP03LinkConsistent']??false)===true)
                    .' | LCN-GUS anonym konsistent '
                    .self::yes(($cross['anonymousRoomOverlayConsistent']??false)===true)
                    .' | keine Personenidentitaet');
                $next=$cross['priorityChecks']??[];
                if(is_array($next)){
                    foreach(array_slice($next,0,2) as $i=>$item){
                        if(is_string($item))
                            $add('QUELLEN-INTEGRITAET NAECHSTER SCHRITT '
                                .($i+1).': '.self::code($item));
                    }
                }
            }
            $day=$rooms['dayDigest']??[];
            if(is_array($day)
                &&($day['authority']??null)
                    ==='LCN_24H_ANONYMOUS_ACTIVITY_DIGEST_ONLY'){
                $add('RAUMHISTORIE/24h: '.self::code($day['status']??'UNKNOWN')
                    .' | qualifizierte Hausimpulse '
                    .self::number($day['homeRoomEvents24h']??null)
                    .' | verschiedene Raeume '
                    .self::number($day['distinctRooms24h']??null)
                    .' | KEIN Haus-leer-Beweis');
                $add('RAUMHISTORIE/6h: '
                    .self::number($day['motionEvents6h']??null)
                    .' Impulse | letzte Stunde '
                    .self::number($day['motionEvents1h']??null)
                    .' | fortlaufende 24h '
                    .self::yes(($day['complete24hCoverage']??false)===true)
                    .' | Messungsluecken verhindern die Vollstaendigkeit');
            }
            $power=$rooms['powerContext']??[];
            if(is_array($power)
                &&($power['authority']??null)
                    ==='EXISTING_WATT_SOURCE_AND_ANONYMOUS_KITCHEN_MOTION_ONLY'){
                $add('STROM-KUECHEN-KONTEXT: '.self::code(
                    $power['state']??'UNKNOWN')
                    .' | aktuell '
                    .self::number($power['watts']??null)
                    .' W | >3000W '
                    .self::yes(($power['highLoad3000W']??false)===true)
                    .' | Kochen und Person UNBEWIESEN');
            }
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
        $add('Kochen: Auch Strom >3000 W und Kuechen-GUS zusammen beweisen weder Kochvorgang noch Person.');
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
        if(($operational['authority']??null)==='SOURCE_COMMISSIONING_READ_ONLY'){
            $a=$operational['ssid']??[];
            foreach(['johannes'=>'Johannes','carina'=>'Carina'] as $person=>$label){
                $q=$a[$person]??[];
                $add('BETRIEB SSID '.$label.': '
                    .self::code($q['reason']??'NOT_CHECKED')
                    .' | Alter '.self::number($q['lastUpdateAgeSeconds']??null)
                    .' s | aktuell (max 300s) '
                    .self::yes(($q['currentWithinFiveMinutes']??false)===true));
            }
            $q=$operational['p03']??[];
            $add('BETRIEB P03: '.self::code($q['reason']??'NOT_CHECKED')
                .' | Modus '.self::code($q['operatingMode']??'UNKNOWN')
                .' | beide Observer LIVE '
                .self::yes(($q['bothExistingAuxObserversLive']??false)===true)
                .' | neue Route noch NICHT bestaetigt');
            $g=$q['productionPreflight']??[];
            if(($g['verifiedFromFreshDiagnostic']??false)===true){
                $add('BETRIEB P03: Freigabe Audit '
                    .self::yes(($g['auditPassed']??false)===true)
                    .' | Simulation '.self::yes(($g['simulationPassed']??false)===true)
                    .' | OUT '.self::number($g['homeToLagerCount']??null).'/2'
                    .' | IN '.self::number($g['lagerToHomeCount']??null).'/2'
                    .' | Mast-Sockets '.self::yes(($g['socketAndMastSourcesReady']??false)===true)
                    .' | Vorfreigabe '.self::yes(($g['eligibleForManualProductionEnable']??false)===true));
            }else{
                $add('BETRIEB P03: Vorfreigabe-Detail noch nicht aus frischer P03-Hauptdiagnose auslesbar; nicht freigeben.');
            }
            if(($q['productionEnableFunctionHasVerifiedGuards']??false)===true)
                $add('P03 FREIGABE: nur nach Audit + Simulation + 2 OUT/2 IN + zwei Maststreams; SHADOW aktiviert P03 nicht.');
        }
        if(($sourceCommissioning['authority']??null)
            ==='READ_ONLY_SOURCE_DELIVERY_AND_P03_NEXT_ACTION'){
            $add('BETRIEB SSID-BRIDGE: '
                .self::code($sourceCommissioning['ssidHook']??'UNBEKANNT')
                .' | BEIDE ALT '.self::yes(
                    ($sourceCommissioning['bothPhoneReportsStale']??false)===true)
                .' | gleicher Zeitstempel '.self::yes(
                    ($sourceCommissioning['sharedSourceUpdateSecond']??false)===true));
            $patterns=$sourceCommissioning['sourceValuePatterns']??[];
            $add('BETRIEB SSID QUELLWERTE: Johannes '
                .self::code($patterns['johannes']??'UNKNOWN')
                .' | Carina '.self::code($patterns['carina']??'UNKNOWN')
                .' | Gleichzeitigkeit '.self::code(
                    $sourceCommissioning['sharedStaleSourcePattern']??'NO_CONCLUSION')
                .' (kein POST-Beweis)');
            $epoch=$sourceCommissioning['ssidSourceUpdateEpoch']??[];
            if(is_array($epoch)&&$epoch!==[]){
                $j=$epoch['johannes']??[];
                $c=$epoch['carina']??[];
                $add('BETRIEB SSID UPDATE-VERLAUF: Johannes '
                    .self::code($j['state']??'NOT_CHECKED')
                    .' ('.self::number($j['observedUpdatesThisEpoch']??0).')'
                    .' | Carina '.self::code($c['state']??'NOT_CHECKED')
                    .' ('.self::number($c['observedUpdatesThisEpoch']??0).')'
                    .' | Quellvariable geaendert != authentifizierter Handy-POST');
            }
            $add('BETRIEB SSID NAECHSTER SCHRITT: '.self::code(
                $sourceCommissioning['ssidAction']??'UNKNOWN'));
            $steps=is_array($sourceCommissioning['p03Actions']??null)
                ?$sourceCommissioning['p03Actions']:[];
            foreach(array_slice($steps,0,5) as $i=>$step)
                $add('BETRIEB P03 OFFEN '.($i+1).': '.self::code($step));
        }
        if(($away['authority']??null)==='HOUSEHOLD_AWAY_VISUAL_HINT_ONLY'){
            $add('BEWOHNER-ABWESENHEITSINDIZ: '
                .self::code($away['status']??'UNKNOWN')
                .' | beide Handy-OUT '
                .self::yes(
                    ($away['geofenceOutside']['johannes']??false)===true
                    &&($away['geofenceOutside']['carina']??false)===true)
                .' | 15 GUS ruhig + Licht/TV: '
                .self::yes(($away['eligible']??false)===true)
                .' | geprüft: Licht '
                .self::number($away['checkedLights']??0)
                .', TV '.self::number($away['checkedTVs']??0)
                .' | keine menschliche Abwesenheitszertifizierung');
            $reasons=is_array($away['reasons']??null)
                ?$away['reasons']:[];
            $add('BEWOHNER-ABWESENHEIT FEHLT: '
                .($reasons?implode(' | ',array_slice($reasons,0,3))
                    :'KEIN GRUND VERFUEGBAR'));
        }
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

        return JVShadowReportBudget::fit($lines,self::MAX_TEXT_BYTES);
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
