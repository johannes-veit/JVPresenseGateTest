<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/libs/P03OnvifPullPoint.php';

$count=0;$bad=0;
function confirm(bool $ok,string $why):void{
    global $count,$bad;$count++;
    if(!$ok){$bad++;echo "FAIL $why\n";}else{echo "PASS $why\n";}
}
$host='192.168.107.96';$port=80;
$cap='<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
    .'<s:Body><tds:GetCapabilitiesResponse xmlns:tds="http://www.onvif.org/ver10/device/wsdl">'
    .'<tds:Capabilities><tt:Events xmlns:tt="http://www.onvif.org/ver10/schema">'
    .'<tt:XAddr>http://192.168.107.96/onvif/events</tt:XAddr>'
    .'</tt:Events></tds:Capabilities></tds:GetCapabilitiesResponse></s:Body></s:Envelope>';
function subscription(string $duration='PT600S', string $address='http://192.168.107.96/Subscription?Idx=7'):string{
    $current=strtotime('2026-10-11T13:00:00Z');
    $seconds=substr($duration,2,-1);
    $until=gmdate('Y-m-d\TH:i:s\Z',$current+(int)$seconds);
    return '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
        .'<s:Body><tev:CreatePullPointSubscriptionResponse xmlns:tev="http://www.onvif.org/ver10/events/wsdl">'
        .'<tev:SubscriptionReference><wsa:Address xmlns:wsa="http://www.w3.org/2005/08/addressing">'
        .htmlspecialchars($address,ENT_XML1).'</wsa:Address></tev:SubscriptionReference>'
        .'<tev:CurrentTime>2026-10-11T13:00:00Z</tev:CurrentTime>'
        .'<tev:TerminationTime>'.$until.'</tev:TerminationTime>'
        .'</tev:CreatePullPointSubscriptionResponse></s:Body></s:Envelope>';
}
function messages(int $number):string{
    $out='<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
        .'<s:Body><tev:PullMessagesResponse xmlns:tev="http://www.onvif.org/ver10/events/wsdl"'
        .' xmlns:wsnt="http://docs.oasis-open.org/wsn/b-2"'
        .' xmlns:tt="http://www.onvif.org/ver10/schema">';
    for($i=0;$i<$number;$i++){
        $out.='<wsnt:NotificationMessage>'
            .'<wsnt:Topic>tns1:RuleEngine/LineDetector/Crossed</wsnt:Topic>'
            .'<wsnt:Message><tt:Message UtcTime="2026-10-11T13:00:05Z" PropertyOperation="Changed">'
            .'<tt:Source><tt:SimpleItem Name="Rule" Value="Überquerung zur Treppe"/></tt:Source>'
            .'<tt:Key><tt:SimpleItem Name="ObjectId" Value="'.$i.'"/></tt:Key>'
            .'<tt:Data><tt:SimpleItem Name="ClassTypes" Value="Human"/></tt:Data>'
            .'</tt:Message></wsnt:Message></wsnt:NotificationMessage>';
    }
    return $out.'</tev:PullMessagesResponse></s:Body></s:Envelope>';
}
$requests=[];
$transport=static function($url,$username,$password,$action,$body,$service,$refs) use (&$requests,$cap){
    $requests[]=['url'=>$url,'action'=>$action,'body'=>$body,'refs'=>$refs];
    $response=match($action){
        'GetCapabilities'=>$cap,
        'CreatePullPointSubscription'=>subscription(),
        'PullMessages'=>messages(1),
        default=>''
    };
    return ['ok'=>$response!=='','body'=>$response,'status'=>$response!==''?'HTTP 200':'ERROR'];
};
$first=P03OnvifPullPoint::poll($host,$port,'test-user','secret','',0,'[]',$transport,1000);
confirm($first['ok']===true && $first['subscribed']===true
    && $first['eventCount']===1 && count($first['events'])===1,
    'Fresh subscription: capabilities, registration, event pull and parser all succeed');
confirm(array_column($requests,'action')===
    ['GetCapabilities','CreatePullPointSubscription','PullMessages'],
    'Connection registers ONVIF subscription before receiving first notification');
confirm(($first['until']??0)===1480 && str_contains($first['url'],'/Subscription?Idx=7'),
    'Lease is capped at 8m; subscription address is camera-owned');
$requests=[];
$second=P03OnvifPullPoint::poll($host,$port,'test-user','secret',
    $first['url'],$first['until'],$first['reference'],$transport,1015);
confirm($second['ok']===true && array_column($requests,'action')===['PullMessages'],
    'Steady polling reuses subscription without spamming CreatePullPointSubscription');
$requests=[];
$third=P03OnvifPullPoint::poll($host,$port,'test-user','secret',
    $first['url'],$first['until'],$first['reference'],$transport,1475);
confirm($third['ok']===true &&
    array_column($requests,'action')===
    ['GetCapabilities','CreatePullPointSubscription','PullMessages'],
    'Expired subscription triggers a clean new registration');
confirm(P03OnvifPullPoint::subscriptionLifetime(subscription('PT45S'))===45,
    'Camera-granted short 45s lease read from authoritative ONVIF response');
confirm(P03OnvifPullPoint::subscriptionLifetime(subscription('PT120S'))===120,
    '120s camera lease honored regardless of SymBox UTC offset');
confirm(P03OnvifPullPoint::subscriptionLifetime('<bad>')===60,
    'Unparseable camera expiry never defaults to a falsely long subscription');
$short=static function($url,$u,$p,$action,$body,$service,$refs)use($cap){
    return ['ok'=>true,'body'=>match($action){
        'GetCapabilities'=>$cap,
        'CreatePullPointSubscription'=>subscription('PT10S'),
        'PullMessages'=>messages(0)
    },'status'=>'HTTP 200'];
};
$shortResult=P03OnvifPullPoint::poll($host,$port,'user','pw','',0,'[]',$short,1000);
confirm(!$shortResult['ok'] && str_contains($shortResult['status'],'Laufzeit'),
    'Too-short camera subscription rejected, avoiding permanent invalid PullPoint');
$unauthorized=static function() {return ['ok'=>false,'body'=>'','status'=>'HTTP 401'];};
$authFail=P03OnvifPullPoint::poll($host,$port,'user','pw','',0,'[]',$unauthorized,1000);
confirm($authFail['ok']===false && str_contains($authFail['status'],'GetCapabilities HTTP 401'),
    'Digest or WSSE rejection reported as connection failure, never as LIVE');
$badHost=static function($url,$u,$p,$action,$body,$service,$refs)use($cap){
    if($action==='GetCapabilities'){
        return ['ok'=>true,'body'=>str_replace('192.168.107.96/onvif/events',
            '192.168.2.253/onvif/events',$cap),'status'=>'HTTP 200'];
    }
    throw new RuntimeException('Should never contact foreign ONVIF host');
};
$deny=P03OnvifPullPoint::poll($host,$port,'user','pw','',0,'[]',$badHost,1000);
confirm($deny['ok']===false && str_contains($deny['status'],'Fremdadresse'),
    'Camera supplied foreign ONVIF XAddr rejected before network request');
$onvifFault='<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
    .'<s:Body><s:Fault><s:Reason><s:Text>Invalid Subscription</s:Text>'
    .'</s:Reason></s:Fault></s:Body></s:Envelope>';
$badPull=static function($url,$u,$p,$action,$body,$service,$refs)use($onvifFault){
    return ['ok'=>true,'body'=>$onvifFault,'status'=>'HTTP 200'];
};
$fail=P03OnvifPullPoint::poll($host,$port,'user','pw',
    'http://192.168.107.96/Subscription?Idx=7',1200,'[]',$badPull,1000);
confirm($fail['ok']===false && !($fail['subscribed']??false),
    'ONVIF SOAP fault despite HTTP 200 never reports a subscribed event source');
$empty=static function($url,$u,$p,$action,$body,$service,$refs){
    return ['ok'=>true,'body'=>messages(0),'status'=>'HTTP 200'];
};
$noEvents=P03OnvifPullPoint::poll($host,$port,'user','pw',
    'http://192.168.107.96/Subscription?Idx=7',1200,'[]',$empty,1000);
confirm($noEvents['ok']===true && $noEvents['eventCount']===0
    && str_contains($noEvents['status'],'Ereignisse=0'),
    'Established subscription with zero events distinct from connection error');
// 2026-10-11 real JV_LEFT: HTTP 0 "Empty reply from server" occurs
// BEFORE subscription or event registration. Do not manufacture an IVS fault.
// Under injected transport the production read-only probe must not run.
$emptyReply=static function($url,$user,$pass,$action,$body,$service,$refs) {
    return ['ok'=>false,'body'=>'',
        'status'=>'HTTP 0 / CURL 52 / TCP=20ms / HTTP-Wartezeit=0ms / Empty reply from server'];
};
$zero=P03OnvifPullPoint::poll($host,$port,'user','pw','',0,'[]',$emptyReply,1000);
confirm($zero['ok']===false && $zero['eventCount']===0
    && str_contains($zero['status'],'GetCapabilities')
    && str_contains($zero['status'],'CURL 52')
    && !($zero['subscribed']??false),
    'Real-world HTTP0/cURL52 classified as ONVIF transport failure before registration');
$module=file_get_contents(dirname(__DIR__).'/libs/P03OnvifPullPoint.php');
confirm(str_contains($module,'CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1')
    && str_contains($module,"CURLOPT_NOPROXY=>'*'")
    && str_contains($module,"'Expect:'")
    && str_contains($module,"'Connection: close'"),
    'Embedded Dahua SOAP POST explicitly uses HTTP/1.1, direct camera connection, no Expect');
confirm(str_contains($module,'CURLOPT_HTTPGET=>true')
    && !str_contains($module,'action=setConfig')
    && !str_contains($module,'configManager.setConfig'),
    'Optional HTTP0 transport probes are read-only; no Dahua camera configuration writes');
echo "P03 ONVIF PROTOCOL INTEGRATION: $count tests, $bad errors\n";
exit($bad>0?1:0);
