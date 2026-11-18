<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * URL validation and the article key (case 7, 11, 13).
 */
final class Url
{
    /** Leading host labels that do not change the publisher. */
    private const HOST_PREFIXES = ['www.', 'm.', 'mobile.', 'amp.'];

    /** Tracking arguments dropped from the article key by default. */
    public const TRACKING = ['fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref', 'cmpid'];

    /**
     * Trim and decode HTML entities once.
     */
    public static function clean(string $url): string
    {
        return trim(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * True for an absolute http or https URL with a host and no white space
     * or control characters.
     */
    public static function isValid(string $url): bool
    {
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '' && preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) === 1;
    }

    public static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower(rtrim($host, '.')) : '';
    }

    /**
     * The host without one leading www., m., mobile. or amp. label.
     */
    public static function bareHost(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        foreach (self::HOST_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix) && substr_count($host, '.') >= 2) {
                return substr($host, strlen($prefix));
            }
        }

        return $host;
    }

    /**
     * True when two URLs or hosts belong to the same site, ignoring the
     * prefixes above.
     */
    public static function sameSite(string $a, string $b): bool
    {
        $ha = str_contains($a, '/') ? self::host($a) : strtolower($a);
        $hb = str_contains($b, '/') ? self::host($b) : strtolower($b);

        return $ha !== '' && self::bareHost($ha) === self::bareHost($hb);
    }

    /**
     * The article key of case 13.
     *
     * @param list<string> $tracking extra argument names to drop
     */
    public static function articleKey(string $url, array $tracking = self::TRACKING): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return strtolower($url);
        }
        $host = self::bareHost((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $path = (string) preg_replace('#/amp/?$#i', '', $path);
        $path = rtrim($path, '/');
        $drop = array_map('strtolower', $tracking);
        $args = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            foreach (explode('&', $parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                $name = $eq === false ? $pair : substr($pair, 0, $eq);
                $value = $eq === false ? '' : substr($pair, $eq + 1);
                $lname = strtolower(rawurldecode($name));
                if (str_starts_with($lname, 'utm_') || in_array($lname, $drop, true) || $lname === 'amp') {
                    continue;
                }
                if (($lname === 'outputtype' && strtolower($value) === 'amp')) {
                    continue;
                }
                $args[] = [$name, $value];
            }
        }
        usort($args, static fn (array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
        $query = implode('&', array_map(static fn (array $p): string => $p[1] === '' ? $p[0] : $p[0] . '=' . $p[1], $args));

        return $host . $port . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * A date in the URL path: /2026/11/14/, /2026-11-14/ or /20261114/.
     *
     * @return array{int,int,int}|null year, month, day
     */
    public static function pathDate(string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $patterns = [
            '#/((?:19|20)\d{2})/(0[1-9]|1[0-2])/(0[1-9]|[12]\d|3[01])(?:/|$)#',
            '#/((?:19|20)\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])(?:[/\-_.]|$)#',
            '#/((?:19|20)\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(?:/|$)#',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return [(int) $m[1], (int) $m[2], (int) $m[3]];
            }
        }

        return null;
    }
}
