<?php
declare(strict_types=1);

require_once __DIR__ . '/P03OnvifEvents.php';

/**
 * Short-lived, strictly read-only ONVIF PullPoint subscription.
 * Creates only a temporary ONVIF event listener, never configures the IPC.
 * One camera / one observer, no NVR, no extra network access from a PC.
 */
final class P03OnvifPullPoint
{
    /** @return array{ok:bool,url:string,until:int,status:string,events:array} */
    public static function poll(
        string $host, int $port, string $username, string $password,
        string $subscriptionUrl, int $validUntil
    ): array {
        $out = ['ok'=>false,'url'=>'','until'=>0,'status'=>'','events'=>[]];
        if ($host === '' || $port < 1 || $port > 65535
            || $username === '' || $password === '') {
            $out['status']='ONVIF-Zugang nicht vollständig';
            return $out;
        }
        $base='http://' . $host . ':' . $port;
        $clientUrl = P03OnvifEvents::verifiedSameHostUrl($subscriptionUrl,$host);
        if ($clientUrl === null || $validUntil <= time()+10) {
            $cap = self::soap($base . '/onvif/device_service',
                $username,$password,'GetCapabilities',
                '<tds:GetCapabilities><tds:Category>Events</tds:Category></tds:GetCapabilities>',
                'device');
            if (!$cap['ok']) {
                $out['status']='ONVIF Device/GetCapabilities ' . $cap['status'];
                return $out;
            }
            $eventService=self::findXmlValue($cap['body'],
                '//*[local-name()="Capabilities"]/*[local-name()="Events"]/*[local-name()="XAddr"]');
            $eventUrl=P03OnvifEvents::verifiedSameHostUrl($eventService,$host);
            if ($eventUrl === null) {
                $out['status']='ONVIF Events XAddr nicht vorhanden oder Fremdadresse';
                return $out;
            }
            $subscription=self::soap($eventUrl,$username,$password,
                'CreatePullPointSubscription',
                '<tev:CreatePullPointSubscription>'
                . '<tev:InitialTerminationTime>PT10M</tev:InitialTerminationTime>'
                . '</tev:CreatePullPointSubscription>', 'events');
            if (!$subscription['ok']) {
                $out['status']='ONVIF CreatePullPointSubscription ' . $subscription['status'];
                return $out;
            }
            $address=self::findXmlValue($subscription['body'],
                '//*[local-name()="SubscriptionReference"]/*[local-name()="Address"]');
            $clientUrl=P03OnvifEvents::verifiedSameHostUrl($address,$host);
            if ($clientUrl===null) {
                $out['status']='ONVIF SubscriptionReference ohne verifizierte Kamera-Adresse';
                return $out;
            }
            // Soft expiry before ONVIF's requested PT10M hard expiration.
            $validUntil=time()+8*60;
        }

        $pull=self::soap($clientUrl,$username,$password,'PullMessages',
            '<tev:PullMessages>'
            . '<tev:Timeout>PT1S</tev:Timeout>'
            . '<tev:MessageLimit>24</tev:MessageLimit>'
            . '</tev:PullMessages>', 'events');
        if (!$pull['ok']) {
            // Drop stale PullPoint on the next iteration; never reuse a dead
            // subscription or claim a live stream merely from an old HTTP 200.
            $out['status']='ONVIF PullMessages ' . $pull['status'];
            return $out;
        }
        $out['ok']=true;
        $out['url']=$clientUrl;
        $out['until']=$validUntil;
        $out['status']='ONVIF PullPoint LIVE, HTTP 200';
        $out['events']=P03OnvifEvents::parseNotifications($pull['body']);
        return $out;
    }

    /** @return array{ok:bool,body:string,status:string} */
    private static function soap(
        string $url, string $username,string $password,string $action,
        string $body,string $service
    ): array {
        $namespace = $service==='device'
            ? 'http://www.onvif.org/ver10/device/wsdl'
            : 'http://www.onvif.org/ver10/events/wsdl';
        $actionUri = $service==='device'
            ? $namespace . '/' . $action
            : ($action==='PullMessages'
                ? $namespace . '/PullPointSubscription/PullMessagesRequest'
                : $namespace . '/EventPortType/' . $action . 'Request');
        // SOAP 1.2 with ONVIF WSA Action. Real hardware may require WSSE
        // instead of Digest; if unavailable the module reports an auth error.
        $xml='<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:tds="http://www.onvif.org/ver10/device/wsdl"'
            . ' xmlns:tev="http://www.onvif.org/ver10/events/wsdl"'
            . ' xmlns:wsa="http://www.w3.org/2005/08/addressing">'
            . '<s:Header><wsa:Action>' . htmlspecialchars($actionUri,ENT_XML1)
            . '</wsa:Action><wsa:To>'
            . htmlspecialchars($url, ENT_XML1) . '</wsa:To></s:Header>'
            . '<s:Body>' . $body . '</s:Body></s:Envelope>';
        $ch=curl_init($url);
        if ($ch===false) {
            return ['ok'=>false,'body'=>'','status'=>'curl_init nicht verfügbar'];
        }
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$xml,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/soap+xml; charset=utf-8; action="' . $actionUri . '"',
                'Accept: application/soap+xml'
            ],
            CURLOPT_HTTPAUTH=>CURLAUTH_DIGEST,
            CURLOPT_USERPWD=>$username . ':' . $password,
            CURLOPT_CONNECTTIMEOUT=>2,
            CURLOPT_TIMEOUT=>6,
            CURLOPT_NOSIGNAL=>true,
            CURLOPT_FOLLOWLOCATION=>false
        ]);
        $answer=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if (!is_string($answer) || $status<200 || $status>=300) {
            return ['ok'=>false,'body'=>'',
                'status'=>'HTTP ' . $status . ($error!=='' ? ' / ' . substr($error,0,180) : '')];
        }
        if (stripos($answer,'<s:Fault')!==false
            || stripos($answer,'<env:Fault')!==false
            || stripos($answer,'<SOAP-ENV:Fault')!==false) {
            return ['ok'=>false,'body'=>'','status'=>'SOAP Fault'];
        }
        if (strlen($answer)>1024*1024) {
            return ['ok'=>false,'body'=>'','status'=>'SOAP Body zu groß'];
        }
        return ['ok'=>true,'body'=>$answer,'status'=>'HTTP 200'];
    }

    private static function findXmlValue(string $xml,string $path): string
    {
        if (!class_exists('DOMDocument') || strlen($xml)>1024*1024) {
            return '';
        }
        $doc=new DOMDocument();
        $errors=libxml_use_internal_errors(true);
        try {
            if (!@$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) {
                return '';
            }
            $xp=new DOMXPath($doc);
            $node=$xp->query($path)->item(0);
            return $node ? trim((string)$node->textContent) : '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
    }
}
