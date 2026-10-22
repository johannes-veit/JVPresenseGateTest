<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowReportBudget.php';
function budgetAssert(bool $good,string $why):void{
    if(!$good)throw new RuntimeException('BUDGET '.$why);
}
$lines=[
    '=== TESTBERICHT AN CHATGPT – ANWESENHEIT ===',
    'Schema 1 | SHADOW v0.8.75 Build 156',
    '--- Johannes ---',
    'KACHEL: ANWESEND | Handy nicht Mensch',
    '--- Carina ---',
    'KACHEL: NICHT BESTÄTIGT',
    '--- KAMERAS / LCN / ROUTE ---'
];
for($i=0;$i<300;$i++)$lines[]='RAUMIMPULS: 18:30:00 | EG Flur | '.str_repeat('X',100);
$lines[]='P03-UEBERGANG/24h: HOME_TO_LAGER | 20.10.2026 11:00:00 | anonym';
$lines[]='P03-5MIN-STATUS: RETAINED_ANONYMOUS_HISTORY_24H | HISTORIE NICHT PERSONENANZAHL';
$lines[]='P03-5MIN/24h: 2026-10-20 11 +02:00 | 11:00 O1/I0 11:05 O0/I1';
$lines[]='HANDY-EVENT/24h: PHONE_GEOFENCE_OUT_OBSERVED | 20.10.2026 11:01:00';
$lines[]='UPGRADE-SPEICHERPRUEFUNG: EXISTING_STATE_VALIDATED | keine automatische Loeschung';
$lines[]='MEILENSTEIN QUELLENQUALIFIKATION: CORE_READ_ONLY_VALIDATED';
$lines[]='ENDPRUEFUNG HAUS: QUIET_NO_ABSENCE_PROOF | Haus leer NICHT ZERTIFIZIERT';
$lines[]='ENDPRUEFUNG AUTO/FUSSWEG: NO_DIRECTIONAL_VEHICLE_SOURCE | Fahrer NICHT BEWIESEN';
$lines[]='SELBSTPRUEFUNG: CONSISTENT';
$lines[]='BETRIEB SSID Johannes: PERSONAL_SSID_HEARTBEAT_TOO_OLD | Alter 2161 s';
$lines[]='BETRIEB SSID Carina: PERSONAL_SSID_HEARTBEAT_TOO_OLD | Alter 2161 s';
$lines[]='BETRIEB SSID-BRIDGE: HOOK_ASSIGNED_VALID_SCRIPT | BEIDE ALT JA';
$lines[]='BETRIEB SSID UPDATE-VERLAUF: Johannes SOURCE_VALUE_UNCHANGED_HEARTBEAT_OVERDUE (0) | Carina SOURCE_VALUE_UNCHANGED_HEARTBEAT_OVERDUE (0)';
$lines[]='BETRIEB SSID NAECHSTER SCHRITT: CHECK_BOTH_PHONE_AUTOMATIONS_AND_ACCEPTED_POST_RESPONSE';
$lines[]='BETRIEB P03 OFFEN 1: P03_AUDIT_MAPPING_NOT_VERIFIED_CHECK_READ_ONLY_AUDIT';
$lines[]='BETRIEB P03 OFFEN 2: P03_HOME_TO_LAGER_NEEDS_1';
$lines[]='BETRIEB P03 OFFEN 3: P03_LAGER_TO_HOME_NEEDS_2';
$lines[]='BETRIEB P03: P03_PRODUCTION_OFF_EXPLICIT | TEST_AND_PRODUCTION_OFF';
$lines[]='BETRIEB P03: Freigabe Audit NEIN | Simulation NEIN | OUT 0/2 | IN 0/2';
$lines[]='P03 FREIGABE: nur nach Audit + Simulation + 2 OUT/2 IN';
$lines[]='OFFEN 5: HAUS_LEER_UNBEWIESEN';
$lines[]='ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN';
$lines[]='An ChatGPT senden: DIESE EINE VARIABLE';
$lines[]='Keine weiteren Passwoerter, Roh-GPS-Daten oder Kamerakonfigurationen mitsenden.';
$lines[]='=== ENDE TESTBERICHT ===';
$compressed=JVShadowReportBudget::fit($lines,4000);
budgetAssert(strlen($compressed)<=4000
    &&str_starts_with($compressed,'=== TESTBERICHT')
    &&str_ends_with($compressed,'=== ENDE TESTBERICHT ==='),
    'large report respects hard limit and retains copyable start/end');
foreach([
    '--- Johannes ---','--- Carina ---',
    'P03-UEBERGANG/24h:', 'P03-5MIN-STATUS:',
    'P03-5MIN/24h: 2026-10-20 11', 'HANDY-EVENT/24h:',
    'BETRIEB SSID Johannes:','BETRIEB SSID Carina:',
    'BETRIEB P03:', 'P03 FREIGABE:',
    'BETRIEB SSID-BRIDGE:', 'BETRIEB SSID UPDATE-VERLAUF:',
    'BETRIEB SSID NAECHSTER SCHRITT:',
    'BETRIEB P03 OFFEN 1:',
    'UPGRADE-SPEICHERPRUEFUNG:',
    'ENDPRUEFUNG HAUS:',
    'ENDPRUEFUNG AUTO/FUSSWEG:',
    'MEILENSTEIN QUELLENQUALIFIKATION:',
    'ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN',
    '[DETAILS GEKUERZT'
] as $required)
    budgetAssert(str_contains($compressed,$required),
        'required late safety line survived: '.$required);
budgetAssert(!preg_match('/\\xEF$/',$compressed),
    'no malformed UTF-8 fragments after whole-line compaction');
$short=JVShadowReportBudget::fit(array_slice($lines,0,6),4000);
budgetAssert($short===implode("\n",array_slice($lines,0,6)),
    'short report unchanged verbatim');
echo "SHADOW bounded report safety footer PASS\n";
