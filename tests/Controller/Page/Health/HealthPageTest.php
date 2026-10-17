<?php

namespace App\Tests\Controller\Page\Health;

use App\Domain\Health\BodyMeasurement;
use App\Domain\Health\DailySummary;
use App\Domain\Health\HealthRecords;
use App\Domain\Health\HealthRepository;
use App\Domain\Health\HrvSummary;
use App\Domain\Health\SleepSession;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Tests\Controller\Admin\AdminWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HealthPageTest extends AdminWebTestCase
{
    public function testTodayAsksToConnectGarminWithoutHealthData(): void
    {
        $crawler = $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-health-empty-state] a[href="/admin/garmin"]'));
        $this->assertCount(0, $crawler->filter('[data-today-metrics]'));
        // The latest activity shows regardless.
        $this->assertCount(1, $crawler->filter('[data-latest-activity] a'));
        $this->assertSame('true', $crawler->filter('aside a[href="/today"]')->attr('aria-selected'));
    }

    public function testTodayShowsEveryNumberNextToYourNormal(): void
    {
        $this->seedThirtyDays();

        $crawler = $this->client->request('GET', '/today');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-health-empty-state]'));
        $restingHeartRate = $crawler->filter('[data-metric="resting-heart-rate"]');
        $this->assertStringContainsString('58', $restingHeartRate->text());
        $this->assertStringContainsString('bpm', $restingHeartRate->text());
        $this->assertStringContainsString('Above your normal', $restingHeartRate->text());
        $this->assertSame('/health/heart', $restingHeartRate->attr('href'));

        $hrv = $crawler->filter('[data-metric="hrv"]');
        // Garmin's own balanced range is used for HRV.
        $this->assertStringContainsString('Normal 49–63', $hrv->text());
        $this->assertStringContainsString('Within your normal', $hrv->text());

        $this->assertStringContainsString('7h 30m', $crawler->filter('[data-metric="sleep-duration"]')->text());
        $this->assertStringContainsString('No data yet', $crawler->filter('[data-metric="training-readiness"]')->text());
        $this->assertCount(1, $crawler->filter('[data-last-night]'));
        $this->assertStringContainsString('72.4', $crawler->filter('[data-latest-weight]')->text());
    }

    public function testTodayShowsWhereYourBodyBatteryIsNow(): void
    {
        $this->seedThirtyDays();

        $crawler = $this->client->request('GET', '/today');

        $bodyBattery = $crawler->filter('[data-metric="body-battery-high"]');
        // The big number is the latest reading; the peak of the day is what gets compared with your normal.
        $this->assertSame('41 now', trim($bodyBattery->filter('[data-current]')->text()));
        $this->assertStringContainsString('Charged to 80 today', $bodyBattery->text());
        $this->assertStringContainsString('Within your normal', $bodyBattery->text());
    }

    #[DataProvider('provideRanges')]
    public function testAHealthSectionChartsItsMetrics(string $range, int $expectedPoints): void
    {
        $this->seedThirtyDays();

        $crawler = $this->client->request('GET', '/health/heart?range='.$range);

        $this->assertResponseIsSuccessful();
        $this->assertSame('true', $crawler->filter('aside a[href="/health/heart"]')->attr('aria-selected'));
        $this->assertSame('?range='.$range, $crawler->filter('nav[aria-label="Period"] a[aria-current="page"]')->attr('href'));
        $this->assertSame(['resting-heart-rate', 'hrv'], $crawler->filter('[data-health-metric]')->each(fn ($node) => $node->attr('data-health-metric')));

        $chart = Json::decode((string) $crawler->filter('[data-health-metric="resting-heart-rate"] [data-echarts-options]')->attr('data-echarts-options'));
        $this->assertCount($expectedPoints, $chart['series'][0]['data']);
        $this->assertArrayHasKey('markArea', $chart['series'][0]);
    }

    public static function provideRanges(): iterable
    {
        yield '7 days' => ['7d', 7];
        yield '30 days' => ['30d', 30];
        yield 'a year' => ['365d', 365];
    }

    public function testAnEmptySectionSaysSo(): void
    {
        $this->seedThirtyDays();

        $crawler = $this->client->request('GET', '/health/fitness');

        $this->assertResponseIsSuccessful();
        // Steps were seeded; nothing else of fitness.
        $this->assertSame(['steps'], $crawler->filter('[data-health-metric]')->each(fn ($node) => $node->attr('data-health-metric')));
    }

    public function testAnUnknownSectionIsNotFound(): void
    {
        $this->client->request('GET', '/health/nope');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownRangeFallsBackToThirtyDays(): void
    {
        $crawler = $this->client->request('GET', '/health/sleep?range=forever');

        $this->assertResponseIsSuccessful();
        $this->assertSame('?range=30d', $crawler->filter('nav[aria-label="Period"] a[aria-current="page"]')->attr('href'));
    }

    private function seedThirtyDays(): void
    {
        $today = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable();
        $records = HealthRecords::empty();
        for ($daysAgo = 30; $daysAgo >= 0; --$daysAgo) {
            $day = $today->modify(sprintf('-%d days', $daysAgo))->format('Y-m-d');
            $records->addDailySummary(new DailySummary(
                day: $day,
                source: 'garmin',
                steps: 8000 + 100 * ($daysAgo % 7),
                // Steady between 46 and 50, then a high one today.
                restingHeartRate: 0 === $daysAgo ? 58 : 46 + ($daysAgo % 5),
                averageStress: 25,
                bodyBatteryHighest: 80,
                bodyBatteryMostRecent: 0 === $daysAgo ? 41 : 30,
            ));
            $records->addHrvSummary(new HrvSummary(
                day: $day,
                source: 'garmin',
                lastNightAverage: 55.0,
                weeklyAverage: 54.0,
                status: 'BALANCED',
                balancedLow: 49.0,
                balancedHigh: 63.0,
            ));
            $records->addSleepSession(new SleepSession(
                day: $day,
                source: 'garmin',
                sleepSeconds: 27000,
                deepSleepSeconds: 5400,
                lightSleepSeconds: 14400,
                remSleepSeconds: 7200,
                awakeSeconds: 1800,
                score: 80,
            ));
        }
        $records->addBodyMeasurement(new BodyMeasurement(
            id: 'garmin:1',
            source: 'garmin',
            day: $today->format('Y-m-d'),
            measuredOn: $today->format('Y-m-d').' 07:00:00',
            weightInKg: 72.4,
        ));
        $this->getContainer()->get(HealthRepository::class)->save($records);
    }
}
