<?php

/**
 * A small stand-in for the parts of WordPress the plugin uses, so PHPUnit
 * runs without a WordPress install. State lives in WpStub and is reset
 * before every test.
 */

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
defined('WPINC') || define('WPINC', 'wp-includes');
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $didAction = [];
    /** @var list<array{0: string, 1: string}> */
    public static array $doingItWrong = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    public static int $nextPostId = 100;
    public static bool $insertFails = false;
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, array{0: int, 1: string}> */
    public static array $scheduled = [];
    /** @var list<array{0: string, 1: int}> */
    public static array $single = [];
    /** @var (callable(string, array<string, mixed>): mixed)|null */
    public static $http = null;
    /** @var list<string> */
    public static array $httpCalls = [];
    /** @var array<string, bool> */
    public static array $caps = [];
    public static int $userId = 1;
    /** @var array<string, string|WP_Error> */
    public static array $feeds = [];
    /** @var array<string, array<string, mixed>> */
    public static array $registeredMeta = [];
    /** @var array<string, array<string, mixed>> */
    public static array $blocks = [];
    public static int $currentPost = 0;
    /** @var list<string> */
    public static array $privacy = [];

    public static function reset(): void
    {
        self::$options = ['timezone_string' => 'UTC', 'date_format' => 'F j, Y', 'time_format' => 'g:i a', 'home' => 'https://mysite.example'];
        self::$hooks = [];
        self::$didAction = [];
        self::$doingItWrong = [];
        self::$posts = [];
        self::$meta = [];
        self::$nextPostId = 100;
        self::$insertFails = false;
        self::$transients = [];
        self::$scheduled = [];
        self::$single = [];
        self::$http = null;
        self::$httpCalls = [];
        self::$caps = [];
        self::$userId = 1;
        self::$feeds = [];
        self::$registeredMeta = [];
        self::$blocks = [];
        self::$currentPost = 0;
        self::$privacy = [];
    }
}

class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code] = [$message];
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(): string
    {
        $code = $this->get_error_code();

        return $code === '' ? '' : ($this->errors[$code][0] ?? '');
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'draft';
    public string $post_type = 'post';
    public int $post_author = 0;
    public string $post_date = '';

    /** @param array<string, mixed> $data */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}

class WP_REST_Response
{
    public function __construct(private mixed $data = null)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function set_data(mixed $data): void
    {
        $this->data = $data;
    }
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::$hooks[$hook][$priority][] = [$callback, $args];
    ksort(WpStub::$hooks[$hook]);

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_filter(string $hook, callable|string $callback, int $priority = 10): bool
{
    foreach (WpStub::$hooks[$hook][$priority] ?? [] as $i => [$cb]) {
        if ($cb === $callback) {
            unset(WpStub::$hooks[$hook][$priority][$i]);
            WpStub::$hooks[$hook][$priority] = array_values(WpStub::$hooks[$hook][$priority]);
            if (WpStub::$hooks[$hook][$priority] === []) {
                unset(WpStub::$hooks[$hook][$priority]);
            }
            if (WpStub::$hooks[$hook] === []) {
                unset(WpStub::$hooks[$hook]);
            }

            return true;
        }
    }

    return false;
}

function remove_action(string $hook, callable|string $callback, int $priority = 10): bool
{
    return remove_filter($hook, $callback, $priority);
}

function has_filter(string $hook, mixed $callback = false): bool
{
    return !empty(WpStub::$hooks[$hook]);
}

function has_action(string $hook, mixed $callback = false): bool
{
    return has_filter($hook, $callback);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $count]) {
            $value = $callback(...array_slice([$value, ...$args], 0, max(1, $count)));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$didAction[$hook] = (WpStub::$didAction[$hook] ?? 0) + 1;
    foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    }
}

function did_action(string $hook): int
{
    return WpStub::$didAction[$hook] ?? 0;
}

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[$name] = $value;

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
{
    if (array_key_exists($name, WpStub::$options)) {
        return false;
    }
    WpStub::$options[$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[$name]);

    return true;
}

function get_transient(string $name): mixed
{
    return WpStub::$transients[$name] ?? false;
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    WpStub::$transients[$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStub::$transients[$name]);

    return true;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function _x(string $text, string $context, string $domain = 'default'): string
{
    return $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function esc_attr__(string $text, string $domain = 'default'): string
{
    return esc_attr($text);
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_js(string $text): string
{
    return addslashes($text);
}

function esc_url(string $url): string
{
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }

    return str_replace(['&', '"', "'", '<', '>'], ['&#038;', '&quot;', '&#039;', '&lt;', '&gt;'], $url);
}

function esc_url_raw(string $url): string
{
    return preg_match('#^https?://#i', $url) ? $url : '';
}

function esc_sql(string $text): string
{
    return addslashes($text);
}

function wp_kses_post(string $html): string
{
    return (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $html);
}

function wp_strip_all_tags(string $text): string
{
    $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $text);

    return trim(strip_tags($text));
}

function sanitize_text_field(string $text): string
{
    $text = strip_tags($text);
    $text = str_replace('<', '&lt;', $text);

    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', $text));
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function wp_slash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}

function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function home_url(string $path = ''): string
{
    return rtrim((string) WpStub::$options['home'], '/') . '/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return home_url('wp-admin/' . $path);
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    return home_url('wp-content/plugins/feed-item-brief/' . $path);
}

function wp_timezone_string(): string
{
    $tz = get_option('timezone_string', 'UTC');

    return is_string($tz) && $tz !== '' ? $tz : 'UTC';
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(wp_timezone_string());
}

function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string
{
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format);
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    WpStub::$doingItWrong[] = [$function, $message];
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * @param array<string, mixed> $args
 */
function wp_insert_post(array $args, bool $wpError = false): int|WP_Error
{
    if (WpStub::$insertFails) {
        return $wpError ? new WP_Error('db_insert_error', 'Could not insert post into the database.') : 0;
    }
    $args = wp_unslash($args);
    $id = WpStub::$nextPostId++;
    $post = new WP_Post([
        'ID' => $id,
        'post_title' => (string) ($args['post_title'] ?? ''),
        'post_content' => (string) ($args['post_content'] ?? ''),
        'post_excerpt' => (string) ($args['post_excerpt'] ?? ''),
        'post_status' => (string) ($args['post_status'] ?? 'draft'),
        'post_type' => (string) ($args['post_type'] ?? 'post'),
        'post_author' => (int) ($args['post_author'] ?? 0),
        'post_date' => (string) ($args['post_date'] ?? ''),
    ]);
    WpStub::$posts[$id] = $post;
    foreach ((array) ($args['meta_input'] ?? []) as $key => $value) {
        WpStub::$meta[$id][$key] = $value;
    }
    do_action('transition_post_status', $post->post_status, 'new', $post);

    return $id;
}

/**
 * @param array<string, mixed> $args
 */
function wp_update_post(array $args, bool $wpError = false): int|WP_Error
{
    $args = wp_unslash($args);
    $id = (int) ($args['ID'] ?? 0);
    if (!isset(WpStub::$posts[$id])) {
        return $wpError ? new WP_Error('invalid_post', 'Invalid post ID.') : 0;
    }
    $post = WpStub::$posts[$id];
    $old = $post->post_status;
    foreach ($args as $key => $value) {
        if ($key !== 'ID' && property_exists($post, $key)) {
            $post->{$key} = $value;
        }
    }
    if ($post->post_status !== $old) {
        do_action('transition_post_status', $post->post_status, $old, $post);
    }

    return $id;
}

function get_post(mixed $post = null): ?WP_Post
{
    $id = $post === null ? WpStub::$currentPost : ($post instanceof WP_Post ? $post->ID : (int) $post);

    return WpStub::$posts[$id] ?? null;
}

function get_post_status(mixed $post = null): string|false
{
    $p = get_post($post);

    return $p === null ? false : $p->post_status;
}

function get_the_ID(): int|false
{
    return WpStub::$currentPost > 0 ? WpStub::$currentPost : false;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    if (!array_key_exists($key, WpStub::$meta[$id] ?? [])) {
        return $single ? '' : [];
    }

    return $single ? WpStub::$meta[$id][$key] : [WpStub::$meta[$id][$key]];
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    WpStub::$meta[$id][$key] = wp_unslash($value);

    return true;
}

function add_post_meta(int $id, string $key, mixed $value, bool $unique = false): bool
{
    return update_post_meta($id, $key, $value);
}

function delete_post_meta(int $id, string $key): bool
{
    unset(WpStub::$meta[$id][$key]);

    return true;
}

/**
 * @param array<string, mixed> $args
 */
function register_post_meta(string $type, string $key, array $args): bool
{
    WpStub::$registeredMeta[$key] = $args;

    return true;
}

/**
 * @param array<string, mixed> $args
 */
function register_block_type(string $name, array $args = []): bool
{
    WpStub::$blocks[$name] = $args;

    return true;
}

/**
 * @param list<string> $deps
 */
function wp_register_script(string $handle, string $src, array $deps = [], mixed $ver = false, mixed $footer = false): bool
{
    return true;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    return WpStub::$caps[$cap] ?? false;
}

function user_can(mixed $user, string $cap, mixed ...$args): bool
{
    return (int) $user === WpStub::$userId && current_user_can($cap, ...$args);
}

class WP_User
{
    public function __construct(public int $ID = 0, public string $user_login = '')
    {
    }
}

function wp_get_current_user(): WP_User
{
    return new WP_User(WpStub::$userId, 'user' . WpStub::$userId);
}

function get_current_user_id(): int
{
    return WpStub::$userId;
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_safe_remote_get(string $url, array $args = []): array|WP_Error
{
    WpStub::$httpCalls[] = $url;
    if (WpStub::$http === null) {
        return new WP_Error('http_request_failed', 'No network in tests.');
    }

    return (WpStub::$http)($url, $args);
}

function wp_remote_retrieve_response_code(mixed $response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}

function wp_remote_retrieve_header(mixed $response, string $header): string
{
    if (!is_array($response)) {
        return '';
    }
    foreach ((array) ($response['headers'] ?? []) as $name => $value) {
        if (strtolower((string) $name) === strtolower($header)) {
            return (string) $value;
        }
    }

    return '';
}

function wp_remote_retrieve_body(mixed $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function fetch_feed(string $url): object
{
    $source = WpStub::$feeds[$url] ?? new WP_Error('simplepie-error', 'Feed not found.');
    if ($source instanceof WP_Error) {
        return $source;
    }
    $feed = new \SimplePie\SimplePie();
    $feed->set_raw_data((string) file_get_contents($source));
    $feed->enable_cache(false);
    $feed->init();

    return $feed;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    WpStub::$scheduled[$hook] = [$timestamp, $recurrence];

    return true;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    WpStub::$single[] = [$hook, $timestamp];

    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return isset(WpStub::$scheduled[$hook]) ? WpStub::$scheduled[$hook][0] : false;
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    unset(WpStub::$scheduled[$hook]);

    return 1;
}

function is_admin(): bool
{
    return false;
}

function is_multisite(): bool
{
    return false;
}

function is_singular(): bool
{
    return WpStub::$currentPost > 0;
}

function get_queried_object_id(): int
{
    return WpStub::$currentPost;
}

/**
 * @return array<string, string>
 */
function get_post_types(array $args = []): array
{
    return ['post' => 'post', 'page' => 'page'];
}

function wp_add_privacy_policy_content(string $name, string $text): void
{
    WpStub::$privacy[] = $text;
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return $nonce === 'nonce-' . $action ? 1 : false;
}

function wp_nonce_field(string $action, string $name = '_wpnonce'): string
{
    echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';

    return '';
}

function dbDelta(array|string $queries): array
{
    global $wpdb;
    foreach ((array) $queries as $query) {
        $wpdb->query((string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', trim($query)));
    }

    return [];
}
