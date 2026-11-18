<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Output checks on a summary (case 41). Lines that narrate the model's
 * working are removed; unsupported numbers and quotes, single-source
 * phrases, over-length text and the wrong language are hard failures;
 * relative-time words are flagged only.
 */
final class OutputChecker
{
    public const BANNED_LINES = ['Note:', 'Word count', 'Summary:', 'Headline:'];

    public const SINGLE_SOURCE = [
        'reports differ', 'sources differ', 'other outlets', 'according to reports', 'media reports',
        'multiple reports', 'some outlets',
    ];

    public const RELATIVE_TIME = ['today', 'yesterday', 'tomorrow', 'this morning', 'last night'];

    public const HEADLINE_MIN = 20;
    public const HEADLINE_MAX = 120;

    /**
     * @param array<mixed> $data the model or summariser result
     * @param string $source the source text sent to the model
     * @param string $title the item title
     * @param string|null $language the item language, null or 'und' when unknown
     * @param int $maxWords the word ceiling for the mode
     * @param int|null $date the resolved publish time, whose day and year count as supported numbers
     */
    public function check(array $data, string $source, string $title, ?string $language, int $maxWords, ?int $date = null): CheckResult
    {
        $shape = self::shape($data);
        if (is_string($shape)) {
            return new CheckResult('', [], '', false, ['bad_shape: ' . $shape], []);
        }
        [$headline, $paragraphs, $resultLanguage, $insufficient] = $shape;
        if ($insufficient) {
            return new CheckResult($headline, $paragraphs, $resultLanguage, true, [], []);
        }

        $removed = 0;
        $headline = $this->cleanHeadline($headline);
        $clean = [];
        foreach ($paragraphs as $paragraph) {
            $kept = $this->cleanParagraph($paragraph, $removed);
            if ($kept !== '') {
                $clean[] = $kept;
            }
        }

        $failures = [];
        $flags = [];
        if ($clean === []) {
            $failures[] = 'empty_summary';
        }
        $headLength = mb_strlen($headline, 'UTF-8');
        if ($headLength < self::HEADLINE_MIN || $headLength > self::HEADLINE_MAX) {
            $failures[] = sprintf('headline_length: %d characters, allowed %d to %d', $headLength, self::HEADLINE_MIN, self::HEADLINE_MAX);
        }

        $summary = implode("\n\n", $clean);
        $haystack = $source . "\n\n" . $title;
        $lang = $language !== null && $language !== '' && $language !== 'und' ? $language : null;
        $numberLang = $lang ?? ($resultLanguage !== '' ? $resultLanguage : 'und');

        foreach ($this->numbers($headline . "\n" . $summary, $haystack, $numberLang, $date) as [$number, $covered]) {
            if ($covered) {
                $failures[] = 'unsupported_number: ' . $number;
            } else {
                $flags[] = 'unverified_number: ' . $number;
            }
        }

        $normalisedSource = Text::forMatch($haystack);
        foreach (self::quotes($summary) as $quote) {
            $needle = trim(Text::forMatch($quote), " \t.,;:!?'\"");
            if ($needle !== '' && !str_contains($normalisedSource, $needle)) {
                $failures[] = 'unsupported_quote: "' . $quote . '"';
            }
        }

        $phrases = apply_filters('feed_item_brief_single_source_phrases', self::SINGLE_SOURCE, $numberLang);
        foreach (is_array($phrases) ? $phrases : [] as $phrase) {
            if (is_string($phrase) && $phrase !== '' && (Classifier::containsPhrase($summary, $phrase) || Classifier::containsPhrase($headline, $phrase))) {
                $failures[] = 'single_source_phrase: ' . $phrase;
            }
        }

        $words = apply_filters('feed_item_brief_relative_time_words', self::RELATIVE_TIME, $numberLang);
        foreach (is_array($words) ? $words : [] as $word) {
            if (is_string($word) && $word !== '' && Classifier::containsPhrase($summary . "\n" . $headline, $word) && !Classifier::containsPhrase($source, $word)) {
                $flags[] = 'relative_time: ' . $word;
            }
        }

        $count = Text::wordCount($summary);
        $ceiling = (int) floor($maxWords * 1.1);
        if ($maxWords > 0 && $count > $ceiling) {
            $failures[] = sprintf('over_length: %d words, ceiling %d', $count, $ceiling);
        }

        if ($lang !== null) {
            $want = strtolower(explode('-', $lang)[0]);
            $got = strtolower(explode('-', str_replace('_', '-', $resultLanguage))[0]);
            if ($want !== $got) {
                $failures[] = 'wrong_language: ' . ($resultLanguage !== '' ? $resultLanguage : 'none') . ', expected ' . $want;
            }
        }

        return new CheckResult($headline, $clean, $resultLanguage, false, array_values(array_unique($failures)), array_values(array_unique($flags)), $removed);
    }

    /**
     * @param array<mixed> $data
     * @return array{string, list<string>, string, bool}|string the fields, or what is wrong
     */
    public static function shape(array $data): array|string
    {
        if (!is_string($data['headline'] ?? null)) {
            return 'headline must be a string';
        }
        if (!is_array($data['summary'] ?? null) || !array_is_list($data['summary'])) {
            return 'summary must be an array of strings';
        }
        if (!is_bool($data['insufficient'] ?? null)) {
            return 'insufficient must be a boolean';
        }
        $language = $data['language'] ?? '';
        if (!is_string($language)) {
            return 'language must be a string';
        }
        $paragraphs = [];
        foreach ($data['summary'] as $paragraph) {
            if (!is_string($paragraph)) {
                return 'summary must be an array of strings';
            }
            $paragraphs[] = Text::decodeEscapes($paragraph);
        }
        if (!$data['insufficient'] && (count($paragraphs) < 1 || count($paragraphs) > 3)) {
            return 'summary must have 1 to 3 paragraphs';
        }

        return [Text::decodeEscapes($data['headline']), $paragraphs, trim(Text::decodeEscapes($language)), $data['insufficient']];
    }

    /**
     * @return list<string>
     */
    private function bannedPrefixes(): array
    {
        $list = apply_filters('feed_item_brief_banned_lines', self::BANNED_LINES);

        return array_values(array_filter(is_array($list) ? $list : [], static fn ($p): bool => is_string($p) && trim($p) !== ''));
    }

    private function cleanHeadline(string $headline): string
    {
        $headline = trim(strip_tags($headline));
        $headline = (string) preg_replace('/^#{1,6}\s*/u', '', $headline);
        $headline = str_replace(['**', '__'], '', $headline);
        foreach ($this->bannedPrefixes() as $prefix) {
            if (mb_stripos($headline, $prefix, 0, 'UTF-8') === 0) {
                $headline = trim(mb_substr($headline, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $headline));
    }

    private function cleanParagraph(string $paragraph, int &$removed): string
    {
        $keep = [];
        foreach (preg_split('/\r?\n/u', strip_tags($paragraph)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $plain = trim(str_replace(['**', '__'], '', $line));
            if (
                preg_match('/^#{1,6}\s/u', $plain) === 1
                || preg_match('/^([\-*\x{2022}]|\d+[.)])\s/u', $plain) === 1
                || preg_match('/^[\[(].*[\])]$/u', $plain) === 1
                || $this->startsWithBanned($plain)
            ) {
                $removed++;
                continue;
            }
            $before = $plain;
            $plain = trim((string) preg_replace('/\s*\[[^\]]*\]/u', '', $plain));
            if ($plain !== $before) {
                $removed++;
            }
            if ($plain !== '') {
                $keep[] = $plain;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $keep)));
    }

    private function startsWithBanned(string $line): bool
    {
        foreach ($this->bannedPrefixes() as $prefix) {
            if (mb_stripos($line, $prefix, 0, 'UTF-8') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Numbers in the summary that the source does not support.
     *
     * @return list<array{string, bool}> number, and whether a word list covers the language
     */
    private function numbers(string $summary, string $source, string $language, ?int $date): array
    {
        $found = self::numberTokens($summary);
        if ($found === []) {
            return [];
        }
        $supported = array_map([self::class, 'normaliseNumber'], self::numberTokens($source));
        $words = NumberWords::valuesIn($source, $language);
        $covered = $words !== null;
        if ($words !== null) {
            $supported = array_merge($supported, $words);
        }
        if ($date !== null) {
            foreach (['UTC', function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC'] as $zone) {
                try {
                    $local = (new \DateTimeImmutable('@' . $date))->setTimezone(new \DateTimeZone($zone));
                } catch (\Exception) {
                    continue;
                }
                $supported[] = $local->format('Y');
                $supported[] = $local->format('j');
                $supported[] = $local->format('n');
            }
        }
        $supported = array_flip($supported);
        $out = [];
        foreach ($found as $token) {
            $value = self::normaliseNumber($token);
            if (!isset($supported[$value])) {
                $out[] = [$token, $covered];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function numberTokens(string $text): array
    {
        if (preg_match_all('/\p{Nd}+(?:[.,\x{00A0}\x{202F}\x{2009}]\p{Nd}+|\x{20}\p{Nd}{3}(?!\p{Nd}))*/u', $text, $m) === 0) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (string $t): string => rtrim($t, '.,'), $m[0])));
    }

    /**
     * Separators normalised: "1,200", "1 200" and "1.200,00" compare equal
     * to "1200"; a decimal comma becomes a point; native digits become ASCII.
     */
    public static function normaliseNumber(string $token): string
    {
        $ascii = '';
        foreach (mb_str_split($token, 1, 'UTF-8') as $char) {
            $ascii .= self::asciiDigit($char);
        }
        $token = (string) preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]/u', '', $ascii);
        $hasComma = str_contains($token, ',');
        $hasPoint = str_contains($token, '.');
        if ($hasComma && $hasPoint) {
            if (strrpos($token, ',') > strrpos($token, '.')) {
                $token = str_replace(',', '.', str_replace('.', '', $token));
            } else {
                $token = str_replace(',', '', $token);
            }
        } elseif ($hasComma) {
            if (preg_match('/^\d{1,3}(,\d{3})+$/', $token) === 1 || preg_match('/^\d{1,3}(,\d{2})*,\d{3}$/', $token) === 1) {
                $token = str_replace(',', '', $token);
            } else {
                $token = str_replace(',', '.', $token);
            }
        } elseif ($hasPoint && preg_match('/^\d{1,3}(\.\d{3}){2,}$/', $token) === 1) {
            $token = str_replace('.', '', $token);
        }
        if (str_contains($token, '.')) {
            [$int, $frac] = explode('.', $token, 2);
            $frac = rtrim($frac, '0');
            $int = ltrim($int, '0');
            $token = ($int === '' ? '0' : $int) . ($frac !== '' ? '.' . $frac : '');
        } else {
            $token = ltrim($token, '0');
            $token = $token === '' ? '0' : $token;
        }

        return $token;
    }

    private static function asciiDigit(string $char): string
    {
        if (preg_match('/^\p{Nd}$/u', $char) !== 1 || ctype_digit($char)) {
            return $char;
        }
        $code = mb_ord($char, 'UTF-8');
        foreach ([0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0DE6, 0x0E50, 0x0ED0, 0x0F20, 0x1040, 0xFF10] as $zero) {
            if ($code >= $zero && $code <= $zero + 9) {
                return (string) ($code - $zero);
            }
        }

        return $char;
    }

    /**
     * Passages of four words or more inside quotation marks.
     *
     * @return list<string>
     */
    public static function quotes(string $text): array
    {
        $out = [];
        $patterns = [
            '/\x{201C}([^\x{201C}\x{201D}]+)\x{201D}/u',
            '/"([^"]+)"/u',
            '/\x{00AB}\s*([^\x{00AB}\x{00BB}]+?)\s*\x{00BB}/u',
            '/\x{201E}([^\x{201E}\x{201C}\x{201D}]+)[\x{201C}\x{201D}]/u',
            '/\x{300C}([^\x{300C}\x{300D}]+)\x{300D}/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $m) > 0) {
                foreach ($m[1] as $passage) {
                    if (Text::wordCount($passage) >= 4) {
                        $out[] = trim($passage);
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }
}
