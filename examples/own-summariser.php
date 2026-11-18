<?php

/**
 * Plugin Name: Feed Item Brief summariser example
 * Description: Plugs a site's own summariser into Feed Item Brief.
 *
 * With this hooked in, the plugin needs no llm-json-php, no provider and
 * no API key. The switch, the cap, the output checks and the attribution
 * still apply.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_filter('feed_item_brief_summarise', static function (?array $result, array $request, $item) {
    if ($result !== null) {
        return $result;
    }
    // $request['messages'] is a chat message list; $request['schema'] the
    // JSON schema of the answer. Send them to your own service here.
    $response = wp_remote_post('https://summaries.internal.example/v1/summarise', [
        'timeout' => 45,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode(['messages' => $request['messages'], 'schema' => $request['schema']]),
    ]);
    if (is_wp_error($response)) {
        return $response;
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    return is_array($data) ? $data : new WP_Error('bad_answer', 'The summariser returned no JSON object.');
}, 10, 3);
