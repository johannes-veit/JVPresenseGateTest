<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowReportBudget.php';

function budgetStress(bool $good,string $reason):void {
    if(!$good)throw new RuntimeException('SHADOW_REPORT_BUDGET_STRESS '.$reason);
}
$header='=== TESTBERICHT AN CHATGPT – ANWESENHEIT ===';
$rows=[
    $header,'Schema 1 | SHADOW v0.8.108 Build 189',
    '--- Johannes ---','KACHEL: NICHT BESTÄTIGT',
    '--- Carina ---','KACHEL: NICHT BESTÄTIGT',
    '--- KAMERAS / LCN / ROUTE ---'
];
for($i=0;$i<500;$i++)
    $rows[]='RAUMIMPULS: '.str_repeat('x',90).' '.$i;
$rows[]='SELBSTPRUEFUNG: CONSISTENT';
$rows[]='ENDPRUEFUNG HAUS: NICHT BEWIESEN';
$rows[]='ENDPRUEFUNG AUTO/FUSSWEG: UNBEKANNT';
for($i=0;$i<150;$i++)
    $rows[]='ABWESENHEIT-AKTIONEN / ALARMFREIGABE: '
        .str_repeat('JA STÖRZEICHEN ',800);
$rows[]='An ChatGPT senden: '.str_repeat('Ü',100000);
$rows[]='Keine weiteren Passwoerter, '.str_repeat('Ö',50000);
$rows[]='=== ENDE TESTBERICHT ===';

foreach([700,701,900,1200,4000,13000] as $max){
    $text=JVShadowReportBudget::fit($rows,$max);
    budgetStress(strlen($text)<=$max,'must never exceed '.$max.' bytes');
    budgetStress(str_ends_with($text,
        "ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN\n"
        ."=== ENDE TESTBERICHT ==="),
        'always end with bounded fail-closed safety and end marker');
    budgetStress(preg_match('//u',$text)===1,
        'UTF-8 remains valid at limit '.$max);
    budgetStress(str_contains($text,'[DETAILS GEKUERZT'),
        'large input visibly flagged as truncated');
    budgetStress(substr_count($text,
        'ABWESENHEIT-AKTIONEN / ALARMFREIGABE:')===1,
        'duplicate or oversized safety tails are never copied');
    budgetStress(!str_contains($text,'JA STÖRZEICHEN'),
        'untrusted/duplicated permission is not accidentally emitted');
}
foreach([0,1,16,64,699] as $max){
    $text=JVShadowReportBudget::fit($rows,$max);
    budgetStress(strlen($text)<=$max,
        'even abnormal tiny budgets remain strictly bounded '.$max);
    budgetStress(!str_contains($text,'ALARMFREIGABE: JA'),
        'short fallback cannot authorize any physical actuation');
}
$ordinary=[
    $header,'Schema 1 | SHADOW v0.8.108 Build 189',
    'An ChatGPT senden: diesen Status',
    'ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN',
    '=== ENDE TESTBERICHT ==='
];
budgetStress(JVShadowReportBudget::fit($ordinary,13000)
    ===implode("\n",$ordinary),
    'a report below limit is preserved byte-for-byte');
echo "SHADOW report stress 150 duplicate alarm footers, 300k UTF-8 bytes and 11 budgets PASS\n";
