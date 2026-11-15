<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Integration;

use IndianOpinion\FeedItemBrief\Installer;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\WpdbRepository;
use PHPUnit\Framework\TestCase;

/**
 * Runs the database repository against a real MySQL or MariaDB server.
 * Skipped unless FIB_TEST_DB_HOST or FIB_TEST_DB_SOCKET is set, with
 * FIB_TEST_DB_USER, FIB_TEST_DB_PASSWORD and FIB_TEST_DB_NAME.
 */
final class WpdbRepositoryTest extends TestCase
{
    private \wpdb $db;
    private WpdbRepository $repo;

    protected function setUp(): void
    {
        $host = (string) getenv('FIB_TEST_DB_HOST');
        $socket = (string) getenv('FIB_TEST_DB_SOCKET');
        if ($host === '' && $socket === '') {
            self::markTestSkipped('Set FIB_TEST_DB_HOST or FIB_TEST_DB_SOCKET to run the database tests.');
        }
        \WpStub::reset();
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $link = new \mysqli(
            $host !== '' ? $host : 'localhost',
            (string) (getenv('FIB_TEST_DB_USER') ?: 'root'),
            (string) getenv('FIB_TEST_DB_PASSWORD'),
            (string) (getenv('FIB_TEST_DB_NAME') ?: 'feed_item_brief_test'),
            (int) (getenv('FIB_TEST_DB_PORT') ?: 3306),
            $socket !== '' ? $socket : null
        );
        $link->set_charset('utf8mb4');
        $this->db = new \wpdb($link);
        $this->db->prefix = 'fibtest_';
        $GLOBALS['wpdb'] = $this->db;
        Installer::drop($this->db);
        Installer::install($this->db);
        $this->repo = new WpdbRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            Installer::drop($this->db);
        }
        $GLOBALS['wpdb'] = null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(array $row = []): int
    {
        return $this->repo->insert($row + [
            'adapter' => 'core-feed',
            'source_key' => 'k',
            'key_hash' => hash('sha256', 'example.org/a'),
            'guid_hash' => hash('sha256', 'g'),
            'url' => 'https://example.org/a',
            'guid' => 'g',
            'feed_url' => '',
            'title' => "It\u{2019}s a title",
            'source_name' => 'Example',
            'byline' => ['Jane Roe'],
            'language' => 'en',
            'resolved_at' => 1000,
            'status' => Status::QUEUED,
            'failures' => [],
            'body_text' => 'Body',
            'body_html' => '<p>Body</p>',
            'body_hash' => 'h',
            'meta' => ['item' => ['published' => '2026-11-14']],
            'result' => '',
            'not_before' => 0,
            'created_at' => 100,
            'updated_at' => 100,
        ]);
    }

    public function testSchemaIsCompleteAfterInstall(): void
    {
        self::assertSame([], Installer::missingColumns($this->db));
        self::assertSame(Installer::DB_VERSION, \WpStub::$options[Installer::VERSION_OPTION]);
    }

    public function testMissingColumnIsDetected(): void
    {
        $this->db->query('ALTER TABLE `fibtest_feed_item_brief_items` DROP COLUMN body_hash');
        self::assertSame(['fibtest_feed_item_brief_items.body_hash'], Installer::missingColumns($this->db));
    }

    public function testInsertFindAndUpdateRoundTrip(): void
    {
        $id = $this->insert();
        $record = $this->repo->find($id);
        self::assertNotNull($record);
        self::assertSame("It\u{2019}s a title", $record->title);
        self::assertSame(['Jane Roe'], $record->bylines);
        self::assertSame('2026-11-14', $record->meta['item']['published']);
        self::assertNull($record->finalAt);

        $this->repo->update($id, ['status' => Status::DRAFTED, 'post_id' => 55, 'final_at' => 200, 'failures' => ['x'], 'lease_token' => null]);
        $record = $this->repo->find($id);
        self::assertNotNull($record);
        self::assertSame(55, $record->postId);
        self::assertSame(['x'], $record->failures);
        self::assertSame($id, $this->repo->findByPost(55)?->id);
        self::assertSame($id, $this->repo->findByKey(hash('sha256', 'example.org/a'))?->id);
        self::assertSame($id, $this->repo->findByGuid('core-feed', hash('sha256', 'g'))?->id);
        self::assertSame($id, $this->repo->findSeen($id, 'core-feed', 'k', 'h')?->id);
        self::assertSame([Status::DRAFTED => 1], $this->repo->countByStatus());
        self::assertCount(1, $this->repo->search(['status' => Status::DRAFTED, 'since' => 50]));
    }

    public function testLeasesAndOrder(): void
    {
        $old = $this->insert(['resolved_at' => 500]);
        $new = $this->insert(['resolved_at' => 900, 'key_hash' => 'other']);

        $first = $this->repo->claimNext(1000, 'a', 300);
        self::assertSame($new, $first?->id, 'newest resolved time first');
        $second = $this->repo->claimNext(1000, 'b', 300);
        self::assertSame($old, $second?->id);
        self::assertNull($this->repo->claimNext(1000, 'c', 300), 'nothing left to claim');
        self::assertNull($this->repo->claim($new, 1000, 'd', 300));
        self::assertSame('e', $this->repo->claimNext(1301, 'e', 300)?->leaseToken, 'expired lease reclaimed');

        $this->repo->update($old, ['lease_token' => null, 'lease_expires' => null, 'final_at' => 5]);
        self::assertNull($this->repo->claim($old, 2000, 'f', 300), 'final rows are never claimed');
    }

    public function testCounterIsConditional(): void
    {
        self::assertTrue($this->repo->takeCall('2026-11-14', 2));
        self::assertTrue($this->repo->takeCall('2026-11-14', 2));
        self::assertFalse($this->repo->takeCall('2026-11-14', 2));
        self::assertSame(2, $this->repo->callsOn('2026-11-14'));
        $this->repo->refundCall('2026-11-14');
        self::assertSame(1, $this->repo->callsOn('2026-11-14'));
        self::assertFalse($this->repo->takeCall('2026-11-15', 0));
        self::assertSame(1, $this->repo->purgeDays('2026-11-15'));
    }

    public function testRetention(): void
    {
        $kept = $this->insert(['final_at' => 100, 'post_id' => 9]);
        $gone = $this->insert(['final_at' => 100, 'key_hash' => 'z']);
        self::assertSame(2, $this->repo->purgeBodies(200));
        self::assertSame('', $this->repo->find($kept)?->bodyText);
        self::assertSame('h', $this->repo->find($kept)?->bodyHash);
        self::assertSame(1, $this->repo->purgeRows(200));
        self::assertNull($this->repo->find($gone));
        self::assertNotNull($this->repo->find($kept));
    }
}
