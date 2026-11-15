<?php

/**
 * Plugin Name: Feed Item Brief (must-use loader)
 * Description: Loads Feed Item Brief as a must-use plugin.
 *
 * Copy this file to wp-content/mu-plugins/feed-item-brief-loader.php and
 * put the plugin folder at wp-content/mu-plugins/feed-item-brief/.
 * Must-use plugins get no activation hook, so the tables are created on
 * the first load and the cron events on the next "init".
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/feed-item-brief/feed-item-brief.php';
