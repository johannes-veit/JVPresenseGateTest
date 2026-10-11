<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/P03OnvifEvents.php';
require_once dirname(__DIR__).'/libs/P03OnvifPullPoint.php';
require_once dirname(__DIR__).'/libs/P03OnvifCorrelator.php';

$errors=0;
function okOnvif(bool $good,string $why): void {
    global $errors;
    if (!$good) {
        $errors++;
        echo 'FAIL '.$why."\n";
    } else {
        echo 'PASS '.$why."\n";
    }
}
function notification(string $topic,string $rule,string $type,string $object='99',string $state='true'): string {
    return '<wsnt:NotificationMessage xmlns:wsnt="http://docs.oasis-open.org/wsn/b-2"'
      .' xmlns:tt="http://www.onvif.org/ver10/schema"'
      .' xmlns:wsa="http://www.w3.org/2005/08/addressing">'
      .'<wsnt:Topic>'.$topic.'</wsnt:Topic>'
      .'<wsnt:Message><tt:Message UtcTime="2026-10-11T13:30:00Z" PropertyOperation="Changed">'
      .'<tt:Source><tt:SimpleItem Name="Rule" Value="'.htmlspecialchars($rule,ENT_QUOTES|ENT_XML1).'"/></tt:Source>'
      .'<tt:Data><tt:SimpleItem Name="ObjectId" Value="'.$object.'"/>'
      .'<tt:SimpleItem Name="ObjectType" Value="'.$type.'"/>'
      .'<tt:SimpleItem Name="State" Value="'.$state.'"/></tt:Data>'
      .'</tt:Message></wsnt:Message></wsnt:NotificationMessage>';
}
$head='<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><tev:PullMessagesResponse xmlns:tev="http://www.onvif.org/ver10/events/wsdl">';
$foot='</tev:PullMessagesResponse></s:Body></s:Envelope>';
$xml=$head
 .notification('tns1:RuleEngine/LineDetector/Crossed','Überquerung zur Treppe','Human','12')
 .notification('tns1:RuleEngine/LineDetector/Crossed','Überquerung zur Straße','Human','13')
 .notification('tns1:RuleEngine/LineDetector/Crossed','Überquerung zur Treppe','MotorVehicle','14')
 .notification('tns1:RuleEngine/LineDetector/Crossed','Überquerung zur Treppe','','15')
 .notification('tns1:RuleEngine/CellMotionDetector/Motion','Überquerung zur Treppe','Human','16')
 .$foot;
$events=P03OnvifEvents::parseNotifications($xml);
okOnvif(count($events)===5,'ONVIF PullPoint XML parses all notification topics');
$proofs=array_values(array_filter($events,[P03OnvifEvents::class,'isHumanStairCrossing']));
okOnvif(count($proofs)===1,'Only Human on Treppe is a gate proof: NOT road/vehicle/generic/unclassified');
okOnvif(($proofs[0]['source']['Rule']??'')==='Überquerung zur Treppe',
    'Existing GUI rule name preserved across ONVIF XML');
okOnvif((P03OnvifEvents::summarize($proofs[0])['objectId']??'')==='12',
    'Event evidence preserves minimal object identity without user credentials');
$stopped=$events[0];
$stopped['data']['State']='false';
okOnvif(!P03OnvifEvents::isHumanStairCrossing($stopped),
    'ONVIF Stop/false is not a new person crossing');
$init=$events[0];
$init['operation']='Initialized';
okOnvif(!P03OnvifEvents::isHumanStairCrossing($init),
    'ONVIF Initialized snapshot does not fabricate a person');
okOnvif(P03OnvifEvents::parseNotifications('<!DOCTYPE e [<!ENTITY x SYSTEM "file:///etc/passwd">]><e>&x;</e>')===[],
    'No entity/DOCTYPE content becomes an ONVIF event');
okOnvif(P03OnvifEvents::verifiedSameHostUrl(
    'http://192.168.107.96/onvif/Subscription?Idx=1','192.168.107.96')!==null,
    'Valid same-camera ONVIF PullPoint URL accepted');
okOnvif(P03OnvifEvents::verifiedSameHostUrl(
    'http://192.168.2.253/onvif/Subscription','192.168.107.96')===null,
    'ONVIF XAddr may never redirect SymBox to a different VLAN/host');
okOnvif(P03OnvifEvents::verifiedSameHostUrl(
    'http://192.168.107.96:37777/onvif/Subscription','192.168.107.96')===null,
    'ONVIF XAddr cannot escape to Dahua TCP control ports');
$refEnvelope='<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:tev="http://www.onvif.org/ver10/events/wsdl"'
    .' xmlns:wsa="http://www.w3.org/2005/08/addressing"'
    .' xmlns:sub="urn:dahua:onvif:subscription">'
    .'<s:Body><tev:CreatePullPointSubscriptionResponse>'
    .'<tev:SubscriptionReference><wsa:Address>http://192.168.107.96/onvif/services</wsa:Address>'
    .'<wsa:ReferenceParameters><sub:SubscriptionId>42</sub:SubscriptionId>'
    .'</wsa:ReferenceParameters></tev:SubscriptionReference>'
    .'</tev:CreatePullPointSubscriptionResponse></s:Body></s:Envelope>';
$parameters=P03OnvifPullPoint::referenceParameters($refEnvelope);
okOnvif(count($parameters)===1 && $parameters[0]['name']==='SubscriptionId'
    && $parameters[0]['value']==='42' && $parameters[0]['namespace']==='urn:dahua:onvif:subscription',
    'ONVIF subscription reference parameters parsed for subsequent PullMessages');

// v0.7.1: official ONVIF ClassTypes is plural. Never accidentally
// accept a Human/Vehicle mixture in one notification.
$classed=$events[0];
unset($classed['data']['ObjectType']);
$classed['data']['ClassTypes']='Human';
okOnvif(P03OnvifEvents::isHumanStairCrossing($classed),
    'Real ONVIF ClassTypes=Human counts on staircase');
$classed['data']['ClassTypes']='Human,Vehicle';
okOnvif(!P03OnvifEvents::isHumanStairCrossing($classed),
    'Mixed ClassTypes=Human,Vehicle does not fabricate a person');
$classed['data']['ClassTypes']='Vehicle';
okOnvif(!P03OnvifEvents::isHumanStairCrossing($classed),
    'Vehicle-only classification does not count as Human');
$classed['data']['ClassTypes']='Human';
$classed['data']['ObjectClass']='MotorVehicle';
okOnvif(!P03OnvifEvents::isHumanStairCrossing($classed),
    'Contradictory ONVIF object class fields fail closed');
okOnvif(P03OnvifEvents::verifiedSameHostUrl(
    'http://192.168.107.96:8899/onvif/events','192.168.107.96',8899)!==null,
    'Camera-configured nonstandard ONVIF port is supported safely');
okOnvif(P03OnvifEvents::verifiedSameHostUrl(
    'http://192.168.107.96:8899/onvif/events','192.168.107.96')===null,
    'Unexpected port denied without explicit configured value');
$when='2010-09-16T07:50:45Z';
$nonce=base64_decode('LKqI6G/AikKCQrN0zqZFlg==');
$token=P03OnvifPullPoint::wsUsernameToken('someone','userpassword',$nonce,$when);
okOnvif(str_contains($token,'wsse:UsernameToken')
    && str_contains($token,'wsu:Created')
    && str_contains($token,'tuOSpGlFlIXsozq4HFNeeGeFLEI=')
    && !str_contains($token,'>userpassword<'),
    'ONVIF UsernameToken uses nonce/UTC/SHA1 digest, never plaintext secret');
$good='<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'
    .'<x:PullMessagesResponse xmlns:x="http://www.onvif.org/ver10/events/wsdl"/>'
    .'</s:Body></s:Envelope>';
$fault='<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'
    .'<s:Fault><s:Code><s:Value>s:Sender</s:Value></s:Code></s:Fault>'
    .'</s:Body></s:Envelope>';
okOnvif(P03OnvifPullPoint::isValidSoapResponse($good,'PullMessagesResponse'),
    'Proper ONVIF SOAP success response verified');
okOnvif(!P03OnvifPullPoint::isValidSoapResponse($fault,'PullMessagesResponse'),
    'SOAP Fault over HTTP 200 must never mean a live event stream');
okOnvif(!P03OnvifPullPoint::isValidSoapResponse('<html>error</html>','PullMessagesResponse'),
    'HTTP 200 HTML/error is rejected as ONVIF events');

$seen=[];
$e=['topic'=>'tns1:RuleEngine/LineDetector/Crossed','utc'=>'2026-10-11T09:00:00Z',
    'rule'=>'Überquerung zur Treppe','objectId'=>'41'];
okOnvif(P03OnvifEvents::isFresh($e,$seen,100,3),
    'First unique ONVIF person is accepted');
okOnvif(!P03OnvifEvents::isFresh($e,$seen,101,3),
    'Same wire event delivered again within 3 s is ignored');
okOnvif(P03OnvifEvents::isFresh($e,$seen,103,3),
    'Reused camera identifier after 3 s is accepted again');
$seen['invalidTimestamp']=9999;
okOnvif(P03OnvifEvents::isFresh(['objectId'=>'42'],$seen,105,3)
    && !isset($seen['invalidTimestamp']),
    'Future/broken dedupe entries are removed before matching');
okOnvif(P03OnvifEvents::verifiedSameHostUrl(
    'http://192.168.107.96/Subscription?Idx=0','192.168.107.96')!==null,
    'Standard ONVIF subscription endpoint from the official specification is accepted');
okOnvif(P03OnvifEvents::verifiedSameHostUrl(
    'http://192.168.107.96/cgi-bin/configManager.cgi?action=setConfig',
    '192.168.107.96')===null,
    'ONVIF endpoint validation rejects any CGI configuration path');
// Standard ONVIF LineDetector events commonly carry ObjectId only.
// Human classification can arrive as a separate ObjectDetection event.
$classEvent=[
    'topic'=>'tns1:RuleEngine/ObjectDetection/Object',
    'utc'=>'2026-10-11T13:33:01Z',
    'source'=>['VideoSourceConfigurationToken'=>'IPC1'],
    'data'=>['ObjectId'=>'77','ClassTypes'=>'Human']
];
$crossEvent=[
    'topic'=>'tns1:RuleEngine/LineDetector/Crossed',
    'utc'=>'2026-10-11T13:33:03Z',
    'source'=>['VideoSourceConfigurationToken'=>'IPC1',
        'Rule'=>'Überquerung zur Treppe'],
    'data'=>['ObjectId'=>'77']
];
$state=[];
okOnvif(P03OnvifCorrelator::ingest($crossEvent,$state,100)===null,
    'Bare line crossing with object ID is not automatically human');
$proof=P03OnvifCorrelator::ingest($classEvent,$state,115);
okOnvif(is_array($proof)&&$proof['objectId']==='77'
    && $proof['source']==='ONVIF_OBJECTID_CLASS_CORRELATION',
    'Human ObjectDetection joins stair LineDetector by same ObjectId across polls');
okOnvif(P03OnvifCorrelator::ingest($classEvent,$state,116)===null,
    'One completed correlation cannot emit two proofs');
$state=[];
okOnvif(P03OnvifCorrelator::ingest($classEvent,$state,100)===null,
    'Human class alone is not a crossing');
$proof=P03OnvifCorrelator::ingest($crossEvent,$state,115);
okOnvif(is_array($proof)&&$proof['objectId']==='77',
    'Crossing after classification works in reverse order');
$state=[];
$wrongStream=$classEvent;
$wrongStream['source']['VideoSourceConfigurationToken']='OTHER';
P03OnvifCorrelator::ingest($wrongStream,$state,100);
okOnvif(P03OnvifCorrelator::ingest($crossEvent,$state,101)===null,
    'ObjectId may not be correlated between different video sources');
$state=[];
$vehicle=$classEvent;
$vehicle['data']['ClassTypes']='Vehicle';
P03OnvifCorrelator::ingest($vehicle,$state,100);
okOnvif(P03OnvifCorrelator::ingest($crossEvent,$state,101)===null,
    'Vehicle on staircase never becomes a person by object ID alone');
$state=[];
$road=$crossEvent;
$road['source']['Rule']='Überquerung zur Straße';
P03OnvifCorrelator::ingest($classEvent,$state,100);
okOnvif(P03OnvifCorrelator::ingest($road,$state,101)===null,
    'Street line is excluded even if Human classifier detects same object');
$state=[];
$stale=$classEvent;
$stale['utc']='2026-10-11T13:31:01Z';
P03OnvifCorrelator::ingest($stale,$state,100);
okOnvif(P03OnvifCorrelator::ingest($crossEvent,$state,115)===null,
    'ObjectId with stale class timestamp is never combined with a later crossing');
$state=[];
$bare=$crossEvent;
$bare['data']['ObjectId']='';
okOnvif(P03OnvifCorrelator::ingest($bare,$state,110)===null,
    'No human proof without an object ID for delayed correlation');


// v0.7.2 real ONVIF nested wsnt:Message / tt:Message structure with
// ObjectId in tt:Key (not Data) from published ONVIF Core examples.
$nested='<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:wsnt="http://docs.oasis-open.org/wsn/b-2"'
    .' xmlns:tt="http://www.onvif.org/ver10/schema">'
    .'<s:Body><wsnt:NotificationMessage>'
    .'<wsnt:Topic>tns1:RuleEngine/LineDetector/Crossed</wsnt:Topic>'
    .'<wsnt:Message><tt:Message UtcTime="2026-10-11T13:33:00Z"'
    .' PropertyOperation="Initialized">'
    .'<tt:Source><tt:SimpleItem Name="Rule" Value="Überquerung zur Treppe"/>'
    .'<tt:SimpleItem Name="VideoSourceConfigurationToken" Value="IPC1"/></tt:Source>'
    .'<tt:Key><tt:SimpleItem Name="ObjectId" Value="35"/></tt:Key>'
    .'<tt:Data><tt:SimpleItem Name="ClassTypes" Value="Human"/></tt:Data>'
    .'</tt:Message></wsnt:Message></wsnt:NotificationMessage>'
    .'</s:Body></s:Envelope>';
$parsedNested=P03OnvifEvents::parseNotifications($nested);
okOnvif(count($parsedNested)===1
    && ($parsedNested[0]['utc']??'')==='2026-10-11T13:33:00Z'
    && ($parsedNested[0]['operation']??'')==='Initialized'
    && ($parsedNested[0]['data']['ObjectId']??'')==='35',
    'Nested tt:Message UTC/operation and tt:Key ObjectId are preserved');
okOnvif(!P03OnvifEvents::isHumanStairCrossing($parsedNested[0]??[]),
    'ONVIF Initialized snapshot never counted as a new person crossing');
$correlationState=[];
okOnvif(P03OnvifCorrelator::ingest($parsedNested[0],$correlationState,100)===null,
    'Correlator rejects initialization even with explicit Human classification');
$changed=str_replace('PropertyOperation="Initialized"','PropertyOperation="Changed"',$nested);
$parsedChanged=P03OnvifEvents::parseNotifications($changed);
okOnvif(count($parsedChanged)===1
    && P03OnvifEvents::isHumanStairCrossing($parsedChanged[0]),
    'Nested ONVIF Changed with explicit Human is accepted by precise parser');

$baseTime=strtotime('2026-10-11T13:33:00Z');
okOnvif(P03OnvifEvents::isRecent(
    ['utc'=>'2026-10-11T13:33:00Z'],$baseTime,120),
    'Exact camera UTC timestamp is accepted as fresh evidence');
okOnvif(!P03OnvifEvents::isRecent(
    ['utc'=>'2026-10-11T12:53:00Z'],$baseTime,120),
    'Old ONVIF replay is rejected regardless of matching rule and class');
okOnvif(!P03OnvifEvents::isRecent(
    ['utc'=>''],$baseTime,120),
    'Missing camera event time never contributes to Human gate evidence');
okOnvif(!P03OnvifEvents::isRecent(
    ['utc'=>'2026-10-11T17:00:00Z'],$baseTime,120),
    'Future or badly skewed camera clock fails closed');
$missingStream=$crossEvent;
unset($missingStream['source']['VideoSourceConfigurationToken']);
$noClassState=[];
P03OnvifCorrelator::ingest($classEvent,$noClassState,100);
okOnvif(P03OnvifCorrelator::ingest($missingStream,$noClassState,101)===null,
    'Unidentified video source cannot correlate Human class with crossing ObjectId');
$observerSource=file_get_contents(dirname(__DIR__).'/JVPresenceP03AuxObserver/module.php');
$from=strpos($observerSource,'public function OnvifPoll(): void');
$to=strpos($observerSource,'public function GetConfigurationForParent(): string',$from);
$pollSource=substr($observerSource,$from,$to-$from);
okOnvif(!str_contains($pollSource,'publishHumanProof(')
    && !preg_match('/->(?:SetValue|WriteAttributeInteger)\\([\\x27\\x22]HumanEventCounter/', $pollSource),
    'ONVIF diagnostic candidates cannot bypass event-time sequencing or claim HOME/LAGER transfers');


$conflictLine=$crossEvent;
$conflictLine['data']['ClassTypes']='Human,Vehicle';
$conflictState=[];
P03OnvifCorrelator::ingest($conflictLine,$conflictState,100);
okOnvif(P03OnvifCorrelator::ingest($classEvent,$conflictState,101)===null,
    'Explicit Human+Vehicle line class cannot be overridden by a later Human event');
$badClassOnly=$classEvent;
unset($badClassOnly['data']['ObjectId']);
$badClassState=[];
okOnvif(P03OnvifCorrelator::ingest($badClassOnly,$badClassState,100)===null,
    'Standard ClassTypes-only ObjectDetection (no ObjectId) is diagnostic, not a crossing');
$realTimeCross=$crossEvent;
$realTimeCross['utc']='2026-10-11T13:33:03Z';
$realTimeClass=$classEvent;
$realTimeClass['utc']='2026-10-11T13:33:01Z';
$timeState=[];
P03OnvifCorrelator::ingest($realTimeCross,$timeState,100);
$matched=P03OnvifCorrelator::ingest($realTimeClass,$timeState,104);
okOnvif(is_array($matched)
    && ($matched['utc']??'')==='2026-10-11T13:33:03Z',
    'Delayed class confirmation preserves original staircase crossing UTC, not class arrival time');
$retrySeen=[];
$eventKey=['rule'=>'stairs','objectId'=>'35','utc'=>'2026-10-11T13:33:03Z'];
okOnvif(P03OnvifEvents::isFresh($eventKey,$retrySeen,100,180),
    'First unique dated ONVIF crossing accepted');
okOnvif(!P03OnvifEvents::isFresh($eventKey,$retrySeen,115,180),
    'PullPoint replay of same dated crossing after 15 seconds is not double counted');
$newEventKey=$eventKey;
$newEventKey['utc']='2026-10-11T13:33:07Z';
okOnvif(P03OnvifEvents::isFresh($newEventKey,$retrySeen,118,180),
    'Real second crossing with new timestamp counted even if ObjectId reused');
okOnvif(!P03OnvifEvents::isRecent(['utc'=>'now'],$baseTime,120),
    'Non ISO8601 relative time strings can never masquerade as camera UTC');

$differentAnalytics=$classEvent;
$differentAnalytics['source']['VideoAnalyticsConfigurationToken']='ANALYSIS_B';
$matchingAnalyticsCross=$crossEvent;
$matchingAnalyticsCross['source']['VideoAnalyticsConfigurationToken']='ANALYSIS_A';
$analyticsState=[];
P03OnvifCorrelator::ingest($differentAnalytics,$analyticsState,100);
okOnvif(P03OnvifCorrelator::ingest($matchingAnalyticsCross,$analyticsState,101)===null,
    'ObjectId collision across different analytics configurations cannot prove a person');
// Long-running noisy camera: several thousand different object IDs during a
// short window must not make persistent Symcon Attributes grow indefinitely.
$stressState=[];
for($i=0;$i<4000;$i++){
    $e=[
        'topic'=>'tns1:RuleEngine/ObjectDetection/Object',
        'utc'=>'2026-10-11T13:33:00Z',
        'source'=>['VideoSourceConfigurationToken'=>'IPC1',
            'VideoAnalyticsConfigurationToken'=>'An1'],
        'data'=>['ObjectId'=>'obj'.$i,'ClassTypes'=>'Human']
    ];
    P03OnvifCorrelator::ingest($e,$stressState,5000);
}
okOnvif(count($stressState['classes']??[])<=128
    && count($stressState['crossings']??[])<=128,
    '4000 synthetic ONVIF objects do not produce unbounded persistent correlation memory');

echo "ONVIF test total errors: $errors\n";
exit($errors>0?1:0);
