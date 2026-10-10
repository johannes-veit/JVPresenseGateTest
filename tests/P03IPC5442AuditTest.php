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

echo "P03 IPC5442 READ-ONLY MODEL AUDIT UNIT TEST PASSED\n";
