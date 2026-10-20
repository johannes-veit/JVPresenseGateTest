<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowIntegrityAudit.php';
require_once dirname(__DIR__).'/libs/JVPresenceCommissioningGate.php';
function auditBridge86(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('SHADOW_CROSS_GATE_BRIDGE '.$why);
}
$t=1900000000;
$fusion=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>['eventHistory'=>[]],
        'carina'=>['eventHistory'=>[]]],
    'p03'=>['logContinuity'=>'OK','logInterrupted'=>false,
        'routeDeliveryProofs'=>[], 'unackedRouteProofCount'=>0]
];
$routes=['schema'=>1,'authority'=>'ANONYMOUS_ROUTE_EPISODES_READ_ONLY',
    'openOutgoingPassages'=>0,'ambiguousOutstanding'=>0,'lastEpisode'=>null];
$journey=['schema'=>1,
    'authority'=>'READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS',
    'candidateCount'=>0,'phoneCandidates'=>[],'candidatePerson'=>null];
$readiness=['schema'=>1,
    'authority'=>'READ_ONLY_PRESENCE_EVIDENCE_READINESS',
    'p03Mode'=>'P03_INTENTIONALLY_OFF_NO_NEW_TRANSFERS',
    'mastStreams'=>'BOTH_OBSERVERS_LIVE_NOT_PROOF_OF_TRANSITION',
    'roomMotionCoverage'=>'FIFTEEN_GUS_SOURCES_VERIFIED_ANONYMOUS'];
$cross=['schema'=>1,
    'authority'=>'SHADOW_CROSS_SOURCE_INVARIANT_AUDIT_NO_ACTION',
    'status'=>'CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY',
    'inputDataRejected'=>false,'violations'=>[]];
$ok=JVShadowIntegrityAudit::evaluate(
    $fusion,$routes,$journey,$readiness,$t,$cross);
auditBridge86($ok['status']==='CONSISTENT'
    &&$ok['issues']===[]&&!$ok['mayArmAlarm'],
    'valid end-to-end consistency stays diagnostic not action');
$incomplete=$cross;
$incomplete['status']='SOURCE_INCOMPLETE_NO_ACTION';
$incompleteGate=JVShadowIntegrityAudit::evaluate(
    $fusion,$routes,$journey,$readiness,$t,$incomplete);
auditBridge86($incompleteGate['status']==='CONSISTENT',
    'an intentionally absent P03/phone source is an unmet task not corruption');
$bad=$cross;
$bad['status']='CROSS_SOURCE_CONTRADICTION_NO_ACTION';
$bad['inputDataRejected']=true;
$bad['violations']=['P03_LATEST_PROOF_NOT_QUALIFIED'];
$quarantined=JVShadowIntegrityAudit::evaluate(
    $fusion,$routes,$journey,$readiness,$t,$bad);
auditBridge86($quarantined['status']==='REVIEW_REQUIRED'
    &&in_array('CROSS_SOURCE_INTEGRITY_CONTRADICTION',
        $quarantined['issues'],true)
    &&!$quarantined['mayArmAlarm'],
    'cross-source contradiction propagates to established integrity gate');
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'people'=>[]];
$work=['schema'=>1,
    'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'people'=>[]];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0];
$commission=JVPresenceCommissioningGate::evaluate(
    $wlan,$fusion,$work,$readiness,$boundary,[],[],
    $quarantined,$t
);
auditBridge86($commission['state']==='SOURCE_CONSISTENCY_REVIEW'
    &&!$commission['canObserveInShadowReadOnly']
    &&in_array('REVIEW_SHADOW_INTEGRITY_CODES',
        $commission['actions'],true)
    &&!$commission['mayArmAlarm']
    &&!$commission['maySwitchDevices'],
    'real commissioning state blocks review while offering specific diagnostic action');
$forged=$cross;$forged['violations']=['UNAUTHORIZED_CONTROL'];
$rejected=JVShadowIntegrityAudit::evaluate(
    $fusion,$routes,$journey,$readiness,$t,$forged);
auditBridge86(in_array('CROSS_SOURCE_AUDIT_STATUS_UNTRUSTED',
        $rejected['issues'],true),
    'claimed clean audit cannot hide nonempty contradiction list');
$noAudit=JVShadowIntegrityAudit::evaluate(
    $fusion,$routes,$journey,$readiness,$t);
auditBridge86($noAudit['status']==='CONSISTENT',
    'legacy five-argument call remains backward compatible');
echo "SHADOW 0.8.86: cross-source integrity -> commissioning and safety review bridge PASS\n";
