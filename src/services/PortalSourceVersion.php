<?php

declare(strict_types=1);

namespace App\Services;

final class PortalSourceVersion
{
    /** @param array<string,mixed> $visibleFields */
    public static function from(array$visibleFields):string
    {
        self::sort($visibleFields);
        return 'sha256-'.hash('sha256',json_encode($visibleFields,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    private static function sort(array&$value):void{if(array_is_list($value)){foreach($value as&$item)if(is_array($item))self::sort($item);unset($item);return;}ksort($value);foreach($value as&$item)if(is_array($item))self::sort($item);unset($item);}
}
