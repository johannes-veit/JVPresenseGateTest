<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentRoomPaths.php';
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';
function routeTest(bool $v,string $reason):void{
    if(!$v)throw new RuntimeException('ROOM_ROUTES: '.$reason);
}
$now=(new DateTimeImmutable('2026-10-17 16:45:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$roomIds=[
 'eg_buero'=>'Bewegungsmelder EG Büro',
 'eg_flur'=>'Bewegungsmelder EG Flur',
 'eg_gaeste_wc'=>'Bewegungsmelder EG Gäste WC',
 'eg_wohnen_kueche'=>'Bewegungsmelder EG Wohnen / Küche',
 'og_flur'=>'Bewegungsmelder OG Flur',
 'og_ankleide'=>'Bewegungsmelder OG Ankleide'
];
$source=[];
foreach($roomIds as $id=>$name)
    $source[$id]=['name'=>$name,'zone'=>'HOME','lastMotionAt'=>null];
$steps=[
 ['eg_buero',900],['eg_flur',845],['eg_gaeste_wc',795],
 ['eg_flur',710],['eg_buero',645],
 ['eg_wohnen_kueche',580],['og_flur',460],['og_ankleide',350]
];
$entries=[];
foreach($steps as [$id,$seconds])
    $entries[]=['id'=>$id,'at'=>$now-$seconds,'status'=>'SINGLE_UNCERTAIN'];
$state=['schema'=>1,'startedAt'=>$now-2000,'entries'=>$entries];
$ledger=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
 'perSource'=>$source,'recentMotionEdges'=>[]];
$out=JVResidentRoomPaths::evaluate($state,$ledger,$now);
routeTest($out['roomEdgeCount90m']===8
    &&count($out['recentRoomEdges'])===8
    &&is_array($out['officeGuestWcReturn'])
    &&is_array($out['kitchenToUpperDressing']),
    'recognize office-hall-guestWC-hall-office and kitchen-upper hall-dressing from exact known 15 LCN source keys');
routeTest($out['officeGuestWcReturn']['events'][2]['id']==='eg_gaeste_wc'
    &&$out['officeGuestWcReturn']['humanIdentity']==='UNKNOWN'
    &&$out['kitchenToUpperDressing']['humanIdentity']==='UNKNOWN'
    &&$out['roomActivityCannotProveCookingOrChangingClothes'],
    'events are spatially ordered but anonymous; no cooking/clothes claims');
routeTest($out['powerMeterVerified']===false
    &&$out['cameraHumanVehicleLinkVerified']===false
    &&$out['alarmMayBeControlled']===false,
    'no fabricated energy or car connection, no external writes');
$visu=['schema'=>1,'authority'=>'RESIDENT_PHONE_VISU_HINTS_READ_ONLY',
 'people'=>['johannes'=>['decision'=>'UNKNOWN'],
            'carina'=>['decision'=>'UNKNOWN']]];
$tile=JVResidentTileDashboard::evaluate(
    $visu,[],[],[],[],$now,[],$out
);
$html=JVResidentTileDashboard::render($tile);
routeTest(count($tile['roomPathsAnonymous'])===2
    &&str_contains($html,'Büro → Flur → Gäste-WC → Flur → Büro')
    &&str_contains($html,'Küche → OG Flur → Ankleide')
    &&str_contains($html,'Person unbekannt')
    &&$tile['people']['johannes']['status']==='NICHT BESTÄTIGT'
    &&$tile['people']['carina']['status']==='NICHT BESTÄTIGT',
    'two anonymous paths displayed compactly without changing individual state');
$changed=$state;
$changed['entries']=array_slice($entries,0,2);
$empty=JVResidentRoomPaths::evaluate($changed,$ledger,$now);
routeTest($empty['officeGuestWcReturn']===null
    &&$empty['kitchenToUpperDressing']===null,
    'incomplete route not fabricated');
$badSource=$ledger;
$badSource['perSource']['eg_gaeste_wc']['name']='Andere Messstelle';
$route=JVResidentRoomPaths::evaluate($state,$badSource,$now);
routeTest($route['officeGuestWcReturn']===null,
    'reused named source mismatch excludes guest WC from confidence');
$aged=$state;
foreach($aged['entries'] as &$item)$item['at']=$now-7200;
unset($item);
$route=JVResidentRoomPaths::evaluate($aged,$ledger,$now);
routeTest($route['roomEdgeCount90m']===0
    &&$route['officeGuestWcReturn']===null,
    'older events do not remain current traces');
$future=$state;$future['entries'][0]['at']=$now+200;
$route=JVResidentRoomPaths::evaluate($future,$ledger,$now);
routeTest($route['roomEdgeCount90m']===7
    &&$route['officeGuestWcReturn']===null,
    'future-dated source event is rejected');
echo "15-GUS anonymous spatial office-WC-office and kitchen-to-OG route recognition PASS\n";
