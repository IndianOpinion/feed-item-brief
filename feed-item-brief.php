<?php

/**
 * Plugin Name:       Feed Item Brief
 * Plugin URI:        https://github.com/IndianOpinion/feed-item-brief
 * Description:       Turns one feed item into an attributed summary draft with the source link, byline and publish time kept, for any importer or none.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       feed-item-brief
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/autoload.php';
    require_once __DIR__ . '/src/functions.php';
    if (is_readable(__DIR__ . '/lib/llm-json-php/bootstrap.php')) {
        require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';
    }
}

\IndianOpinion\FeedItemBrief\Plugin::boot(__FILE__);
