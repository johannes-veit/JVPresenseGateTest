<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/libs/P03OnvifEvents.php';
require_once dirname(__DIR__).'/libs/P03OnvifCorrelator.php';

$tests=0;$errors=0;
function checkHuman(bool $passed,string $msg):void {
    global $tests,$errors;
    $tests++;
    if (!$passed) {$errors++;echo "FAIL: $msg\n";}
    else {echo "PASS: $msg\n";}
}

/**
 * Full ONVIF PullMessagesResponse with the nested wsnt:Message / tt:Message
 * format expected from a real camera. No hardcoded parsed PHP event objects.
 */
function makeFrame(array $items):string {
    $xml='<?xml version="1.0" encoding="UTF-8"?>'
        .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
        .' xmlns:tev="http://www.onvif.org/ver10/events/wsdl"'
        .' xmlns:wsnt="http://docs.oasis-open.org/wsn/b-2"'
        .' xmlns:tt="http://www.onvif.org/ver10/schema"><s:Body>'
        .'<tev:PullMessagesResponse>';
    foreach ($items as $it) {
        $topic=htmlspecialchars((string)($it['topic']??'tns1:RuleEngine/LineDetector/Crossed'),ENT_XML1|ENT_QUOTES);
        $utc=htmlspecialchars((string)($it['utc']??'2026-10-11T14:00:00Z'),ENT_XML1|ENT_QUOTES);
        $op=htmlspecialchars((string)($it['op']??'Changed'),ENT_XML1|ENT_QUOTES);
        $stream=htmlspecialchars((string)($it['stream']??'MAIN'),ENT_XML1|ENT_QUOTES);
        $ana=htmlspecialchars((string)($it['ana']??'ANA1'),ENT_XML1|ENT_QUOTES);
        $rule=htmlspecialchars((string)($it['rule']??'Überquerung zur Treppe'),ENT_XML1|ENT_QUOTES);
        $obj=htmlspecialchars((string)($it['obj']??''),ENT_XML1|ENT_QUOTES);
        $state=htmlspecialchars((string)($it['state']??'true'),ENT_XML1|ENT_QUOTES);
        $class=htmlspecialchars((string)($it['class']??''),ENT_XML1|ENT_QUOTES);
        $xml.='<wsnt:NotificationMessage><wsnt:Topic>'.$topic.'</wsnt:Topic>'
            .'<wsnt:Message><tt:Message UtcTime="'.$utc.'" PropertyOperation="'.$op.'">'
            .'<tt:Source><tt:SimpleItem Name="Rule" Value="'.$rule.'"/>'
            .'<tt:SimpleItem Name="VideoSourceConfigurationToken" Value="'.$stream.'"/>'
            .'<tt:SimpleItem Name="VideoAnalyticsConfigurationToken" Value="'.$ana.'"/>'
            .'</tt:Source>'
            .'<tt:Key><tt:SimpleItem Name="ObjectId" Value="'.$obj.'"/></tt:Key>'
            .'<tt:Data><tt:SimpleItem Name="State" Value="'.$state.'"/>';
        if ($class!=='') {
            $xml.='<tt:SimpleItem Name="ClassTypes" Value="'.$class.'"/>';
        }
        $xml.='</tt:Data></tt:Message></wsnt:Message></wsnt:NotificationMessage>';
    }
    return $xml.'</tev:PullMessagesResponse></s:Body></s:Envelope>';
}

/**
 * Simulated production path is still DIAGNOSTIC_ONLY on P03 v0.7.3:
 * wire SOAP -> actual parser -> UTC freshness -> actual correlator -> dedupe
 * -> ONVIF candidates. Legacy HumanCounter and zone are never modified.
 */
function feedHuman(string $camera,array $items,array &$states,int $now):array {
    $events=P03OnvifEvents::parseNotifications(makeFrame($items));
    $out=[];
    foreach ($events as $event) {
        $states[$camera]['wire']++;
        if (!P03OnvifEvents::isRecent($event,$now,120)) {
            $states[$camera]['stale']++;
            continue;
        }
        $positive=P03OnvifCorrelator::ingest($event,$states[$camera]['corr'],$now);
        if (!is_array($positive)) {
            continue;
        }
        $key=['rule'=>$positive['rule'],
            'objectId'=>$positive['objectId'],
            'utc'=>$positive['utc'],'origin'=>$positive['source']];
        if (!P03OnvifEvents::isFresh($key,$states[$camera]['seen'],$now,180)) {
            $states[$camera]['duplicate']++;
            continue;
        }
        $states[$camera]['humanCandidates']++;
        $out[]=$positive;
    }
    return $out;
}
$start=strtotime('2026-10-11T14:00:00Z');
$empty=static fn()=>['wire'=>0,'stale'=>0,'duplicate'=>0,
    'humanCandidates'=>0,'seen'=>[],'corr'=>['classes'=>[],'crossings'=>[]]];
$states=['JV_LEFT'=>$empty(),'WORK_LEFT'=>$empty()];
$legacyCounter=['JV_LEFT'=>0,'WORK_LEFT'=>6];
$zone='UNKNOWN';

// Camera A: genuine classified Human crossing stairs
$good=feedHuman('JV_LEFT',[['obj'=>'11','class'=>'Human']],$states,$start);
checkHuman(count($good)===1 && $good[0]['rule']==='Überquerung zur Treppe'
    && $good[0]['source']==='ONVIF_INLINE_HUMAN',
    'JV_LEFT: real stair Human START recognized from ONVIF SOAP XML');

// Camera B: own stair line, Human START
$work=feedHuman('WORK_LEFT',[['obj'=>'20','class'=>'Human']],$states,$start);
checkHuman(count($work)===1 && $work[0]['objectId']==='20',
    'WORK_LEFT: real stair Human START recognized independently');

// Work camera additional STREET rule: never counts
$street=feedHuman('WORK_LEFT',[
    ['obj'=>'21','rule'=>'Überquerung zur Straße','class'=>'Human']
],$states,$start);
checkHuman($street===[],'WORK_LEFT IVS2 street line Human excluded from gate');

// Vehicle or combined classification: never counts, both cameras
$vehicles=feedHuman('JV_LEFT',[
    ['obj'=>'12','class'=>'MotorVehicle'],
    ['obj'=>'13','class'=>'Human,MotorVehicle']
],$states,$start);
checkHuman($vehicles===[],'MotorVehicle and mixed Human/Vehicle rejected on stair line');

// Body/motion topics anywhere in camera image: never count
$motion=feedHuman('JV_LEFT',[
    ['obj'=>'14','class'=>'Human','topic'=>'tns1:VideoSource/MotionAlarm'],
    ['obj'=>'15','class'=>'Human','topic'=>'tns1:RuleEngine/CellMotionDetector/Motion']
],$states,$start);
checkHuman($motion===[],'VideoMotion and generic SMD do not prove stair crossing');

// Same event delivered again on the next 25s PullPoint poll
$repeat=feedHuman('JV_LEFT',[
    ['obj'=>'11','class'=>'Human']
],$states,$start+25);
checkHuman($repeat===[] && $states['JV_LEFT']['duplicate']===1,
    'Identical ONVIF crossing replayed after 25s is not counted twice');

// Real NEW crossing reuses ObjectId but has new camera UTC
$new=feedHuman('JV_LEFT',[
    ['obj'=>'11','class'=>'Human','utc'=>'2026-10-11T14:00:30Z']
],$states,$start+30);
checkHuman(count($new)===1,'Reused Dahua ObjectId with NEW UTC counts again');

// Unclassified tripwire: never counts alone
$unclassified=feedHuman('WORK_LEFT',[
    ['obj'=>'30','utc'=>'2026-10-11T14:00:40Z']
],$states,$start+40);
checkHuman($unclassified===[],'Unclassified ONVIF LineDetector ObjectId cannot prove Human');
// Matching classification with same stream and object id: counts
$class=feedHuman('WORK_LEFT',[
    ['obj'=>'30','class'=>'Human','utc'=>'2026-10-11T14:00:42Z',
        'topic'=>'tns1:RuleEngine/ObjectDetection/Object']
],$states,$start+42);
checkHuman(count($class)===1
    && $class[0]['source']==='ONVIF_OBJECTID_CLASS_CORRELATION'
    && $class[0]['utc']==='2026-10-11T14:00:40Z',
    'Later Human class matches only same stream/ObjectId and retains crossing time');

// Class arrives first, followed by stair line
$before=feedHuman('JV_LEFT',[
    ['obj'=>'44','class'=>'Human','utc'=>'2026-10-11T14:00:46Z',
        'topic'=>'tns1:RuleEngine/ObjectDetection/Object']
],$states,$start+46);
$after=feedHuman('JV_LEFT',[
    ['obj'=>'44','utc'=>'2026-10-11T14:00:48Z']
],$states,$start+48);
checkHuman($before===[] && count($after)===1,
    'Reverse arrival order: Human classification before crossing recognized');

// STOP cancels pending crossing; late human must not cause a false positive
$pending=feedHuman('JV_LEFT',[
    ['obj'=>'55','utc'=>'2026-10-11T14:00:51Z']
],$states,$start+51);
$stop=feedHuman('JV_LEFT',[
    ['obj'=>'55','utc'=>'2026-10-11T14:00:52Z','state'=>'false']
],$states,$start+52);
$late=feedHuman('JV_LEFT',[
    ['obj'=>'55','class'=>'Human','utc'=>'2026-10-11T14:00:53Z',
        'topic'=>'tns1:RuleEngine/ObjectDetection/Object']
],$states,$start+53);
checkHuman($pending===[] && $stop===[] && $late===[],
    'STOP cancels pending crossing and prevents delayed human false positive');

// Initialized historical/current state cannot be used as new detection
$init=feedHuman('JV_LEFT',[
    ['obj'=>'56','class'=>'Human','op'=>'Initialized','utc'=>'2026-10-11T14:00:54Z']
],$states,$start+54);
checkHuman($init===[],'ONVIF Initialized with Human class never increments');

// Exact same ObjectId on DIFFERENT camera cannot correlate
$foreignFirst=feedHuman('JV_LEFT',[
    ['obj'=>'60','utc'=>'2026-10-11T14:00:56Z']
],$states,$start+56);
$foreignClass=feedHuman('WORK_LEFT',[
    ['obj'=>'60','class'=>'Human','utc'=>'2026-10-11T14:00:57Z',
        'topic'=>'tns1:RuleEngine/ObjectDetection/Object']
],$states,$start+57);
checkHuman($foreignFirst===[] && $foreignClass===[],
    'No ObjectId matching across different physical camera observers');

// Expired/replayed event: not counted, no correlation left
$stale=feedHuman('JV_LEFT',[
    ['obj'=>'70','class'=>'Human','utc'=>'2026-10-11T13:00:00Z']
],$states,$start+60);
checkHuman($stale===[] && $states['JV_LEFT']['stale']===1,
    'Old camera timestamp rejected independently from successful connection');

// Fail-closed: none of the simulated ONVIF candidates changes legacy proof
// counters or HOME/LAGER; these remain purely passive diagnostic metrics.
checkHuman($legacyCounter['JV_LEFT']===0 && $legacyCounter['WORK_LEFT']===6
    && $zone==='UNKNOWN',
    'Legacy HumanCounter 0/6 and HOME/LAGER zone remain unchanged in diagnostic mode');

checkHuman($states['JV_LEFT']['humanCandidates']===3
    && $states['WORK_LEFT']['humanCandidates']===2,
    'Exact simulated candidate totals: JV_LEFT=3, WORK_LEFT=2');
checkHuman($states['JV_LEFT']['wire']>8 && $states['WORK_LEFT']['wire']>=5,
    'Received wire event counts stay separate from Human candidate counters');

echo "P03 FINAL TWO-CAMERA HUMAN SIMULATION: $tests tests, $errors errors\n";
exit($errors>0 ? 1 : 0);
