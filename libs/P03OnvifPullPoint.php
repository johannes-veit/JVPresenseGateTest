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
        string $subscriptionUrl, int $validUntil, string $referenceJson = '[]'
    ): array {
        $out = ['ok'=>false,'url'=>'','until'=>0,'status'=>'','events'=>[], 'reference'=>'[]'];
        $refs = json_decode($referenceJson,true);
        if (!is_array($refs)) {
            $refs=[];
        }
        if ($host === '' || $port < 1 || $port > 65535
            || $username === '' || $password === '') {
            $out['status']='ONVIF-Zugang nicht vollständig';
            return $out;
        }
        $base='http://' . $host . ':' . $port;
        $clientUrl = P03OnvifEvents::verifiedSameHostUrl($subscriptionUrl,$host,$port);
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
            $eventUrl=P03OnvifEvents::verifiedSameHostUrl($eventService,$host,$port);
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
            $clientUrl=P03OnvifEvents::verifiedSameHostUrl($address,$host,$port);
            if ($clientUrl===null) {
                $out['status']='ONVIF SubscriptionReference ohne verifizierte Kamera-Adresse';
                return $out;
            }
            // Some Dahua firmwares return an ONVIF ReferenceParameters
            // SubscriptionId instead of an identity-bearing unique URL.
            $refs=self::referenceParameters($subscription['body']);
            // Soft expiry before ONVIF's requested PT10M hard expiration.
            $validUntil=time()+8*60;
        }

        $pull=self::soap($clientUrl,$username,$password,'PullMessages',
            '<tev:PullMessages>'
            . '<tev:Timeout>PT1S</tev:Timeout>'
            . '<tev:MessageLimit>24</tev:MessageLimit>'
            . '</tev:PullMessages>', 'events', $refs);
        if (!$pull['ok']) {
            // Drop stale PullPoint on the next iteration; never reuse a dead
            // subscription or claim a live stream merely from an old HTTP 200.
            $out['status']='ONVIF PullMessages ' . $pull['status'];
            return $out;
        }
        $out['ok']=true;
        $out['url']=$clientUrl;
        $out['until']=$validUntil;
        $out['reference']=json_encode($refs, JSON_UNESCAPED_SLASHES)?:'[]';
        if (!self::isValidSoapResponse($pull['body'], 'PullMessagesResponse')) {
            $out['status']='ONVIF HTTP 200 ohne gueltige PullMessagesResponse';
            return $out;
        }
        $out['status']='ONVIF PullPoint LIVE, HTTP 200';
        $out['events']=P03OnvifEvents::parseNotifications($pull['body']);
        return $out;
    }

    /** @return array{ok:bool,body:string,status:string} */
    private static function soap(
        string $url, string $username,string $password,string $action,
        string $body,string $service,array $referenceParams=[]
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
            . htmlspecialchars($url, ENT_XML1) . '</wsa:To>'
            . self::wsUsernameToken($username,$password)
            . self::referencesSoapHeader($referenceParams) . '</s:Header>'
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


    /** Extract ONVIF SubscriptionReference/ReferenceParameters safely as data. */
    public static function referenceParameters(string $xml): array
    {
        if (!class_exists('DOMDocument') || strlen($xml)>1024*1024) {
            return [];
        }
        $doc=new DOMDocument();
        $prev=libxml_use_internal_errors(true);
        try {
            if (!@$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) {
                return [];
            }
            $xp=new DOMXPath($doc);
            $nodes=$xp->query('//*[local-name()="SubscriptionReference"]/*[local-name()="ReferenceParameters"]/*');
            if ($nodes===false) {
                return [];
            }
            $result=[];
            foreach ($nodes as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                $name=(string)$node->localName;
                $ns=(string)$node->namespaceURI;
                $val=trim((string)$node->textContent);
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,79}$/D',$name)
                    || strlen($ns)>250 || strlen($val)>160 || $ns===''
                    || count($result)>=8) {
                    continue;
                }
                $result[]=['name'=>$name,'namespace'=>$ns,'value'=>$val];
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** @param array<int,array<string,string>> $refs */
    private static function referencesSoapHeader(array $refs): string
    {
        $out='';
        foreach (array_slice($refs,0,8) as $i=>$ref) {
            if (!is_array($ref)) {
                continue;
            }
            $name=(string)($ref['name']??'');
            $ns=(string)($ref['namespace']??'');
            $val=(string)($ref['value']??'');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,79}$/D',$name)
                || $ns==='' || strlen($ns)>250 || strlen($val)>160) {
                continue;
            }
            $q='p'.(int)$i;
            $out.='<'.$q.':'.$name.' xmlns:'.$q.'="'.htmlspecialchars($ns,ENT_XML1|ENT_QUOTES).'"'
                .' wsa:IsReferenceParameter="true">'
                .htmlspecialchars($val,ENT_XML1|ENT_QUOTES)
                .'</'.$q.':'.$name.'>';
        }
        return $out;
    }

    /**
     * Never call an arbitrary 200-OK HTML/error response a valid event stream.
     * Reject all namespace variants of SOAP Fault and malformed XML.
     */
    public static function isValidSoapResponse(string $xml,string $operation): bool
    {
        if (!class_exists('DOMDocument') || strlen($xml)>1024*1024) {
            return false;
        }
        $document=new DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try {
            if (!@$document->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) {
                return false;
            }
            $xpath=new DOMXPath($document);
            $faults=$xpath->query('//*[local-name()="Body"]/*[local-name()="Fault"]');
            if ($faults!==false && $faults->length>0) {
                return false;
            }
            if (!preg_match('/^[A-Za-z]+Response$/D',$operation)) {
                return false;
            }
            $items=$xpath->query('//*[local-name()="Body"]/*[local-name()="'.$operation.'"]');
            return $items!==false && $items->length===1;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** ONVIF WS-Security nonce-based UsernameToken digest. */
    public static function wsUsernameToken(string $user,string $secret,?string $nonce=null,?string $when=null): string
    {
        $nonce=$nonce??random_bytes(16);
        $when=$when??gmdate('Y-m-d\TH:i:s\Z');
        $digest=base64_encode(sha1($nonce.$when.$secret,true));
        $base='http://docs.oasis-open.org/wss/2004/01/';
        return '<wsse:Security xmlns:wsse="'.$base.'oasis-200401-wss-wssecurity-secext-1.0.xsd"'
            .' xmlns:wsu="'.$base.'oasis-200401-wss-wssecurity-utility-1.0.xsd"'
            .' s:mustUnderstand="1"><wsse:UsernameToken>'
            .'<wsse:Username>'.htmlspecialchars($user,ENT_XML1|ENT_QUOTES).'</wsse:Username>'
            .'<wsse:Password Type="'.$base.'oasis-200401-wss-username-token-profile-1.0#PasswordDigest">'
            .$digest.'</wsse:Password>'
            .'<wsse:Nonce EncodingType="'.$base.'oasis-200401-wss-soap-message-security-1.0#Base64Binary">'
            .base64_encode($nonce).'</wsse:Nonce>'
            .'<wsu:Created>'.htmlspecialchars($when,ENT_XML1|ENT_QUOTES).'</wsu:Created>'
            .'</wsse:UsernameToken></wsse:Security>';
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
