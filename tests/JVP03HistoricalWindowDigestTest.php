<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03HistoricalWindowDigest.php';
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
function digestCheck(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('HISTORICAL_P03_DIGEST '.$message);
}
$tz=new DateTimeZone('Europe/Berlin');
$t=(new DateTimeImmutable('2026-10-20 11:00:00',$tz))->getTimestamp();
$base=JVP03IncrementalTimeline::evaluate('',[],$t-10);
$raw=[];
for($i=0;$i<28;$i++){
    $at=$i===0?$t+60:$t+1800+($i-1)*14;
    $atStr=(new DateTimeImmutable('@'.$at))
        ->setTimezone($tz)->format('Y-m-d H:i:s');
    $dir=$i%2===0?'HOME_TO_LAGER':'LAGER_TO_HOME';
    $route=$dir==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV';
    $raw[]='['.$atStr.'] P03 PROOF VERIFIED '.$dir.' via '.$route;
}
// Generate the 28 synthetic events under continuous hourly testing.
// Catching up after an unobserved hour is intentionally NOT allowed.
$rawText='';$last=$t-10;$prev=$base['next'];$accepted=0;
foreach($raw as $i=>$line){
    $at=$i===0?$t+60:$t+1800+($i-1)*14;
    while($at-$last>900){
        $last+=600;
        $tick=JVP03IncrementalTimeline::evaluate($rawText,$prev,$last);
        $prev=$tick['next'];
    }
    $rawText.=($rawText===''?'':"\n").$line;
    $tick=JVP03IncrementalTimeline::evaluate($rawText,$prev,$at);
    $accepted+=$tick['report']['newDirectionProofsThisRead'];
    $prev=$tick['next'];$last=$at;
}
$end=$t+3900;
while($end-$last>900){
    $last+=600;
    $tick=JVP03IncrementalTimeline::evaluate($rawText,$prev,$last);
    $prev=$tick['next'];
}
$p03=JVP03IncrementalTimeline::evaluate(
    $rawText,$prev,$end)['report'];
digestCheck($accepted===28
    &&$p03['newDirectionProofsThisRead']===0,
    'all synthetic P03 proofs arrived under continuous SHADOW observation');
$r=JVP03HistoricalWindowDigest::summarize($p03,$t+3900);
$all=implode("\n",$r['hourlyLines']);
digestCheck($r['status']==='RETAINED_ANONYMOUS_HISTORY_24H'
    &&$r['sourceCount']===28
    &&$r['fiveMinuteWindows']>1
    &&str_contains($all,'11:00 O1/I0')
    &&str_contains($all,'11:30')
    &&count($p03['recentDirectionProofs'])===15,
    'all 28 anonymous stored proofs included even though only 15 displayed');
digestCheck(!$r['personCountCertified']
    &&!$r['identifiedHumanCertified']
    &&!$r['vehicleCertified']
    &&!$r['mayArmAlarm'],
    'camera 5min bucket cannot identify/count humans, cars or enable alarms');
foreach($r['hourlyLines'] as $line)
    digestCheck(strlen('P03-5MIN/24h: '.$line)<=230,
        'each entire anonymous digest line fits existing sanitized text limit');
$malformed=$p03;
$malformed['verifiedDirectionTimeline24h'][]=[
    'at'=>$t+500,'direction'=>'HOME_TO_LAGER',
    'source'=>'UNVERIFIED_FAKE_CAMERA'
];
$malformed['verifiedTimeline24hCount']++;
$bad=JVP03HistoricalWindowDigest::summarize($malformed,$t+3900);
digestCheck($bad['sourceCount']===28
    &&!str_contains(implode("\n",$bad['hourlyLines']),'11:05 O1/I0'),
    'unverified source cannot inject a synthetic camera corridor proof');
$stale=$p03;
$stale['verifiedTimeline24hCount']=5;
digestCheck(JVP03HistoricalWindowDigest::summarize($stale,$t+3900)
    ['status']==='FULL_HISTORY_NOT_EXPOSED',
    'incorrect digest source count has no apparent complete history');
$baseline=$p03;
$baseline['baselineOnly']=true;
$baseline['logContinuity']='BASELINE_ONLY';
$b=JVP03HistoricalWindowDigest::summarize($baseline,$t+3900);
digestCheck($b['status']==='HISTORIC_BASELINE_NOT_LIVE_PROOF'
    &&$b['historyMayBeIncomplete'],
    'first imported P03 log is visible as history but never called live proof');
$truncated=$p03;
$truncated['verifiedTimelineTruncated']=true;
digestCheck(JVP03HistoricalWindowDigest::summarize(
    $truncated,$t+3900)['status']==='BOUNDED_HISTORY_NOT_COMPLETE',
    'bounded 256 or lost log history cannot assert absent earlier passage');
$empty=JVP03HistoricalWindowDigest::summarize([],$t+3900);
digestCheck($empty['status']==='SOURCE_UNVERIFIED',
    'missing P03 authority cannot pass historical source checks');
echo "P03 5-minute historical digest PASS\n";
