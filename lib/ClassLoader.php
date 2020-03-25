<?php

class ClassLoader
{

    private static $apps = [];

    public static function loadClass($base, $cls)
    {
        $items = explode('\\', $cls);
        if ($items < 3) {
            throw new Exception("Job配置格式有误");
        }
        $appName = strtolower($items[1]);
        $app = self::loadApp($base, $appName);
        if (!$app) {
            throw new Exception("装载app[$appName]失败");
        }

        $instance = $app->make($cls);
        if (!$instance) {
            throw new Exception("装载class[$cls]失败");
        }
        return $instance;
    }

    public static function loadApp($base, $appName)
    {
        if (array_key_exists($appName, self::$apps)) {
            return self::$apps[$appName];
        }
        $path = $base . "/gateways/$appName/bootstrap/app.php";
        if (!file_exists($path)) {
            throw new Exception("未打到APP[$appName]");
        }
        $app = require($path);
        self::$apps[$appName] = $app;

        return $app;
    }
}
