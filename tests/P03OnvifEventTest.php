<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/P03OnvifEvents.php';
require_once dirname(__DIR__).'/libs/P03OnvifPullPoint.php';

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
    && str_contains($token,base64_encode(sha1($nonce.$when.'userpassword',true)))
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
echo "ONVIF test total errors: $errors\n";
exit($errors>0?1:0);
