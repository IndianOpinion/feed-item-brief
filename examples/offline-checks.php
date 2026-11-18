<?php

/**
 * Runs without WordPress: php examples/offline-checks.php
 *
 * Shows the article key, byline clean-up, date parsing and the output
 * checks on a made-up article.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

use IndianOpinion\FeedItemBrief\Byline;
use IndianOpinion\FeedItemBrief\DateParser;
use IndianOpinion\FeedItemBrief\OutputChecker;
use IndianOpinion\FeedItemBrief\Url;

echo 'Article key: ', Url::articleKey('https://m.example.org/a/b/amp/?utm_source=x&id=2#top'), "\n";
echo 'Same key:    ', Url::articleKey('http://www.example.org/a/b?id=2'), "\n";
echo 'Byline:      ', Byline::join(Byline::normalise(['By Jane Roe', 'jroe@example.org (Jane Roe)', 'Sam Lee'])), "\n";

$parsed = DateParser::parse('2026-11-14 10:00:00', 'Asia/Kolkata');
echo 'Date:        ', gmdate('c', (int) $parsed['time']), $parsed['assumed'] ? ' (zone assumed)' : '', "\n";

$source = 'The council approved the plan on Tuesday. Two hundred people attended. The mayor said: "We will open the bridge by spring."';
$answer = [
    'headline' => 'Council approves the river bridge plan',
    'summary' => [
        'The council approved the plan on Tuesday, with 200 people attending. Reports differ on the cost.',
        'Word count: 21',
    ],
    'language' => 'en',
    'insufficient' => false,
];
$result = (new OutputChecker())->check($answer, $source, 'Bridge plan approved', 'en', 80);
echo 'Passed:      ', $result->passed() ? 'yes' : 'no', "\n";
echo 'Failures:    ', implode('; ', $result->failures), "\n";
echo 'Kept:        ', implode(' | ', $result->paragraphs), "\n";
