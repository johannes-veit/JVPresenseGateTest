<?php

declare(strict_types=1);

/**
 * Builds the P03-owned Human IVS rule strictly from a template supplied by the
 * target Dahua camera. Unknown firmware-specific fields are preserved.
 */
final class P03AuxHumanRule
{
    public const REGION = [
        [256, 256],
        [7935, 256],
        [7935, 7935],
        [256, 7935],
    ];

    /**
     * @param array<string,mixed> $template Native CrossRegionDetection template.
     * @param array<string,mixed> $eventHandler Existing camera EventHandler template.
     * @return array<string,mixed>
     */
    public static function buildFromTemplate(
        string $name,
        int $id,
        array $template,
        array $eventHandler
    ): array {
        if (!P03DahuaTemplate::looksLikeCrossRegion($template)) {
            throw new InvalidArgumentException('Kein gültiges natives CrossRegionDetection-Template');
        }

        // Preserve all firmware-specific template fields and only override the
        // semantics owned by P03.
        $rule = $template;
        $rule['Class'] = 'Normal';
        $rule['Type'] = 'CrossRegionDetection';
        $rule['Enable'] = true;
        $rule['Id'] = $id;
        $rule['Name'] = $name;
        $rule['ObjectTypes'] = ['Human'];
        $rule['TrackEnable'] = false;
        $rule['PtzPresetId'] = (int) ($rule['PtzPresetId'] ?? 0);
        $rule['EventHandler'] = self::disableSideEffects(
            is_array($rule['EventHandler'] ?? null) ? $rule['EventHandler'] : $eventHandler
        );

        $config = is_array($rule['Config'] ?? null) ? $rule['Config'] : [];
        $config['DetectRegion'] = self::REGION;

        // The mast cameras are not asked for a direction. Their chronological
        // order defines HOME/LAGER direction. "Appear" is therefore the correct
        // IVS action: a Human becoming visible anywhere inside this broad region
        // is sufficient. This also avoids depending on a region-edge crossing.
        $templateAction = $config['Action'] ?? 'Appear';
        $config['Action'] = is_array($templateAction) ? ['Appear'] : 'Appear';

        // Direction is irrelevant for Action=Appear, but keep it non-restrictive
        // for firmwares that require the field to exist.
        $config['Direction'] = 'Both';

        if (is_array($config['SizeFilter'] ?? null)) {
            $config['SizeFilter']['MaxSize'] = [8191, 8191];
            $config['SizeFilter']['MinSize'] = [0, 0];
            $config['SizeFilter']['Type'] ??= 'ByLength';
        }

        $rule['Config'] = $config;
        return $rule;
    }

    /** @param array<string,mixed> $rule */
    public static function matches(array $rule, string $name): bool
    {
        $objects = $rule['ObjectTypes'] ?? [];
        $region = $rule['Config']['DetectRegion'] ?? null;
        $action = $rule['Config']['Action'] ?? null;
        $direction = $rule['Config']['Direction'] ?? null;

        $appear = is_array($action)
            ? in_array('Appear', $action, true)
            : strcasecmp((string) $action, 'Appear') === 0;

        return strcasecmp((string) ($rule['Name'] ?? ''), $name) === 0
            && strcasecmp((string) ($rule['Type'] ?? ''), 'CrossRegionDetection') === 0
            && (($rule['Enable'] ?? false) === true)
            && strcasecmp((string) ($rule['Class'] ?? ''), 'Normal') === 0
            && is_array($objects)
            && in_array('Human', $objects, true)
            && is_array($region)
            && $region === self::REGION
            && $appear
            && strcasecmp((string) $direction, 'Both') === 0;
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
