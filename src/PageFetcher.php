<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Fetches the source page once, through wp_safe_remote_get (section 4.10).
 * Any failure is a reason, not an error: the item goes on with feed data.
 */
class PageFetcher
{
    public const TIMEOUT = 8;
    public const REDIRECTS = 3;
    public const MAX_BYTES = 1048576;

    /**
     * @return array{page: PageMeta|null, reason: string}
     */
    public function fetch(string $url): array
    {
        if (!Url::isValid($url)) {
            return ['page' => null, 'reason' => 'bad_url'];
        }
        $response = wp_safe_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => self::REDIRECTS,
            'limit_response_size' => self::MAX_BYTES,
            'user-agent' => 'feed-item-brief/' . Plugin::VERSION . '; ' . home_url('/'),
            'headers' => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'],
        ]);
        if ($response instanceof \WP_Error) {
            return ['page' => null, 'reason' => 'fetch_error: ' . $response->get_error_code()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code > 299) {
            return ['page' => null, 'reason' => 'http_' . $code];
        }
        $type = wp_remote_retrieve_header($response, 'content-type');
        $type = strtolower(is_array($type) ? (string) end($type) : $type);
        if ($type !== '' && !str_contains($type, 'html')) {
            return ['page' => null, 'reason' => 'not_html'];
        }
        $finalUrl = $url;
        $raw = is_array($response) ? ($response['http_response'] ?? null) : null;
        if (is_object($raw) && method_exists($raw, 'get_response_object')) {
            $object = $raw->get_response_object();
            if ($object instanceof \WpOrg\Requests\Response && Url::isValid($object->url)) {
                $finalUrl = $object->url;
            }
        }
        $robots = wp_remote_retrieve_header($response, 'x-robots-tag');
        $robots = is_array($robots) ? implode(',', $robots) : (string) $robots;

        return ['page' => PageMeta::fromHtml((string) wp_remote_retrieve_body($response), $finalUrl, $robots), 'reason' => 'ok'];
    }
}
