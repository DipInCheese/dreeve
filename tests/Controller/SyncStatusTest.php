<?php

namespace App\Tests\Controller;

use App\Infrastructure\HealthSync\HealthSync;
use App\Infrastructure\HealthSync\HealthSyncFailed;
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $healthSync = $this->getContainer()->get(HealthSync::class);
        assert($healthSync instanceof SpyHealthSync);
        $this->healthSync = $healthSync;
    }
}
