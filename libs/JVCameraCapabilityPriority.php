<?php
declare(strict_types=1);

/**
 * Installed camera roles/capability-based precedence, not a people-count.
 * IPC-HFW5442E-ZE can run hardware People Counting but IVS, Face, and People
 * Counting are SWITCHABLE smart resources per Dahua datasheet. Changing the
 * active Smart Plan can remove P03 IVS direction evidence. Never configure it.
 * TiOC JV Terrasse and JV Hof Garage were previously identified as
 * IPC-PDW3849-A180-AS-PV, a 2x4MP stitched 180-degree TiOC Duo.
 * Official datasheet lists SMD 4.0, IVS tripwire/intrusion human/vehicle,
 * EPTZ; no people-counting function is advertised. Wide coverage is NOT a
 * validated two-person counter. Actual field of view at each location may vary.
 */
final class JVCameraCapabilityPriority
{
    public static function evaluate(array $p03Timeline,array $p03Live,
        array $streetLearning,array $optionalCountTelemetry=[]):array
    {
        $streetMapping=($streetLearning['directionMappingCertified']??false)===true
            && in_array($streetLearning['out']??null,['RightToLeft','LeftToRight'],true)
            && in_array($streetLearning['in']??null,['RightToLeft','LeftToRight'],true)
            && $streetLearning['out']!==$streetLearning['in'];
        $liveDirection=($p03Timeline['authority']??'')==='P03_LIVE_PROOFS_DIRECTION_ONLY'
            && in_array(($p03Timeline['logContinuity']??null),
                ['OK','TAIL_OVERLAP_AFTER_ROTATION'],true)
            && !empty($p03Timeline['recentDirectionProofs']);
        // The P03ProofState is not a person counter or a person-tracking stream.
        $proofState=(string)($p03Live['proofState']??'');
        $p03Blocked=str_contains($proofState,'BLOCKIERT')
            ||str_contains($proofState,'abgebrochen');
        $numb=($optionalCountTelemetry['authority']??'')==='DAHUA_NUMBERSTAT_DIAGNOSTIC_ONLY';
        return [
            'schema'=>1,'authority'=>'CAMERA_CAPABILITY_PRIORITY_SHADOW',
            'cameraPriorityForHouseToWork'=>[
                'JV_LEFT','WORK_LEFT','TIOC_TERRASSE_BACKUP_ONLY'
            ],
            'cameraPriorityForStreet'=>[
                'WORK_LEFT_IPC_STREET_IVS','NO_VERIFIED_SECOND_STREET_COUNTER'
            ],
            'cameraPriorityForIndividualCounting'=>[
                'DEDICATED_IPC_HFW5442E_ZE_PEOPLE_COUNTING_IF_VERIFIED',
                'NO_INSTALLED_TIOC_COUNTING_MODE_ADVERTISED'
            ],
            // Panorama coverage is useful when two people walk shoulder to
            // shoulder, but is NOT individual counting or identity evidence.
            // Require the real camera image to cover the relevant crossing.
            'cameraPriorityForPossibleGroupInFrame'=>[
                'JV_TERRASSE_TIOC_PANORAMA_IF_PATH_COVERED',
                'JV_HOF_GARAGE_TIOC_PANORAMA_IF_PATH_COVERED',
                'JV_LEFT_WORK_LEFT_DIRECTION_CROSS_CHECK'
            ],
            'installedCameras'=>[
                'JV_LEFT'=>[
                    'model'=>'IPC-HFW5442E-ZE',
                    'role'=>'HOME_LAGER_FIRST_MAST',
                    'technology'=>'WizMind 5',
                    'ivsSupportsHumanClassification'=>true,
                    'peopleCountingHardwareCapable'=>true,
                    'peopleCountingSmartPlanActive'=>'NOT_VERIFIED',
                    'simultaneousIVSAndPeopleCountingProven'=>false,
                    'priority'=>'HIGH_DIRECTION_NOT_HEADCOUNT'
                ],
                'WORK_LEFT'=>[
                    'model'=>'IPC-HFW5442E-ZE',
                    'role'=>'LAGER_AND_STREET_IVS',
                    'technology'=>'WizMind 5',
                    'ivsSupportsHumanClassification'=>true,
                    'peopleCountingHardwareCapable'=>true,
                    'peopleCountingSmartPlanActive'=>'NOT_VERIFIED',
                    'simultaneousIVSAndPeopleCountingProven'=>false,
                    'priority'=>'HIGHEST_STREET_DIRECTION_NOT_HEADCOUNT'
                ],
                'JV_TERRASSE'=>[
                    'model'=>'IPC-PDW3849-A180-AS-PV',
                    'role'=>'HOME_LAGER_OPTIONAL_BACKUP',
                    'technology'=>'TiOC Duo WizSense',
                    'stitchedPanoramaHorizontalDegrees'=>180,
                    'dualImageSensors'=>2,
                    'smdVersion'=>'4.0',
                    'ivsSupportsHumanClassification'=>true,
                    'ivsHumanVehicleRules'=>['TRIPWIRE','INTRUSION'],
                    'eptzTracksIntelligentAlarmTargets'=>true,
                    'independentPeopleCountingAdvertised'=>false,
                    'groupOfTwoIndividuallyDetected'=>'NOT_VERIFIED',
                    'installedViewActuallyCoversCrossing'=>'NOT_VERIFIED',
                    'priority'=>'PANORAMA_GROUP_HINT_AND_BACKUP_DIRECTION_NOT_HEADCOUNT'
                ],
                'JV_HOF_GARAGE'=>[
                    'model'=>'IPC-PDW3849-A180-AS-PV',
                    'role'=>'OUTSIDE_HOF_GARAGE_PANORAMA_CONTEXT_ONLY',
                    'technology'=>'TiOC Duo WizSense',
                    'stitchedPanoramaHorizontalDegrees'=>180,
                    'dualImageSensors'=>2,
                    'smdVersion'=>'4.0',
                    'ivsSupportsHumanClassification'=>true,
                    'ivsHumanVehicleRules'=>['TRIPWIRE','INTRUSION'],
                    'eptzTracksIntelligentAlarmTargets'=>true,
                    'independentPeopleCountingAdvertised'=>false,
                    'groupOfTwoIndividuallyDetected'=>'NOT_VERIFIED',
                    'installedViewActuallyCoversCrossing'=>'NOT_VERIFIED',
                    'priority'=>'ADDITIONAL_PANORAMA_CONTEXT_ONLY_NOT_P03_PRIMARY'
                ]
            ],
            'streetDirectionMappingKnown'=>$streetMapping,
            'streetOutCameraDirection'=>$streetMapping?$streetLearning['out']:null,
            'streetInCameraDirection'=>$streetMapping?$streetLearning['in']:null,
            'liveStreetPeopleCounterConnected'=>false,
            'liveStreetExitPersonCount'=>'UNKNOWN',
            'houseToWorkLiveDirectionObserved'=>$liveDirection,
            'p03LiveProofStateBlocked'=>$p03Blocked,
            'numberStatTelemetryStatus'=>$numb
                ?($optionalCountTelemetry['status']??'UNKNOWN'):'NOT_CONNECTED',
            'numberStatTotalsAreNotPerPersonEvents'=>true,
            'numberStatEventMayBeThresholdTriggered'=>true,
            'crossLineEventIdIsNotPersonIdentity'=>true,
            'crossLineObjectIdIsNotCrossCameraIdentity'=>true,
            'smartPlanMustRemainUnchanged'=>true,
            'wouldNeedSeparateCountingMode'=>true,
            'recommendSpareIPCForDedicatedCounting'=>true,
            'physicalTwoPeopleRecognitionVerified'=>false,
            'panoramaCameraDoesNotCertifyExactlyTwoPeople'=>true,
            'exactPeopleCount'=>'UNKNOWN',
            'identityCertified'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'alarmMayBeControlled'=>false,
            'warnings'=>$p03Blocked?['P03_MAST_STREAM_INTERRUPTED']:[]
        ];
    }
}
