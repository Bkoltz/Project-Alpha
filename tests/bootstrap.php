<?php

$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'project-alpha-phpunit-sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0700, true);
}
ini_set('session.save_path', $sessionDir);
putenv('APP_SKIP_DB_CONFIG=1');

require dirname(__DIR__) . '/vendor/autoload.php';
