<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/libs/P03IPC5442Audit.php';

function expect5442(bool $condition, string $message):void {
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
    echo 'OK: '.$message."\n";
}

$md="MotionDetect[0].Enable=true\r\n"
   ."MotionDetect[0].Region[0].Threshold=20\r\n"
   ."MotionDetect[0].EventHandler.TimeSection[0][1]=1 17:00:00-23:59:59\r\n"
   ."MotionDetect[0].Password=secret-cam-credentials\r\n";
$a=P03IPC5442Audit::summarizeConfig('MotionDetect',$md);
$text=implode("\n",$a);
expect5442(str_contains($text,'MotionDetect[0].Region[0].Threshold=20'),
    '5442 MD motion detection ROI/threshold is visible');
expect5442(str_contains($text,'TimeSection[0][1]'),
    '5442 camera arming schedule readback is visible');
expect5442(!str_contains($text,'secret-cam-credentials'),
    'camera credentials cannot escape into report');
expect5442(str_contains($text,'MODELL-PRÜFPUNKT'),
    'model-focused MD requirements are explicit');
$ivs="VideoAnalyseRule[0][3].Enable=true\r\n"
    ."VideoAnalyseRule[0][3].Config.DetectRegion[0].Points[0]=[100,200]\r\n"
    ."VideoAnalyseRule[0][3].Config.Filter.Human=true\r\n";
$b=P03IPC5442Audit::summarizeConfig('VideoAnalyseRule',$ivs);
expect5442(str_contains(implode("\n",$b),'Config.Filter.Human=true'),
    'read-only IVS Human object filter visible');
expect5442(str_contains(implode("\n",$b),'Bereich/Polygon-Felder='),
    'IVS configured geometry count visible');
expect5442(P03IPC5442Audit::wireHeader('SmartMotionHuman','Start','0',7)
    ==='Dahua Code=SmartMotionHuman;action=Start;index=0;gesamt=7',
    'model 5442 raw SmartMotionHuman header is redacted/minimal');
expect5442(P03IPC5442Audit::wireHeader('<script>','Start','0',8)==='',
    'unsafe raw stream header rejected');
// 2.840 CGI configManager returns the "table." prefix on many builds.
// Any parser that requires bare "MotionDetect[0]" incorrectly reports that
// all camera AI/IVS settings are absent.
$realCgi = "table.MotionDetect[0].Enable=true\r\n"
    . "table.MotionDetect[0].EventHandler.TimeSection[0][1]=1 17:00:00-23:59:59\r\n"
    . "table.MotionDetect[0].Region[0].Threshold=30\r\n";
$actual=P03IPC5442Audit::summarizeConfig('MotionDetect',$realCgi);
$actualText=implode("\n",$actual);
expect5442(str_contains($actualText,'MotionDetect[0].Enable=true'),
    '2.840 table.-prefixed Dahua CGI output accepted');
expect5442(str_contains($actualText,'TimeSection[0][1]'),
    'prefixed AI motion arming schedule remains visible');
expect5442(str_contains($actualText,'Region[0].Threshold=30'),
    'prefixed ROI field remains visible');
$bare="table.SmartMotionDetect.Enable=true\r\n"
    . "table.SmartMotionDetect.ObjectType.Human=true\r\n";
$bareFields=implode("\n",P03IPC5442Audit::summarizeConfig('SmartMotionDetect',$bare));
expect5442(str_contains($bareFields,'SmartMotionDetect.Enable=true')
    && str_contains($bareFields,'SmartMotionDetect.ObjectType.Human=true'),
    'module-wide Dahua SMD config without [0] index supported');
$ivsPrefixed="table.VideoAnalyseRule[0][3].Enable=true\r\n"
    . "table.VideoAnalyseRule[0][3].Config.DetectRegion[0].Points[0]=[130,230]\r\n";
$ivsFields=implode("\n",P03IPC5442Audit::summarizeConfig('VideoAnalyseRule',$ivsPrefixed));
expect5442(str_contains($ivsFields,'VideoAnalyseRule[0][3].Enable=true')
    && str_contains($ivsFields,'DetectRegion[0].Points[0]'),
    '2.840 prefixed IVS rule enabled flag and region polygon displayed');

// Actual 19:50 report: the first 48 of ~400 IVS fields are
// HeatMap[0][0] scheduling entries. P03's important [0][3] must
// ALWAYS appear regardless of input order or table size.
$manyFields = [];
$manyFields[] = 'table.VideoAnalyseRule[0][0].Class=HeatMap';
$manyFields[] = 'table.VideoAnalyseRule[0][0].Enable=false';
for ($day=0; $day<7; $day++) {
    for ($slot=0; $slot<6; $slot++) {
        $manyFields[] = "table.VideoAnalyseRule[0][0].EventHandler.TimeSection[$day][$slot]=0 00:00:00-23:59:59";
    }
}
for ($i=0; $i<100; $i++) {
    $manyFields[] = "table.VideoAnalyseRule[0][1].EventHandler.Noise[$i]=0";
}
$manyFields[] = 'table.VideoAnalyseRule[0][1].Enable=false';
$manyFields[] = 'table.VideoAnalyseRule[0][1].Type=CrossLineDetection';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Class=Normal';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Enable=true';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Type=CrossRegionDetection';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Name=P03_JV_LEFT_HUMAN';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Id=4';
$manyFields[] = 'table.VideoAnalyseRule[0][3].ObjectTypes[0]=Human';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Config.Direction=Both';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Config.Action[0]=Appear';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Config.Action[1]=Cross';
for($point=0; $point<4; $point++) {
    $manyFields[] = "table.VideoAnalyseRule[0][3].Config.DetectRegion[$point][0]=".(256+$point*200);
    $manyFields[] = "table.VideoAnalyseRule[0][3].Config.DetectRegion[$point][1]=".(256+$point*200);
}
$manyFields[] = 'table.VideoAnalyseRule[0][3].EventHandler.TimeSection[0][0]=1 00:00:00-23:59:59';
$manyFields[] = 'table.VideoAnalyseRule[0][3].EventHandler.TimeSection[0][1]=0 00:00:00-23:59:59';
$manyFields[] = 'table.VideoAnalyseRule[0][3].Password=never-expose';
$payload = implode("\r\n", $manyFields);
$oldGeneric = implode("\n", P03IPC5442Audit::summarizeConfig('VideoAnalyseRule', $payload, 48));
expect5442(!str_contains($oldGeneric, 'P03_JV_LEFT_HUMAN'),
    'reproduce production defect: first-48 IVS output hides P03 target');
$focus = implode("\n", P03IPC5442Audit::summarizeRulePriorities(
    $payload, 3, 'P03_JV_LEFT_HUMAN', 4));
expect5442(str_contains($focus, 'Regel [0][0]: Enable=false')
    && str_contains($focus, 'Regel [0][3]: Enable=true'),
    'every index summarized irrespective of raw CGI first-48 limit');
expect5442(str_contains($focus, '=== DETAIL P03-REGEL [0][3] ==='),
    'P03 target index 3 always gets own detailed readback');
expect5442(str_contains($focus, 'ObjectTypes[0]=Human')
    && str_contains($focus, 'Config.DetectRegion[0][0]=256'),
    'actual human object filter and region coordinates shown');
expect5442(str_contains($focus, 'Config.Action[0]=Appear')
    && str_contains($focus, 'Config.Direction=Both'),
    'exact IVS actions and direction preserved');
expect5442(str_contains($focus, 'ABGLEICH: RuleName=PASST | Enable=JA | Type=PASST')
    && str_contains($focus, 'ABGLEICH: RuleID=PASST'),
    'read-only rule identity, ID and enabled/IVS semantics verified');
expect5442(str_contains($focus,'aktiv markierte Zeitfenster=1'),
    'actual rule-specific timeslots counted instead of HeatMap schedules');
expect5442(!str_contains($focus, 'never-expose'),
    'no credentials or raw sensitive fields in focused diagnosis');

$disabled = str_replace('VideoAnalyseRule[0][3].Enable=true',
    'VideoAnalyseRule[0][3].Enable=false',$payload);
$focusDisabled = implode("\n", P03IPC5442Audit::summarizeRulePriorities(
    $disabled, 3, 'P03_JV_LEFT_HUMAN', 4));
expect5442(str_contains($focusDisabled, 'Enable=NEIN'),
    'disabling P03 IVS rule is visible, never counted as successful detection');
$noIVS = "table.VideoAnalyseRule[0][0].Class=HeatMap\r\n"
    . "table.VideoAnalyseRule[0][0].Enable=false\r\n";
$missing = implode("\n", P03IPC5442Audit::summarizeRulePriorities(
    $noIVS, 3, 'P03_JV_LEFT_HUMAN', 4));
expect5442(str_contains($missing, 'NICHT GEFUNDEN'),
    'missing CrossRegion rule flagged without altering the camera');
$wrongIndex = str_replace('[0][3]', '[0][5]', $payload);
$recovered = implode("\n", P03IPC5442Audit::summarizeRulePriorities(
    $wrongIndex, 3, 'P03_JV_LEFT_HUMAN', 4));
expect5442(str_contains($recovered,'DETAIL P03-REGEL [0][5]')
    && str_contains($recovered,'Index abweichend'),
    'migrated rule found by exact name if index differs');

// IPC-HFW5442E-ZE read-only resource/config check, including 2.840
// table-prefix CGI, alternative modes and secret field suppression.
$resources="table.VideoAnalyseModule[0][0].Type=Normal\r\n"
    . "table.VideoAnalyseModule[0][0].Sensitivity=10\r\n"
    . "table.VideoAnalyseModule[0][1].Type=NumberStat\r\n"
    . "table.VideoAnalyseModule[0][1].Enable=false\r\n"
    . "table.VideoAnalyseModule[0][1].CredentialSecret=hidden-secret\r\n";
$resourceSummary=implode("\n",P03IPC5442Audit::summarizeModuleResources($resources));
expect5442(str_contains($resourceSummary,'VideoAnalyseModule[0][0].Type=Normal')
    && str_contains($resourceSummary,'VideoAnalyseModule[0][1].Type=NumberStat'),
    'both configured Dahua 5442 intelligence modules visible via SymBox');
expect5442(str_contains($resourceSummary,'VideoAnalyseModule[0][1].Enable=false'),
    'module-specific activation state exposed without inferring smart plan selection');
expect5442(!str_contains($resourceSummary,'hidden-secret'),
    'read-only module probe does not expose camera credentials');
expect5442(str_contains($resourceSummary,'nicht, dass BEIDE Algorithmen gleichzeitig laufen'),
    'diagnostic avoids false inference from merely saved rules');
expect5442(str_contains(implode("\n",
    P03IPC5442Audit::summarizeModuleResources("ERROR: unsupported\r\n")),
    'KEIN NACHWEIS'),
    'unsupported CGI table reported as absent, not as successful AI');
echo "P03 IPC5442 READ-ONLY MODEL AUDIT UNIT TEST PASSED\n";
