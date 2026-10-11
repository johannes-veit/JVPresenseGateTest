<?php
declare(strict_types=1);
require_once __DIR__ . '/P03OnvifEvents.php';

/**
 * Correlate a native ONVIF LineDetector ObjectId with a separate
 * ObjectDetection ClassTypes observation on the SAME camera/stream.
 * Ingest order is arbitrary. A single line event alone is NOT Human proof.
 */
final class P03OnvifCorrelator
{
    /** @param array<string,mixed> $e
     *  @param array<string,mixed> $state
     *  @return array<string,mixed>|null
     */
    public static function ingest(array $e,array &$state,int $now): ?array
    {
        if (!isset($state['classes']) || !is_array($state['classes'])) {
            $state['classes']=[];
        }
        if (!isset($state['crossings']) || !is_array($state['crossings'])) {
            $state['crossings']=[];
        }
        foreach (['classes','crossings'] as $kind) {
            foreach ($state[$kind] as $key=>$previous) {
                if (!is_array($previous) || !isset($previous['seen'])
                    || !is_numeric($previous['seen'])
                    || $now-(int)$previous['seen']>40
                    || (int)$previous['seen']>$now) {
                    unset($state[$kind][$key]);
                }
            }
        }
        $source=is_array($e['source']??null)?$e['source']:[];
        $data=is_array($e['data']??null)?$e['data']:[];
        $objectId=trim((string)(
            $data['ObjectId']??$data['ObjectID']??$source['ObjectId']??''
        ));
        if ($objectId==='' || strlen($objectId)>64
            || !preg_match('/^[A-Za-z0-9_-]+$/D',$objectId)) {
            return null;
        }
        $stream=trim((string)(
            $source['VideoSourceConfigurationToken']
            ??$source['VideoSourceToken']??''
        ));
        // Different streams must never share an ObjectId unless the camera
        // explicitly identifies them as belonging to the same stream.
        $key=sha1($stream.'|'.$objectId);
        $topic=strtolower(trim((string)($e['topic']??'')));
        $stateFlag=strtolower(trim((string)($data['State']??'true')));
        $op=strtolower(trim((string)($e['operation']??'')));
        if ($op==='initialized' || $op==='deleted'
            || in_array($stateFlag,['false','0','off','stop'],true)) {
            return null;
        }
        $utc=(string)($e['utc']??'');
        $timestamp=$utc!==''?strtotime($utc):false;
        $stamp=$timestamp===false?null:(int)$timestamp;

        $line=str_contains($topic,'ruleengine/linedetector/crossed');
        if ($line) {
            $name=trim((string)($source['Rule']??$source['RuleName']??''));
            if ($name==='' || !preg_match('/trepp/i',$name)
                || preg_match('/strasse|straße|street/i',$name)) {
                return null;
            }
            $cls=P03OnvifEvents::extractObjectClassification($data);
            if (in_array($cls,['vehicle','motorvehicle','motor_vehicle'],true)) {
                return null;
            }
            if ($cls!=='' && !in_array($cls,['human','person','pedestrian'],true)) {
                return null;
            }
            $state['crossings'][$key]=[
                'seen'=>$now,'utc'=>$stamp,'objectId'=>$objectId,
                'rule'=>$name,'stream'=>$stream
            ];
            if (in_array($cls,['human','person','pedestrian'],true)) {
                unset($state['crossings'][$key]);
                return ['rule'=>$name,'objectId'=>$objectId,'utc'=>$utc,
                    'source'=>'ONVIF_INLINE_HUMAN'];
            }
        } elseif (str_contains($topic,'ruleengine/objectdetection/object')) {
            $cls=P03OnvifEvents::extractObjectClassification($data);
            if (!in_array($cls,['human','person','pedestrian'],true)) {
                unset($state['classes'][$key]);
                unset($state['crossings'][$key]);
                return null;
            }
            $state['classes'][$key]=['seen'=>$now,'utc'=>$stamp,
                'objectId'=>$objectId,'stream'=>$stream];
        } else {
            return null;
        }

        $cross=$state['crossings'][$key]??null;
        $class=$state['classes'][$key]??null;
        if (!is_array($cross) || !is_array($class)) {
            return null;
        }
        if ((int)$cross['seen'] - (int)$class['seen']>40
            || (int)$class['seen'] - (int)$cross['seen']>40) {
            return null;
        }
        // When UTC is present on both notifications, demand they describe
        // the same short physical scene, independent of 15-second polling.
        if ($cross['utc']!==null && $class['utc']!==null) {
            if (abs((int)$cross['utc']-(int)$class['utc'])>8) {
                return null;
            }
        } elseif (abs((int)$cross['seen']-(int)$class['seen'])>20) {
            return null;
        }
        unset($state['crossings'][$key],$state['classes'][$key]);
        return [
            'rule'=>(string)$cross['rule'],
            'objectId'=>$objectId,
            'utc'=>$utc,
            'source'=>'ONVIF_OBJECTID_CLASS_CORRELATION'
        ];
    }
}
