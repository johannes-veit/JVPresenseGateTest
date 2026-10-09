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
        [128, 128],
        [8063, 128],
        [8063, 8063],
        [128, 8063],
        [128, 128],
    ];

    /** @param array<string,mixed> $eventHandler */
    public static function build(string $name, int $id, array $eventHandler): array
    {
        $eventHandler = self::disableSideEffects($eventHandler);

        return [
            'Class' => 'Normal',
            'Config' => [
                'DetectRegion' => self::REGION,
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
