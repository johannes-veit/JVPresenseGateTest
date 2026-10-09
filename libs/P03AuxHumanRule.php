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
                // P03 only needs "person appears in this camera view".
                // Dahua documents Direction as valid only for Action=Cross.
                // Therefore use Appear alone for a nearly full-frame intrusion
                // region; camera order, not region-cross direction, determines
                // HOME<->LAGER direction.
                'Action' => ['Appear'],
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
        $config = is_array($rule['Config'] ?? null) ? $rule['Config'] : [];
        $objects = self::normalizedObjectTypes($rule['ObjectTypes'] ?? []);
        $actions = self::normalizedActions($config['Action'] ?? []);
        $region = $config['DetectRegion'] ?? null;
        $min = $config['SizeFilter']['MinSize'] ?? null;
        $max = $config['SizeFilter']['MaxSize'] ?? null;
        $eventHandler = is_array($rule['EventHandler'] ?? null) ? $rule['EventHandler'] : [];

        return strcasecmp((string) ($rule['Name'] ?? ''), $name) === 0
            && strcasecmp((string) ($rule['Type'] ?? ''), 'CrossRegionDetection') === 0
            && (($rule['Enable'] ?? false) === true)
            && strcasecmp((string) ($rule['Class'] ?? ''), 'Normal') === 0
            && in_array('human', $objects, true)
            && is_array($region)
            && $region === self::REGION
            && $actions === ['appear']
            && $min === [0, 0]
            && $max === [8191, 8191]
            && strcasecmp((string) ($config['SizeFilter']['Type'] ?? ''), 'ByLength') === 0
            && (($rule['TrackEnable'] ?? false) === false)
            && (int) ($rule['PtzPresetId'] ?? 0) === 0
            && self::has24x7Schedule($eventHandler);
    }

    /** @return string[] */
    private static function normalizedActions(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = strtolower(trim($item));
            if ($item !== '') {
                $out[] = $item;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** @return string[] */
    private static function normalizedObjectTypes(mixed $value): array
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($item)) {
                    $normalized = strtolower(trim($item));
                    if ($normalized !== '') {
                        $out[] = $normalized;
                    }
                    continue;
                }
                if (is_string($key) && (bool) $item) {
                    $normalized = strtolower(trim($key));
                    if ($normalized !== '') {
                        $out[] = $normalized;
                    }
                }
            }
        }
        sort($out);
        return array_values(array_unique($out));
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
