<?php
declare(strict_types=1);

/**
 * Pure, bounded pre-upgrade audit of EXISTING IP-Symcon attributes.
 * Never rewrites state or creates an object. A damaged active P03 cursor,
 * resident event chain or route ledger must not be silently overwritten by
 * a "fresh baseline": the caller can fail closed before Refresh writes.
 */
final class JVShadowUpgradePreflight
{
    public const STATE_NAMES=[
        'LocalSensorState','LocalActivityLedger','ResidentVisuState',
        'ResidentPhoneContinuity','ResidentEventFusion','RouteEpisodeLedger',
        'TileResidentState','P03IncrementalTimeline','BoundaryLedger',
        'ExitCandidateInventory','GateSignalLedger','IndoorHintInventory',
        'CarinaGeofencyInventory','AdditionalIPCInventory',
        'SupplementalIpcMetadata'
    ];
    private const CRITICAL=[
        'ResidentEventFusion','RouteEpisodeLedger','P03IncrementalTimeline'
    ];
    private const MAX_BYTES=262144;
    private const MAX_FUTURE_SECONDS=300;

    /** @param array<string,string> $storedAttributeStrings */
    public static function evaluate(array $storedAttributeStrings,int $now):array
    {
        $report=[
            'schema'=>1,'authority'=>'READ_ONLY_SHADOW_UPGRADE_PREFLIGHT',
            'status'=>'FIRST_RUN_BASELINE','safeToRefresh'=>true,
            'checkedAttributes'=>count(self::STATE_NAMES),
            'existingAttributes'=>0,'baselineAttributes'=>0,
            'reviewRequired'=>[],'criticalIssues'=>[],
            'fusionJournalPending'=>0,'ambiguousRouteOutstanding'=>0,
            'p03CursorPresent'=>false,'repairExternalModuleAllowed'=>false,
            'mayErasePreviousEvidence'=>false,
            'mayArmAlarm'=>false,'maySwitchDevices'=>false
        ];
        if($now<=0){
            $report['criticalIssues'][]='INVALID_SYSTEM_TIME';
            return self::finish($report);
        }
        $states=[];
        foreach(self::STATE_NAMES as $name){
            $raw=$storedAttributeStrings[$name]??null;
            if($raw===null||$raw===''||$raw==='{}'){
                $report['baselineAttributes']++;
                continue;
            }
            if(!is_string($raw)||strlen($raw)>self::MAX_BYTES){
                self::issue($report,$name,'INVALID_OR_OVERSIZE_ATTRIBUTE');
                continue;
            }
            $decoded=json_decode($raw,true,64);
            if(!is_array($decoded)||array_is_list($decoded)||$decoded===[]){
                self::issue($report,$name,'UNREADABLE_STATE_JSON');
                continue;
            }
            $report['existingAttributes']++;
            $states[$name]=$decoded;
            if(in_array($name,self::CRITICAL,true)
                &&isset($decoded['schema'])&&$decoded['schema']!==1){
                self::issue($report,$name,'UNSUPPORTED_CRITICAL_SCHEMA');
            }
        }
        $fusion=$states['ResidentEventFusion']??null;
        if(is_array($fusion)){
            $p=$fusion['p03']??null;
            if(!is_array($p))self::issue($report,'ResidentEventFusion','P03_JOURNAL_NOT_ARRAY');
            else {
                $pending=$p['unackedRouteProofs']??[];
                if(!is_array($pending)||!array_is_list($pending)
                    ||count($pending)>128){
                    self::issue($report,'ResidentEventFusion','P03_JOURNAL_INVALID');
                }else{
                    $report['fusionJournalPending']=count($pending);
                    foreach($pending as $row){
                        if(!is_array($row)||!is_int($row['at']??null)
                            ||$row['at']<1||$row['at']>$now+self::MAX_FUTURE_SECONDS
                            ||!in_array($row['direction']??null,
                                ['HOME_TO_LAGER','LAGER_TO_HOME'],true)
                            ||($row['source']??null)!=='P03_TWO_MAST_VERIFIED'){
                            self::issue($report,'ResidentEventFusion','P03_PENDING_PROOF_INVALID');
                            break;
                        }
                    }
                }
            }
            foreach(['johannes','carina'] as $person){
                $phone=$fusion['people'][$person]??null;
                if($phone===null)continue; // compatible legacy baseline
                if(!is_array($phone)){
                    self::issue($report,'ResidentEventFusion','PERSON_RECORD_INVALID');
                    break;
                }
                $events=$phone['eventHistory']??[];
                if(!is_array($events)||!array_is_list($events)
                    ||count($events)>24){
                    self::issue($report,'ResidentEventFusion','PHONE_HISTORY_INVALID');
                    break;
                }
            }
        }
        $route=$states['RouteEpisodeLedger']??null;
        if(is_array($route)){
            $open=$route['open']??[];
            $outstanding=$route['ambiguousOutstanding']??0;
            if(!is_array($open)||!array_is_list($open)
                ||count($open)>8||!is_int($outstanding)
                ||$outstanding<0||$outstanding>8
                ||($outstanding>0&&count($open)>0)){
                self::issue($report,'RouteEpisodeLedger','ROUTE_QUARANTINE_INVALID');
            }else{
                $report['ambiguousRouteOutstanding']=$outstanding;
            }
        }
        $timeline=$states['P03IncrementalTimeline']??null;
        if(is_array($timeline)){
            $report['p03CursorPresent']=($timeline['initialized']??false)===true;
            $events=$timeline['events']??[];
            if(!is_array($events)||!array_is_list($events)
                ||count($events)>256
                ||(($timeline['initialized']??false)===true
                    &&!is_string($timeline['lastRaw']??null))){
                self::issue($report,'P03IncrementalTimeline','P03_CURSOR_INVALID');
            }
        }
        return self::finish($report);
    }

    private static function issue(array &$report,string $name,string $code):void
    {
        $label=$name.':'.$code;
        $report['reviewRequired'][$label]=true;
        if(in_array($name,self::CRITICAL,true))
            $report['criticalIssues'][$label]=true;
    }

    private static function finish(array $report):array
    {
        $report['reviewRequired']=array_slice(
            array_keys($report['reviewRequired']),0,12);
        $report['criticalIssues']=array_slice(
            array_keys($report['criticalIssues']),0,6);
        $report['safeToRefresh']=$report['criticalIssues']===[];
        $report['status']=!$report['safeToRefresh']
            ?'BLOCKED_PRESERVE_CRITICAL_STATE'
            :($report['reviewRequired']!==[]?'REVIEW_NONCRITICAL_STATE'
                :($report['existingAttributes']===0
                    ?'FIRST_RUN_BASELINE':'EXISTING_STATE_VALIDATED'));
        return $report;
    }
}
