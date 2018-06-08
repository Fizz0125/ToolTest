<?php
namespace locallib\helpers;

namespace Someline\Tool;

class Swagger
{
    /*
     * 获取需要扫描的目录
     */
    public static function getScanFiles($controller)
    {
        $arrDir = [];
        // 排除掉正式环境
        if (env('APP_ENV') != 'pro') {
            $arrDir = [
                base_path("app/Http/Controllers/".ucfirst($controller))
            ];
            $arrDir[] = base_path()."/swagger/".env('APP_ENV');
        }
        return $arrDir;
    }
    
    public static function getExcludeFiles()
    {
        return [];
    }
}
