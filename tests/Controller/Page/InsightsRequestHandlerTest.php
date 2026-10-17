<?php

namespace App\Tests\Controller\Page;

use App\Domain\Health\DailySummary;
use App\Domain\Health\HealthRecords;
use App\Domain\Health\HealthRepository;
use App\Infrastructure\Time\Clock\Clock;
use App\Tests\Controller\Admin\AdminWebTestCase;

class InsightsRequestHandlerTest extends AdminWebTestCase
{
    public function testItSaysWhatIsComingEvenWithoutActivities(): void
    {
        $crawler = $this->client->request('GET', '/insights');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-insights-coming]'));
        $this->assertCount(1, $crawler->filter('[data-insights-empty] a[href="/admin/garmin"]'));
        $this->assertCount(0, $crawler->filter('[data-weekly-summary]'));
        $this->assertStringContainsString('only if you switch it on', $crawler->filter('[data-insights-coming]')->text());
        $this->assertCount(1, $crawler->filter('a[href="/insights"][aria-selected="true"]'));
    }

    public function testItSumsUpTheLastSevenDays(): void
    {
        $today = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable();
        $records = HealthRecords::empty();
        for ($daysAgo = 30; $daysAgo >= 0; --$daysAgo) {
            $records->addDailySummary(new DailySummary(
                day: $today->modify(sprintf('-%d days', $daysAgo))->format('Y-m-d'),
                source: 'garmin',
                restingHeartRate: $daysAgo < 7 ? 58 : 47 + ($daysAgo % 3),
            ));
        }
        $this->getContainer()->get(HealthRepository::class)->save($records);

        $crawler = $this->client->request('GET', '/insights');

        $restingHeartRate = $crawler->filter('[data-weekly-summary] [data-week-metric="resting-heart-rate"]');
        $this->assertStringContainsString('58 bpm', $restingHeartRate->text());
        $this->assertStringContainsString('Above your normal', $restingHeartRate->text());
        $this->assertStringContainsString('more than the week before', $restingHeartRate->text());
        $this->assertCount(0, $crawler->filter('[data-insights-empty]'));
    }

    public function testTheSidebarGroupsHealthTrainingAndSettings(): void
    {
        $crawler = $this->client->request('GET', '/insights');

        $sidebar = $crawler->filter('aside')->first()->text();
        foreach (['Today', 'Training', 'Insights', 'Data & settings'] as $label) {
            $this->assertStringContainsString($label, $sidebar);
        }
        $this->assertLessThan(strpos($sidebar, 'Training'), strpos($sidebar, 'Today'));
        $this->assertLessThan(strpos($sidebar, 'Data & settings'), strpos($sidebar, 'Training'));
    }
}
