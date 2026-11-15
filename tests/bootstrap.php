<?php

declare(strict_types=1);

require __DIR__ . '/stubs/wordpress.php';
require __DIR__ . '/stubs/wpdb.php';
require dirname(__DIR__) . '/vendor/autoload.php';

// Keep error_log() lines out of the test output.
ini_set('error_log', sys_get_temp_dir() . '/feed-item-brief-tests.log');
