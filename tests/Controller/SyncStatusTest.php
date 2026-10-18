<?php

namespace App\Tests\Controller;

use App\Domain\Health\DailySummary;
use App\Domain\Health\HealthRecords;
use App\Domain\Health\HealthRepository;
use App\Infrastructure\HealthSync\HealthSync;
use App\Infrastructure\HealthSync\HealthSyncFailed;
use App\Infrastructure\HealthSync\SyncStatus;
use App\Infrastructure\Time\Clock\Clock;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Infrastructure\HealthSync\SpyHealthSync;
use PHPUnit\Framework\Attributes\DataProvider;

class SyncStatusTest extends AdminWebTestCase
{
    private SpyHealthSync $healthSync;

    #[DataProvider('provideLastSyncs')]
    public function testTheHeaderSaysWhenGarminLastSynced(string $minutesAgo, string $expected): void
    {
        $now = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable();
        $this->healthSync->status = [
            'connected' => true,
            'lastSyncAt' => $now->modify($minutesAgo)->format(\DateTimeInterface::ATOM),
        ];

        $crawler = $this->client->request('GET', '/today');

        $this->assertStringContainsString($expected, $crawler->filter('[data-sync-status]')->text());
        $this->assertCount(1, $crawler->filter('[data-sync-status] .bg-green-500'));
        $this->assertCount(0, $crawler->filter('[data-garmin-banner]'));
    }

    public static function provideLastSyncs(): iterable
    {
        yield 'just now' => ['-20 seconds', 'Synced just now'];
        yield 'minutes' => ['-12 minutes', 'Synced 12 min ago'];
        yield 'hours' => ['-3 hours', 'Synced 3 h ago'];
        yield 'days' => ['-3 days', 'Synced on 14-10-2023'];
    }

    public function testItSaysWhenTheWatchHasNotUploadedToGarminForAWhile(): void
    {
        $now = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable();
        $this->healthSync->status = ['connected' => true, 'lastSyncAt' => $now->format(\DateTimeInterface::ATOM)];
        $this->seedWatchUpload($now->modify('-7 hours'));
        $expected = "Garmin hasn't received data from your watch since 09:15. Open the Garmin Connect app on your phone to sync it.";

        $crawler = $this->client->request('GET', '/today');
        $this->assertSame($expected, $crawler->filter('[data-stale-watch-hint]')->text());
        $this->assertSame($expected, $crawler->filter('[data-sync-status]')->attr('title'));
        $this->assertCount(1, $crawler->filter('[data-sync-status] .bg-amber-400'));
        $this->assertStringContainsString('Synced just now', $crawler->filter('[data-sync-status]')->text());

        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->assertSame($expected, $crawler->filter('[data-stale-watch-hint]')->text());

        // Only the Today page carries the full hint; the header still marks it everywhere.
        $crawler = $this->client->request('GET', '/activities');
        $this->assertCount(0, $crawler->filter('main [data-stale-watch-hint]'));
        $this->assertCount(1, $crawler->filter('[data-sync-status] [data-stale-watch]'));
    }

    public function testItGivesTheDayWhenTheWatchLastUploadedBeforeToday(): void
    {
        $now = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable();
        $this->healthSync->status = ['connected' => true, 'lastSyncAt' => $now->format(\DateTimeInterface::ATOM)];
        $this->seedWatchUpload($now->modify('-1 day -2 hours'));

        $crawler = $this->client->request('GET', '/today');

        $this->assertStringContainsString('since 16-10-2023 14:15.', $crawler->filter('[data-stale-watch-hint]')->text());
    }

    public function testItSaysNothingAboutTheWatchWhenItUploadedRecently(): void
    {
        $now = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable();
        $this->healthSync->status = ['connected' => true, 'lastSyncAt' => $now->format(\DateTimeInterface::ATOM)];
        $this->seedWatchUpload($now->modify('-2 hours'));

        $crawler = $this->client->request('GET', '/today');

        $this->assertCount(0, $crawler->filter('[data-stale-watch-hint]'));
        $this->assertCount(0, $crawler->filter('[data-stale-watch]'));
        $this->assertCount(1, $crawler->filter('[data-sync-status] .bg-green-500'));
    }

    public function testItSaysNothingAboutTheWatchWhenTheUploadIsUnknownOrGarminIsNotConnected(): void
    {
        $now = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable();
        $this->healthSync->status = ['connected' => true, 'lastSyncAt' => $now->format(\DateTimeInterface::ATOM)];

        $crawler = $this->client->request('GET', '/today');
        $this->assertCount(0, $crawler->filter('[data-stale-watch-hint]'));

        $this->seedWatchUpload($now->modify('-2 days'));
        $this->healthSync->status = ['needsLogin' => true];
        $this->getContainer()->get(SyncStatus::class)->forget();

        $crawler = $this->client->request('GET', '/today');
        $this->assertCount(0, $crawler->filter('[data-stale-watch-hint]'));
    }

    public function testItShowsASyncInProgress(): void
    {
        $this->healthSync->status = ['connected' => true, 'syncing' => true];

        $crawler = $this->client->request('GET', '/today');

        $this->assertStringContainsString('Syncing…', $crawler->filter('[data-sync-status]')->text());
    }

    public function testEveryPageAsksToLogInAgainWhenGarminEndedTheSession(): void
    {
        $this->healthSync->status = ['needsLogin' => true];

        foreach (['/today', '/activities', '/admin/upload'] as $path) {
            $crawler = $this->client->request('GET', $path);

            $this->assertCount(1, $crawler->filter('[data-garmin-banner] a[href="/admin/garmin"]'), $path);
            $this->assertStringContainsString('Garmin needs a new login', $crawler->filter('[data-sync-status]')->text(), $path);
        }
    }

    public function testItShowsNothingWithoutAGarminConnection(): void
    {
        $crawler = $this->client->request('GET', '/today');

        $this->assertCount(0, $crawler->filter('[data-sync-status]'));
        $this->assertCount(0, $crawler->filter('[data-garmin-banner]'));
    }

    public function testAnUnreachableServiceNeverBreaksAPage(): void
    {
        $this->healthSync->failure = HealthSyncFailed::unreachable();

        $crawler = $this->client->request('GET', '/today');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-sync-status]'));
    }

    private function seedWatchUpload(\DateTimeImmutable $uploadedAt): void
    {
        $records = HealthRecords::empty();
        $records->addDailySummary(new DailySummary(
            day: $uploadedAt->format('Y-m-d'),
            source: 'garmin',
            steps: 4200,
            watchUploadedAt: $uploadedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ));
        $this->getContainer()->get(HealthRepository::class)->save($records);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $healthSync = $this->getContainer()->get(HealthSync::class);
        assert($healthSync instanceof SpyHealthSync);
        $this->healthSync = $healthSync;
    }
}
