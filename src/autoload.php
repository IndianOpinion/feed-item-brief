<?php

/**
 * Autoloader used when the plugin runs without Composer.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\FeedItemBrief\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
