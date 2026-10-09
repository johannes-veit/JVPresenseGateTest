<?php

declare(strict_types=1);

/**
 * Builds and validates the P03-owned auxiliary Human IVS proof rule.
 *
 * The two IPC-HFW5442E-ZE cameras used for JV_LEFT / WORK_LEFT were observed
 * to publish only VideoMotion/VideoMotionInfo although SMD was configured for
 * Human. A dedicated CrossRegionDetection rule therefore provides a native
 * IVS Human event that the existing AussenlichtAutomatik2 event parser already
 * understands.
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
        $eventHandler = self::disableSideEffects($eventHandler);

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
        $min = $rule['Config']['SizeFilter']['MinSize'] ?? null;
        $max = $rule['Config']['SizeFilter']['MaxSize'] ?? null;

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
            && $min === [0, 0]
            && $max === [8191, 8191];
    }

    /** @param array<string,mixed> $eventHandler */
    public static function disableSideEffects(array $eventHandler): array
    {
        foreach ([
            'AlarmOutEnable', 'BeepEnable', 'ExAlarmOutEnable', 'LogEnable', 'MMSEnable',
            'MailEnable', 'MatrixEnable', 'MessageEnable', 'PtzLinkEnable', 'RecordEnable',
            'SnapshotEnable', 'TipEnable', 'TourEnable', 'VoiceEnable',
        ] as $flag) {
            if (array_key_exists($flag, $eventHandler)) {
                $eventHandler[$flag] = false;
            }
        }

        if (isset($eventHandler['TrigerHttp']) && is_array($eventHandler['TrigerHttp'])) {
            $eventHandler['TrigerHttp']['TrigerHttpEnable'] = false;
            $eventHandler['TrigerHttp']['TrigerHttpCommand'] = '';
        }

        return $eventHandler;
    }
}
