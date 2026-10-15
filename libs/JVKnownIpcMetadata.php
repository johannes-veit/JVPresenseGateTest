<?php
declare(strict_types=1);

/**
 * Read-only inventory of OTHER Dahua IPCs already configured in Symcon.
 * Inputs are ONLY metadata supplied by a caller (no camera connection).
 * Historical addresses are discovery HINTS, never live camera identities.
 * No authenticated CGI, SmartPlan writes, observer instantiation, ARP, ping.
 */
final class JVKnownIpcMetadata
{
    private const KNOWN=[
        '192.168.107.92'=>['role'=>'FARB_CONTAINER','place'=>'Farbcontainer',
            'priority'=>4,'model'=>'IPC-HFW5442E-ZE'],
        '192.168.107.94'=>['role'=>'WORK_LEFT_P03','place'=>'Lagerplatz Werkstatt links (aktuelle P03)',
            'priority'=>9,'model'=>'IPC-HFW5442E-ZE'],
        '192.168.107.95'=>['role'=>'JV_LEFT_P03','place'=>'Lagerplatz JV links (aktuelle P03)',
            'priority'=>9,'model'=>'IPC-HFW5442E-ZE'],
        '192.168.107.96'=>['role'=>'JV_RIGHT_CANDIDATE','place'=>'Lagerplatz JV rechts',
            'priority'=>2,'model'=>'IPC-HFW5442E-ZE'],
        '192.168.107.97'=>['role'=>'TORHALLE_EINFAHRT_CANDIDATE','place'=>'neben Torhalle Einfahrt',
            'priority'=>1,'model'=>'IPC-HFW5442E-ZE'],
        '192.168.107.98'=>['role'=>'TORHALLE_STRASSE_CANDIDATE','place'=>'Torhalle Strasse',
            'priority'=>0,'model'=>'IPC-HFW5442E-ZE'],
        '192.168.107.99'=>['role'=>'WORK_RIGHT_CANDIDATE','place'=>'Lagerplatz Werkstatt rechts',
            'priority'=>3,'model'=>'IPC-HFW5442E-ZE'],
        '192.168.107.110'=>['role'=>'TIOC_JV_TERRASSE','place'=>'JV Terrasse',
            'priority'=>8,'model'=>'IPC-PDW3849-A180-AS-PV'],
        '192.168.107.111'=>['role'=>'TIOC_JV_HOF_GARAGE','place'=>'JV Hof Garage',
            'priority'=>8,'model'=>'IPC-PDW3849-A180-AS-PV']
    ];

    /**
     * User-supplied SmartPSS snapshot 2026-10-15 lists all nine cameras as
     * Online, and identifies seven exact HFW5442E-ZE + two PDW3849-A180-AS-PV.
     *
     * Six cameras also have individual TCP/IP dialogs confirming the IP.
     * For Farbcontainer, Torhalle Strasse and neben Torhalle Einfahrt the
     * MODEL is proven by SmartPSS name; the address remains a historical
     * assignment until a camera TCP/IP dialog is supplied.
     *
     * SmartPSS Online does NOT mean reachable from IP-Symcon/SymBox.
     */
    private const SMARTPSS_IP_CONFIRMED=[
        '192.168.107.94'=>true,'192.168.107.95'=>true,
        '192.168.107.96'=>true,'192.168.107.99'=>true,
        '192.168.107.110'=>true,'192.168.107.111'=>true
    ];
    private const SMARTPSS_NAME_CONFIRMED=[
        '192.168.107.92'=>'Farbcontainer',
        '192.168.107.94'=>'Lagerplatz Werkstatt links',
        '192.168.107.95'=>'Lagerplatz JV links',
        '192.168.107.96'=>'Lagerplatz JV rechts',
        '192.168.107.97'=>'neben Torhalle Einfahrt',
        '192.168.107.98'=>'Torhalle Straße',
        '192.168.107.99'=>'Lagerplatz Werkstatt rechts',
        '192.168.107.110'=>'JV Terrasse',
        '192.168.107.111'=>'JV Hof Garage'
    ];

    public static function knownAddresses():array{return array_keys(self::KNOWN);}

    private static function eventSources(array $instances):array
    {
        $sources=[];
        foreach($instances as $id=>$data){
            $var=$data['eventVariableId']??0;
            if(is_int($var)&&$var>0)$sources[]=[
                'instanceId'=>(int)$id,'eventVariableId'=>$var,
                'eventSource'=>'EXISTING_P03_AUX_LAST_IVS_EVENT',
                'cameraEventDataVerified'=>false,
                'peopleCountVerified'=>false
            ];
            if(count($sources)>=8)break;
        }
        return $sources;
    }


    /**
     * @param array<int,array{instanceId:int,ip:string,label:string}> $entries
     */
    public static function evaluate(array $entries,bool $scanLimited=false):array
    {
        $matched=[];
        foreach(array_slice($entries,0,3000) as $e){
            if(!is_array($e)||!is_int($e['instanceId']??null)
                ||$e['instanceId']<=0
                ||!is_string($e['ip']??null)
                ||!filter_var($e['ip'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))continue;
            $ip=$e['ip'];
            if(!str_starts_with($ip,'192.168.107.'))continue;
            $id=$e['instanceId'];
            $matched[$ip]??=[];
            // Never expose credentials or serials, only human-readable names.
            $label=trim((string)($e['label']??''));
            $eventVariableId=$e['eventVariableId']??0;
            $matched[$ip][$id]=[
                'label'=>substr($label,0,100),
                'eventVariableId'=>is_int($eventVariableId)
                    &&$eventVariableId>0?$eventVariableId:0
            ];
        }
        $candidates=[];
        $existing=[];
        $main=[];
        $tioc=[];
        foreach(self::KNOWN as $ip=>$known){
            $type=($known['role']==='WORK_LEFT_P03'||$known['role']==='JV_LEFT_P03')
                ?'P03_MAST':(str_starts_with($known['role'],'TIOC_')
                    ?'TIOC_PANORAMA':'OTHER_IPC');
            $instances=$matched[$ip]??[];
            $labels=array_values(array_filter(array_map(
                static fn($x)=>is_array($x)?(string)($x['label']??''):'',
                $instances
            ),static fn($x)=>$x!==''));
            $roleNameConflict=false;
            if($type==='OTHER_IPC'
                && str_contains($known['role'],'RIGHT')){
                foreach($labels as $name){
                    if(preg_match('/(?:links|\\bleft\\b)/iu',$name)
                        && !preg_match('/(?:rechts|\\bright\\b)/iu',$name)){
                        $roleNameConflict=true;break;
                    }
                }
            }
            $row=[
                'ip'=>$ip,'historicalRole'=>$known['role'],
                'historicalLocation'=>$known['place'],
                'modelFromPreviousInventory'=>$known['model'],
                'modelVerifiedFromSmartPSSScreenshot'=>true,
                'smartPssDeviceName'=>self::SMARTPSS_NAME_CONFIRMED[$ip],
                'smartPssModelSnapshotDate'=>'2026-10-15',
                'smartPssOnlineAtSnapshot'=>true,
                'smartPssOnlineDoesNotProveSymBoxConnectivity'=>true,
                'ipConfirmedBySmartPSSTcpIpDialog'=>
                    isset(self::SMARTPSS_IP_CONFIRMED[$ip]),
                'modelReadFromCameraNow'=>false,
                'configuredSymconInstanceIds'=>array_slice(
                    array_map('intval',array_keys($instances)),0,10),
                'configuredSymconInstanceCount'=>count($instances),
                'configuredInstanceNames'=>array_slice($labels,0,10),
                'roleNameConflictNeedsReview'=>$roleNameConflict,
                'configuredInSymcon'=>count($instances)>0,
                'existingP03AuxEventSources'=>self::eventSources($instances),
                'cameraCurrentlyOnline'=>'UNKNOWN',
                'cameraVideoCoversStreetExit'=>'UNKNOWN',
                'liveCountingMode'=>'NOT_VERIFIED',
                'peopleCountingSupportedByModel'=>($known['model']==='IPC-HFW5442E-ZE')
                    ?true:'NOT_VERIFIED',
                'maySwitchSmartPlan'=>false,
                'usesP03ProtectedMast'=>$type==='P03_MAST',
                'canVerifyTwoSimultaneousHumans'=>false,
                'canCertifyPropertyExit'=>false,
                'sourceType'=>$type,
                'priorityRank'=>$known['priority'],
                'recommendedNextStep'=>$type==='OTHER_IPC'
                    ?'VERIFY_MODEL_SMART_PLAN_AND_FIELD_OF_VIEW_READ_ONLY'
                    :'LEAVE_EXISTING_PRIMARY_FUNCTIONS_UNCHANGED'
            ];
            if($type==='OTHER_IPC')$candidates[]=$row;
            elseif($type==='P03_MAST')$main[]=$row;
            else $tioc[]=$row;
        }
        // Include cameras never previously named but configured in VLAN107.
        foreach($matched as $ip=>$instances){
            if(isset(self::KNOWN[$ip]))continue;
            $candidates[]=[
                'ip'=>$ip,'historicalRole'=>'UNIDENTIFIED_IPC_OR_DEVICE',
                'historicalLocation'=>'UNKNOWN',
                'modelFromPreviousInventory'=>'UNKNOWN',
                'modelReadFromCameraNow'=>false,
                'configuredSymconInstanceIds'=>array_slice(
                    array_map('intval',array_keys($instances)),0,10),
                'configuredSymconInstanceCount'=>count($instances),
                'configuredInSymcon'=>true,
                'existingP03AuxEventSources'=>self::eventSources($instances),
                'cameraCurrentlyOnline'=>'UNKNOWN',
                'cameraVideoCoversStreetExit'=>'UNKNOWN',
                'liveCountingMode'=>'NOT_VERIFIED',
                'peopleCountingSupportedByModel'=>'NOT_VERIFIED',
                'maySwitchSmartPlan'=>false,
                'usesP03ProtectedMast'=>false,
                'canVerifyTwoSimultaneousHumans'=>false,
                'canCertifyPropertyExit'=>false,
                'sourceType'=>'UNIDENTIFIED_DEVICE_VERIFY_FIRST',
                'priorityRank'=>7,
                'recommendedNextStep'=>'VERIFY_IF_IP_IS_A_CAMERA_AND_GET_MODEL'
            ];
        }
        usort($candidates,static fn($a,$b)=>($a['priorityRank']<=>$b['priorityRank'])
            ?:strcmp($a['ip'],$b['ip']));
        return [
            'schema'=>1,'authority'=>'ADDITIONAL_IPC_METADATA_ONLY',
            'auditStatus'=>'INSTANCES_SCANNED_READ_ONLY',
            'ipAddressesAreHistoricalHints'=>true,
            'smartPssScreenshotModelCount'=>9,
            'smartPssSnapshotOnlineDeviceCount'=>10,
            'smartPssHfw5442Count'=>7,
            'smartPssTiocPdW3849Count'=>2,
            'smartPssVtoDevicesExcludedFromCounting'=>1,
            'smartPssIpDialogsConfirmed'=>count(self::SMARTPSS_IP_CONFIRMED),
            'smartPssOtherThreeIpDialogsStillRequired'=>[
                'Farbcontainer','Torhalle Straße','neben Torhalle Einfahrt'
            ],
            'smartPssOnlineDoesNotVerifySymBoxEventStream'=>true,
            'priorityIsByLikelyViewNotAccuracy'=>true,
            'unconfirmedModelMayNotUsePeopleCounting'=>true,
            'sourceConnectedForHumanCounting'=>false,
            'cameraConfigurationsReadOnly'=>true,
            'cameraSettingsChanged'=>false,
            'foreignModulesChanged'=>false,
            'createdSymconObjects'=>false,
            'cameraNetworkConnectionsPerformed'=>false,
            'otherIpcCandidates'=>$candidates,
            'protectedP03Masts'=>$main,
            'existingTiocCameras'=>$tioc,
            'otherIpcCount'=>count($candidates),
            'otherIpcFoundInSymcon'=>count(array_filter($candidates,
                static fn($v)=>$v['configuredInSymcon'])),
            'scanLimited'=>$scanLimited||count($entries)>3000,
            'twoPeopleReliablyCounted'=>false,
            'personCount'=>'UNKNOWN',
            'identityCertified'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'alarmMayBeControlled'=>false,
            'warnings'=>[]
        ];
    }
}
