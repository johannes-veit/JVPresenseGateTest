<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
function strictP03(bool $ok,string $case):void{
    if(!$ok)throw new RuntimeException('STRICT_P03_LINE '.$case);
}
$zone=new DateTimeZone('Europe/Berlin');
$t=(new DateTimeImmutable('2026-10-21 15:00:00',$zone))->getTimestamp();
$proof=static function(int $seconds,string $direction='HOME_TO_LAGER')use($zone,$t):string {
    $time=(new DateTimeImmutable('@'.($t+$seconds)))
        ->setTimezone($zone)->format('Y-m-d H:i:s');
    return '['.$time.'] P03 PROOF VERIFIED '.$direction.' via '
        .($direction==='HOME_TO_LAGER'
            ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV');
};
$baseline=JVP03IncrementalTimeline::evaluate("P03 BASELINE\n",[],$t);
$valid=JVP03IncrementalTimeline::evaluate(
    "P03 BASELINE\n".$proof(20)."\n",
    $baseline['next'],$t+22
);
strictP03($valid['report']['newDirectionProofsThisRead']===1,
    'one exact on-line verified camera proof accepted');
$suffixes=[
    ' EXTRA_OBJECT', ' UNKNOWN_DIRECTION', ' via P03_UNVERIFIED_BACKUP',
    ' HACK', ' 1234', ' #TRACE', ' | P03 DIAG SUCCESS'
];
foreach($suffixes as $i=>$suffix){
    // A previously trusted route must not survive an appended
    // malformed P03 assertion with arbitrary same-line text.
    $raw="P03 BASELINE\n".$proof(20)."\n"
        .$proof(32,'LAGER_TO_HOME').$suffix."\n";
    $bad=JVP03IncrementalTimeline::evaluate(
        $raw,$valid['next'],$t+36);
    strictP03($bad['report']['logContinuity']==='INVALID_PROOF_EPOCH'
        &&$bad['report']['newDirectionProofsThisRead']===0
        &&$bad['report']['verifiedTimeline24hCount']===0
        &&in_array('P03_PROOF_SYNTAX_UNTRUSTED',
            $bad['report']['warnings'],true)
        &&$bad['next']['events']===[],
        'untrusted same-line proof suffix #'.$i.' quarantined');
    $notReplay=JVP03IncrementalTimeline::evaluate(
        $raw,$bad['next'],$t+40);
    strictP03($notReplay['report']['newDirectionProofsThisRead']===0,
        'same malformed P03 log never revives proof #'.$i);
    $restored=JVP03IncrementalTimeline::evaluate(
        $raw.$proof(45)."\n",$bad['next'],$t+50);
    strictP03($restored['report']['newDirectionProofsThisRead']===1
        &&$restored['report']['verifiedTimeline24hCount']===1
        &&!$restored['report']['identityCertified'],
        'only a new strict proof resumes anonymous flow #'.$i);
}
// Un-timestamped plain diagnostic text on its OWN later physical
// line is not part of the verified proof line and must not invalidate
// it or permit an artificial second route. This format occurs when
// local log appenders interleave health text with camera assertions.
$diagnostic="P03 BASELINE\n".$proof(20)."\nLOCAL_DIAG WARN\n";
$withDiag=JVP03IncrementalTimeline::evaluate(
    $diagnostic,$baseline['next'],$t+25);
strictP03($withDiag['report']['newDirectionProofsThisRead']===1
    &&$withDiag['report']['logContinuity']==='OK',
    'standalone non-timestamped diagnostics do not poison exact P03 proof');
$diagnosticAgain=JVP03IncrementalTimeline::evaluate(
    $diagnostic,$withDiag['next'],$t+27);
strictP03($diagnosticAgain['report']['newDirectionProofsThisRead']===0,
    'un-timestamped diagnostic cannot duplicate verified camera proof');
// Safety contract survives malformed-source quarantine.
$fusion=JVResidentEventFusion::evaluate([],[],[],[],
    $withDiag['report'],[],$t+25);
strictP03(!$fusion['report']['mayArmAlarm']
    &&!$fusion['report']['propertyEmptyCertified'],
    'one anonymous camera proof never certifies person/house');
echo "P03 strict physical-line parsing, corrupt suffix and recovery PASS\n";
