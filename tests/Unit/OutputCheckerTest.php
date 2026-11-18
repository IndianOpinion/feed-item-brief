<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Meta;
use IndianOpinion\FeedItemBrief\OutputChecker;
use IndianOpinion\FeedItemBrief\ReviewPanel;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\FakeModel;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

final class OutputCheckerTest extends TestCase
{
    private const SOURCE = "The mayor said: \u{201C}We will open the bridge by spring next year.\u{201D} The project cost 1,200,000 dollars and two hundred workers joined. Officials said the plan was approved yesterday.";

    /**
     * @param list<string> $paragraphs
     * @return \IndianOpinion\FeedItemBrief\CheckResult
     */
    private function check(array $paragraphs, ?string $language = 'en', int $max = 170)
    {
        return (new OutputChecker())->check(FakeModel::answer($paragraphs), self::SOURCE, 'Bridge to open in spring', $language, $max);
    }

    /** Test 26 (case 30, 41). */
    public function testRelativeTimeWordsAreFlaggedNotRejected(): void
    {
        $result = $this->check(['Officials approved the plan today, and the mayor spoke about the bridge.']);
        self::assertTrue($result->passed());
        self::assertSame(['relative_time: today'], $result->flags);

        $inSource = $this->check(['Officials said the plan was approved yesterday.']);
        self::assertSame([], $inSource->flags);
    }

    public function testRelativeTimeFlagReachesTheReviewPanel(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(FakeModel::answer(['The council met yesterday and approved the plan for the river bridge after a long debate.']));
        $this->plugin->processor->run();
        $record = $this->record(1);

        self::assertSame(Status::DRAFTED, $record->status);
        self::assertSame(['relative_time: yesterday'], ReviewPanel::data((int) $record->postId)['relative_time']);
    }

    /** Test 37 (case 41). */
    public function testInventedQuoteFailsAndARealOnePasses(): void
    {
        $invented = $this->check(["The mayor said \u{201C}we have no money for the bridge\u{201D} at the meeting."]);
        self::assertFalse($invented->passed());
        self::assertStringStartsWith('unsupported_quote', $invented->failures[0]);

        $real = $this->check(['The mayor said "we will open the bridge by spring" and took questions.']);
        self::assertTrue($real->passed(), implode('; ', $real->failures));

        $short = $this->check(['The mayor called it "a fine bridge" and left.']);
        self::assertTrue($short->passed(), 'quotes under four words are not checked');
    }

    /** Test 38 (case 41). */
    public function testSingleSourcePhrasesFail(): void
    {
        $result = $this->check(['Reports differ on when the bridge will open.']);
        self::assertContains('single_source_phrase: reports differ', $result->failures);
        self::assertFalse($this->check(['According to reports, the bridge opens in spring.'])->passed());
    }

    /** Test 39 (case 41). */
    public function testNarrationLinesAreRemovedAndTheRestKept(): void
    {
        $result = (new OutputChecker())->check([
            'headline' => 'Headline: Bridge to open by spring next year',
            'summary' => ["The mayor said the bridge opens in spring.\nWord count: 112", "Note: the source does not say who pays.", "## Background\n- a bullet\nWorkers joined the project. [Note: check this]"],
            'language' => 'en',
            'insufficient' => false,
        ], self::SOURCE, 'Bridge', 'en', 170);

        self::assertTrue($result->passed(), implode('; ', $result->failures));
        self::assertSame('Bridge to open by spring next year', $result->headline);
        self::assertSame(['The mayor said the bridge opens in spring.', 'Workers joined the project.'], $result->paragraphs);
        self::assertSame(5, $result->removedLines);
    }

    public function testNumbersWithSeparatorsAndWords(): void
    {
        self::assertTrue($this->check(['The project cost 1.2 million dollars? No: it cost 1,200,000 dollars.'])->passed() === false, '1.2 alone is not in the source');
        self::assertTrue($this->check(['The project cost 1,200,000 dollars.'])->passed());
        self::assertTrue($this->check(['The project cost 1 200 000 dollars.'])->passed());
        self::assertTrue($this->check(['About 200 workers joined.'])->passed(), 'two hundred in words');
        self::assertTrue($this->check(['Two workers joined the 200 others.'])->passed());
        $bad = $this->check(['About 300 workers joined.']);
        self::assertSame(['unsupported_number: 300'], $bad->failures);
    }

    public function testNumberCheckPassesWithAFlagForLanguagesWithoutAList(): void
    {
        $answer = FakeModel::answer(['300 travailleurs ont rejoint le projet du pont.'], 'Le pont ouvrira au printemps prochain', 'fr');
        $result = (new OutputChecker())->check($answer, 'Deux cents travailleurs ont rejoint le projet.', 'Pont', 'fr', 170);
        self::assertTrue($result->passed(), implode('; ', $result->failures));
        self::assertContains('unverified_number: 300', $result->flags);
    }

    public function testDateNumbersAreSupported(): void
    {
        $result = (new OutputChecker())->check(FakeModel::answer(['On 14 November 2026 the mayor spoke about the bridge.']), self::SOURCE, 'Bridge', 'en', 170, (new \DateTimeImmutable('2026-11-14T09:00:00Z'))->getTimestamp());
        self::assertTrue($result->passed(), implode('; ', $result->failures));
    }

    public function testNumberWordsFilterAddsALanguage(): void
    {
        add_filter('feed_item_brief_number_words', static function (array $lists): array {
            $lists['fr'] = ['units' => ['deux' => 2, 'trois' => 3], 'scales' => ['cent' => 100]];

            return $lists;
        });
        $result = (new OutputChecker())->check(FakeModel::answer(['Le conseil a vu 300 personnes au pont.'], 'Le conseil approuve le pont de la ville', 'fr'), 'Trois cent personnes sont venues au pont.', 'Pont', 'fr', 170);
        self::assertTrue($result->passed(), implode('; ', $result->failures));
        $bad = (new OutputChecker())->check(FakeModel::answer(['Le conseil a vu 400 personnes au pont.'], 'Le conseil approuve le pont de la ville', 'fr'), 'Trois cent personnes sont venues au pont.', 'Pont', 'fr', 170);
        self::assertSame(['unsupported_number: 400'], $bad->failures);
    }

    public function testNativeDigitsNormalise(): void
    {
        self::assertSame('1200', OutputChecker::normaliseNumber("\u{0967}\u{0968}\u{0966}\u{0966}"));
        self::assertSame('1200', OutputChecker::normaliseNumber('1,200'));
        self::assertSame('100000', OutputChecker::normaliseNumber('1,00,000'));
        self::assertSame('3.5', OutputChecker::normaliseNumber('3,5'));
        self::assertSame('1200.5', OutputChecker::normaliseNumber('1.200,50'));
    }

    public function testOverLengthAndWrongLanguage(): void
    {
        $long = $this->check([trim(str_repeat('The mayor spoke. ', 40))], 'en', 60);
        self::assertSame(['over_length: 120 words, ceiling 66'], $long->failures);

        $wrong = $this->check(['The mayor spoke about the bridge.'], 'de');
        self::assertSame(['wrong_language: en, expected de'], $wrong->failures);
        self::assertTrue($this->check(['The mayor spoke about the bridge.'], null)->passed(), 'unknown item language skips the check');
    }

    public function testBadShapeIsAHardFailure(): void
    {
        $result = (new OutputChecker())->check(['headline' => 'A headline long enough', 'summary' => 'not a list', 'language' => 'en', 'insufficient' => false], 'x', 'y', 'en', 100);
        self::assertStringStartsWith('bad_shape', $result->failures[0]);
        $tooMany = (new OutputChecker())->check(FakeModel::answer(['a', 'b', 'c', 'd']), 'x', 'y', 'en', 100);
        self::assertStringStartsWith('bad_shape', $tooMany->failures[0]);
    }

    public function testLiteralEscapesInOutputAreDecodedBeforeChecks(): void
    {
        $result = $this->check(['The mayor’s bridge opens in spring.']);
        self::assertSame(["The mayor\u{2019}s bridge opens in spring."], $result->paragraphs);
    }

    /** Test 41 (case 43). */
    public function testRenderedLinkComesFromTheRecordNotTheModel(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(FakeModel::answer(['Rival Daily reported that the council met on Tuesday and approved the plan for the river bridge after a long debate.']));
        $this->plugin->processor->run();
        $postId = (int) $this->record(1)->postId;
        \WpStub::$currentPost = $postId;
        $html = apply_filters('the_content', \WpStub::$posts[$postId]->post_content);
        $this->plugin->frontend->hooks();
        $html = $this->plugin->frontend->ensureAttribution((string) $html);

        self::assertStringContainsString('<a href="https://news.example.org/2026/11/14/river-bridge" rel="nofollow noopener">Example News</a>', $html);
        self::assertSame('Example News', get_post_meta($postId, Meta::SOURCE_NAME, true));
    }
}
