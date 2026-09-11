<?php

if (file_exists('/opt/vendor/autoload.php')) {
    $loader = require '/opt/vendor/autoload.php';
    if (file_exists('/var/task/vendor/autoload.php')) {
        require_once '/var/task/vendor/autoload.php';
    }
} else {
    $loader = require __DIR__ . '/../vendor/autoload.php';
}

$loader->addPsr4('App\\Common\\', [__DIR__ . '/Common/src/']);
$loader->addPsr4('LibreriasSri\\', [__DIR__ . '/../vendor/liberias-sri/']);
