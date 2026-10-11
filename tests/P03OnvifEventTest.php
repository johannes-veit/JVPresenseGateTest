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
echo "ONVIF test total errors: $errors\n";
exit($errors>0?1:0);
