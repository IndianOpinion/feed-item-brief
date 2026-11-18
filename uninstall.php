<?php

/**
 * Uninstall: drop both tables, delete options, transients and cron events.
 * Posts and their meta are kept: they are editorial content, and the static
 * fallback in each attribution block keeps the credit readable.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\FeedItemBrief\Uninstaller::run();
