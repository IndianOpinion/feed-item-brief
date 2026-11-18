<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Byline clean-up (case 17). Never invents a name, never prints an email.
 */
final class Byline
{
    /**
     * @param mixed $raw string, list of strings, or null
     * @return list<string>
     */
    public static function normalise(mixed $raw, ?string $sourceName = null): array
    {
        $entries = is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);
        $out = [];
        $seen = [];
        $source = $sourceName !== null ? mb_strtolower(trim($sourceName), 'UTF-8') : null;
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $name = Text::plainLine(Text::decodeEscapes($entry));
            if (preg_match('/^\S+@\S+\.\S+\s*\((.+)\)$/u', $name, $m) === 1) {
                $name = trim($m[1]);
            }
            $name = trim((string) preg_replace('/^by[\s:]+/iu', '', $name));
            if ($name === '' || preg_match('/\S+@\S+\.\S+/u', $name) === 1) {
                continue;
            }
            if (mb_strlen($name, 'UTF-8') > 100) {
                continue;
            }
            $lower = mb_strtolower($name, 'UTF-8');
            if (($source !== null && $lower === $source) || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $out[] = $name;
        }

        return $out;
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param list<string> $names
     */
    public static function join(array $names, string $and = 'and'): string
    {
        $count = count($names);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $names[0];
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' ' . $and . ' ' . $last;
    }
}
