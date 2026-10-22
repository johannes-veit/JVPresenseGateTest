<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';

function compactAssert(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('COMPACT_RESIDENT_TILE '.$label);
}
$now=1800000000;
$fixture=[
    'schema'=>1,'authority'=>'CURRENT_STATUS_ONLY_SAFE_HTML_TILE',
    'people'=>[
        'johannes'=>[
            'name'=>'Johannes','status'=>'ANWESEND',
            'decision'=>'HOME_PHONE_CONFIRMED','at'=>$now-20,
            'departureAssessment'=>'NOT_ESTABLISHED'
        ],
        'carina'=>[
            'name'=>'Carina','status'=>'ABWESEND',
            'decision'=>'WORK_PHONE_CONFIRMED','at'=>$now-40,
            'departureAssessment'=>'NOT_ESTABLISHED'
        ]
    ]
];
$state=JVResidentTileDashboard::compactState($fixture,$now);
compactAssert($state['people']['johannes']['status']==='Zuhause',
    'current HOME phone');
compactAssert($state['people']['carina']['status']==='In Firma',
    'current WORK phone must NOT be called absent');
$html=JVResidentTileDashboard::renderCompact($fixture,$now);
compactAssert(substr_count($html,'Johannes')===1
    &&substr_count($html,'Carina')===1
    &&str_contains($html,'Zuhause')
    &&str_contains($html,'In Firma')
    &&!str_contains($html,'<details')
    &&!str_contains($html,'Wegfolge:')
    &&!str_contains($html,'P03:')
    &&strlen($html)<3000,'2 compact rows, no diagnostics');
compactAssert(!$state['mayArmAlarm']&&!$state['mayChangeOtherModules']
    &&!$state['absenceIsHumanCertified']
    &&!$state['houseEmptyCertified'],
    'no switching or human absence authority');

$fixture['people']['johannes']['at']=$now-301;
$stale=JVResidentTileDashboard::compactState($fixture,$now);
compactAssert($stale['people']['johannes']['status']==='Unklar'
    &&$stale['people']['johannes']['at']===null,
    'stale 301-second HOME cannot be retained as live');
$fixture['people']['johannes']['at']=$now-300;
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['johannes']['status']==='Zuhause',
    'exact 300-second freshness boundary');
$fixture['people']['johannes']['at']=$now+6;
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['johannes']['status']==='Unklar',
    'future-dated HOME fails closed');

$fixture['people']['johannes']['at']=$now-500;
$fixture['people']['johannes']['decision']='LAST_KNOWN_UNCONFIRMED';
$fixture['people']['johannes']['status']='NICHT BESTÄTIGT';
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['johannes']['status']==='Unklar',
    'stored last zone is NOT current');
$fixture['people']['johannes']['decision']='CONFLICT';
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['johannes']['status']==='Unklar',
    'opposing WLAN source evidence is not absence');

$fixture['people']['johannes']['decision']='UNKNOWN';
$fixture['people']['johannes']['status']='ABWESEND (WAHRSCHEINLICH)';
$fixture['people']['johannes']['departureAssessment']='LIKELY_PHONE_DEPARTED';
$fixture['people']['johannes']['at']=$now-220;
$away=JVResidentTileDashboard::compactState($fixture,$now);
compactAssert($away['people']['johannes']['status']==='Abwesend'
    &&str_contains($away['people']['johannes']['reason'],'Vermutet')
    &&$away['people']['johannes']['humanPresenceOrAbsenceCertified']===false,
    'qualified departure labeled as probable phone only');
$fixture['people']['johannes']['at']=$now-1801;
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['johannes']['status']==='Unklar',
    'old departure cannot be shown indefinitely');
$fixture['people']['johannes']['at']=$now-15;
$fixture['people']['johannes']['departureAssessment']='NOT_ESTABLISHED';
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['johannes']['status']==='Unklar',
    'mere lack of signal is never away');
$fixture['people']['johannes']['departureAssessment']='LIKELY_PHONE_DEPARTED';
$fixture['people']['johannes']['decision']='HOME_PHONE_CONFIRMED';
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['johannes']['status']==='Zuhause',
    'positive HOME wins over previous departure hint');

$fixture['people']['johannes']['decision']='UNKNOWN';
$fixture['people']['johannes']['name']='<script>alert(1)</script>';
$html=JVResidentTileDashboard::renderCompact($fixture,$now);
compactAssert(!str_contains($html,'<script>')
    &&str_contains($html,'Johannes')
    &&!str_contains($html,'&lt;script&gt;'),
    'fixed resident whitelist ignores injected dynamic name');
$fixture['authority']='UNTRUSTED';
compactAssert(JVResidentTileDashboard::compactState(
    $fixture,$now)['people']['carina']['status']==='Unklar',
    'untrusted input authority always unknown');
echo "Compact two-person Symcon HTMLBox and 4 safe states PASS\n";
