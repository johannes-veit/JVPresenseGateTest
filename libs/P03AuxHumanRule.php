<?php

declare(strict_types=1);

/**
 * Builds and validates the P03-owned auxiliary Human IVS proof rule.
 *
 * The two IPC-HFW5442E-ZE cameras used for JV_LEFT / WORK_LEFT were observed
 * to publish only VideoMotion/VideoMotionInfo although SMD was configured for
 * Human. A dedicated CrossRegionDetection rule therefore provides a native
 * IVS Human event that the independent P03 auxiliary observers consume.
 */
final class P03AuxHumanRule
{
    /**
     * Nearly full-frame region in Dahua's normalized 0..8191 coordinate space.
     * A small inset avoids firmware edge/pathological polygon handling.
     */
    public const REGION = [
        [256, 256],
        [7935, 256],
        [7935, 7935],
        [256, 7935],
    ];

    /** @param array<string,mixed> $eventHandler */
    public static function build(string $name, int $id, array $eventHandler): array
    {
        $eventHandler = self::prepareEventHandler($eventHandler);

        return [
            'Class' => 'Normal',
            'Config' => [
                // Dahua CrossRegionDetection is not functional without an
                // explicit Action. "Cross"+"Appear" with Enter mirrors real
                // Dahua IVS rules and detects a person whether tracking starts
                // inside the region or the target crosses into it.
                'Action' => ['Cross', 'Appear'],
                'Direction' => 'Enter',
                'DetectRegion' => self::REGION,
                'AccuracySnap' => [
                    'HumanBody' => true,
                    'Normal' => true,
                ],
                'MaxTargets' => 100,
                'MinDuration' => 1,
                'MinTargets' => 1,
                'ReportInterval' => 1,
                'Sensitivity' => 10,
                'TrackDuration' => 30,
                'SizeFilter' => [
                    'CalibrateBoxs' => [
                        [
                            'CenterPoint' => [4096, 4096],
                            'Ratio' => 1,
                        ],
                    ],
                    'MaxSize' => [8191, 8191],
                    'MinSize' => [0, 0],
                    'Type' => 'ByLength',
                ],
            ],
            'Enable' => true,
            'EventHandler' => $eventHandler,
            'Id' => $id,
            'Name' => $name,
            'ObjectTypes' => ['Human'],
            'PtzPresetId' => 0,
            'TrackEnable' => false,
            'Type' => 'CrossRegionDetection',
        ];
    }

    /** @param array<string,mixed> $rule */
    public static function matches(array $rule, string $name): bool
    {
        $objects = $rule['ObjectTypes'] ?? [];
        $region = $rule['Config']['DetectRegion'] ?? null;
        $actions = $rule['Config']['Action'] ?? [];
        $direction = $rule['Config']['Direction'] ?? null;
        $config = is_array($rule['Config'] ?? null) ? $rule['Config'] : [];
        $calibrate = $config['SizeFilter']['CalibrateBoxs'][0] ?? null;
        $min = $config['SizeFilter']['MinSize'] ?? null;
        $max = $config['SizeFilter']['MaxSize'] ?? null;
        $accuracySnap = is_array($config['AccuracySnap'] ?? null) ? $config['AccuracySnap'] : [];
        $eventHandler = is_array($rule['EventHandler'] ?? null) ? $rule['EventHandler'] : [];

        return strcasecmp((string) ($rule['Name'] ?? ''), $name) === 0
            && strcasecmp((string) ($rule['Type'] ?? ''), 'CrossRegionDetection') === 0
            && (($rule['Enable'] ?? false) === true)
            && strcasecmp((string) ($rule['Class'] ?? ''), 'Normal') === 0
            && is_array($objects)
            && in_array('Human', $objects, true)
            && is_array($region)
            && $region === self::REGION
            && is_array($actions)
            && in_array('Appear', $actions, true)
            && in_array('Cross', $actions, true)
            && strcasecmp((string) $direction, 'Enter') === 0
            && (($accuracySnap['HumanBody'] ?? false) === true)
            && (($accuracySnap['Normal'] ?? false) === true)
            && (int) ($config['MinDuration'] ?? -1) === 1
            && (int) ($config['MinTargets'] ?? -1) === 1
            && (int) ($config['MaxTargets'] ?? -1) === 100
            && (int) ($config['ReportInterval'] ?? -1) === 1
            && (int) ($config['Sensitivity'] ?? -1) === 10
            && (int) ($config['TrackDuration'] ?? -1) === 30
            && is_array($calibrate)
            && ($calibrate['CenterPoint'] ?? null) === [4096, 4096]
            && (int) ($calibrate['Ratio'] ?? 0) === 1
            && $min === [0, 0]
            && $max === [8191, 8191]
            && (($rule['TrackEnable'] ?? true) === false)
            && (int) ($rule['PtzPresetId'] ?? -1) === 0
            && self::has24x7Schedule($eventHandler);
    }

    /** @param array<string,mixed> $eventHandler */
    public static function prepareEventHandler(array $eventHandler): array
    {
        $eventHandler = self::disableSideEffects($eventHandler);

        // The P03-owned Human proof must be available at all times. Never inherit
        // a possibly restricted arming schedule from an unrelated camera rule.
        $disabled = '0 00:00:00-23:59:59';
        $fullDay = ['1 00:00:00-23:59:59', $disabled, $disabled, $disabled, $disabled, $disabled];
        $eventHandler['TimeSection'] = [];
        for ($day = 0; $day < 7; $day++) {
            $eventHandler['TimeSection'][$day] = $fullDay;
        }

        return $eventHandler;
    }

    /** @param array<string,mixed> $eventHandler */
    public static function has24x7Schedule(array $eventHandler): bool
    {
        $timeSection = $eventHandler['TimeSection'] ?? null;
        if (!is_array($timeSection) || count($timeSection) !== 7) {
            return false;
        }

        for ($day = 0; $day < 7; $day++) {
            $periods = $timeSection[$day] ?? null;
            $first = is_array($periods) ? (string) ($periods[0] ?? '') : '';
            if (!in_array($first, [
                '1 00:00:00-23:59:59',
                '1 00:00:00-24:00:00',
            ], true)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $eventHandler */
    public static function disableSideEffects(array $eventHandler): array
    {
        foreach ([
            'AlarmOutEnable', 'AlarmUploadEnable', 'BeepEnable', 'ExAlarmOutEnable',
            'FlashEnable', 'FTPEnable', 'FtpEnable', 'LightEnable', 'LogEnable',
            'MMSEnable', 'MailEnable', 'MatrixEnable', 'MessageEnable',
            'MsgtoNetEnable', 'MultimediaMsgEnable', 'OnVideoMessageEnable',
            'PtzEnable', 'PtzLinkEnable', 'RecordEnable', 'ShortMsgEnable',
            'ShowInfo', 'SnapEnable', 'SnapshotEnable', 'TipEnable', 'TourEnable',
            'VoiceEnable',
        ] as $flag) {
            if (array_key_exists($flag, $eventHandler)) {
                $eventHandler[$flag] = false;
            }
        }

        if (isset($eventHandler['LightingLink']) && is_array($eventHandler['LightingLink'])) {
            $eventHandler['LightingLink']['Enable'] = false;
        }

        if (isset($eventHandler['TrigerHttp']) && is_array($eventHandler['TrigerHttp'])) {
            $eventHandler['TrigerHttp']['TrigerHttpEnable'] = false;
            $eventHandler['TrigerHttp']['TrigerHttpCommand'] = '';
        }

        return $eventHandler;
    }
}
