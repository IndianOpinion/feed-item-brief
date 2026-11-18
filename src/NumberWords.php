<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Number words per language for the number check (case 41). English ships;
 * sites add others with the feed_item_brief_number_words filter, which
 * receives and returns an array of language => [units, scales].
 */
final class NumberWords
{
    /**
     * @return array<string, array{units: array<string, int>, scales: array<string, int>}>
     */
    public static function lists(): array
    {
        $units = [
            'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
            'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
            'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
            'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
            'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
            'dozen' => 12, 'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
            'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10, 'half' => 50,
            'once' => 1, 'twice' => 2, 'single' => 1, 'double' => 2, 'triple' => 3,
        ];
        $scales = [
            'hundred' => 100, 'thousand' => 1000, 'lakh' => 100000, 'lakhs' => 100000, 'million' => 1000000,
            'crore' => 10000000, 'crores' => 10000000, 'billion' => 1000000000, 'trillion' => 1000000000000,
        ];
        $lists = apply_filters('feed_item_brief_number_words', ['en' => ['units' => $units, 'scales' => $scales]]);
        $out = [];
        foreach (is_array($lists) ? $lists : [] as $lang => $list) {
            if (!is_string($lang) || !is_array($list)) {
                continue;
            }
            $u = [];
            $s = [];
            foreach (is_array($list['units'] ?? null) ? $list['units'] : [] as $word => $value) {
                if (is_string($word) && is_numeric($value)) {
                    $u[mb_strtolower($word, 'UTF-8')] = (int) $value;
                }
            }
            foreach (is_array($list['scales'] ?? null) ? $list['scales'] : [] as $word => $value) {
                if (is_string($word) && is_numeric($value)) {
                    $s[mb_strtolower($word, 'UTF-8')] = (int) $value;
                }
            }
            $out[strtolower($lang)] = ['units' => $u, 'scales' => $s];
        }

        return $out;
    }

    /**
     * Every number value written in words in $text, plus digit-and-scale
     * forms such as "2 million". Null when no list covers the language.
     *
     * @return list<string>|null normalised number strings
     */
    public static function valuesIn(string $text, string $language): ?array
    {
        $primary = strtolower(explode('-', $language)[0]);
        $lists = self::lists();
        if (!isset($lists[$primary])) {
            return null;
        }
        $units = $lists[$primary]['units'];
        $scales = $lists[$primary]['scales'];
        $tokens = preg_split('/[^\p{L}\p{N}.,]+/u', mb_strtolower($text, 'UTF-8')) ?: [];
        $values = [];
        $run = ['current' => null, 'total' => 0];
        foreach ($tokens as $token) {
            $token = trim($token, '.,');
            if ($token === '' || $token === 'and') {
                continue;
            }
            if (isset($units[$token])) {
                $values[] = (string) $units[$token];
                $run['current'] = ($run['current'] ?? 0) + $units[$token];
                continue;
            }
            if (isset($scales[$token])) {
                $base = $run['current'] ?? 1;
                if ($scales[$token] >= 1000) {
                    $run['total'] += $base * $scales[$token];
                    $run['current'] = null;
                } else {
                    $run['current'] = $base * $scales[$token];
                }
                continue;
            }
            $values = self::flush($run, $values);
            $run = ['current' => null, 'total' => 0];
            if (preg_match('/^\d+(?:[.,]\d+)?$/', $token) === 1) {
                $values[] = OutputChecker::normaliseNumber($token);
            }
        }
        $values = self::flush($run, $values);

        $values = array_merge($values, self::digitScales(mb_strtolower($text, 'UTF-8'), $scales));

        return array_values(array_unique($values));
    }

    /**
     * @param array{current: int|null, total: int} $run
     * @param list<string> $values
     * @return list<string>
     */
    private static function flush(array $run, array $values): array
    {
        if ($run['current'] !== null || $run['total'] > 0) {
            $values[] = (string) ($run['total'] + ($run['current'] ?? 0));
        }

        return $values;
    }

    /**
     * "2 million", "1.5 billion", "3 lakh" as plain numbers.
     *
     * @param array<string, int> $scales
     * @return list<string>
     */
    private static function digitScales(string $text, array $scales): array
    {
        $out = [];
        if ($scales === []) {
            return $out;
        }
        $names = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), array_keys($scales)));
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(' . $names . ')\b/u', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $value = (float) $match[1] * $scales[$match[2]];
                $out[] = OutputChecker::normaliseNumber(rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'));
            }
        }

        return $out;
    }
}
