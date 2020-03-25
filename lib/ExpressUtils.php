<?php

class ExpressUtils
{

    /**
     * 08:00:00/3600
     * 08:00:00,09:00:00/86400  每天8点、9点各执行一次
     * 60    60秒后开始执行
     * 60,600   60秒,600秒后各执行一次
     * 5/600  5秒后开始执行，每600秒一次
     *
     **/
    public static function parseExpress($express)
    {
        if (!$express) {
            return [
                [
                    'express' => '-',
                    'delay' => 0,
                    'cycle' => 0,
                ]
            ];
        }

        $arr = explode('/', $express);
        if (count($arr) > 2) {
            return [];
        }
        $startTimeExpress = $arr[0];
        $cycleExpress = '';
        if (count($arr) == 2) {
            $cycleExpress = $arr[1];
        }
        $now = time();

        $result = [];

        $cycle = self::parseCycleExpress($cycleExpress);
        $title = "/$cycle";
        if ($cycle <= 0) {
            $title = '-';
        }
        $timeArr = explode(',', $startTimeExpress);
        foreach ($timeArr as $k => $v) {
            $ret = self::parseStartTimeExpress($v);
            if (!$ret) {
                return [];
            }
            $delay = $ret[1];
            if ($ret[0] == 'time') {
                $delay = $ret[1] - $now;
                $delay = $delay < 0 ? 86400 + $delay : $delay;
                $title = $v ? $v : '-';
            }

            if ($cycle > 0 && $delay > $cycle) {
                $delay = $delay % $cycle;
            }
            $result[] = [
                'express' => $title,
                'delay' => $delay,
                'cycle' => $cycle,
            ];
        }

        return $result;
    }

    private static function parseStartTimeExpress($express)
    {
        if (!$express) {
            return ['seconds', 0];
        }
        if (strpos($express, ':')) {
            $time = strtotime($express);
            if ($time <= 0) {
                return false;
            }
            return ['time', $time];
        }
        $delay = intval($express);
        if ($delay < 0) {
            return false;
        }
        return ['seconds', $delay];
    }

    private static function parseCycleExpress($express)
    {
        if (!$express) {
            return 0;
        }
        $v = intval($express);
        return $v > 0 ? $v : 0;
    }
}
