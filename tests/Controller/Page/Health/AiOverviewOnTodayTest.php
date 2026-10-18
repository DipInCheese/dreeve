<?php

namespace App\Tests\Controller\Page\Health;

use App\Domain\Insight\Insight;
use App\Domain\Insight\InsightRepository;
use App\Domain\Insight\Overview\Overview;
use App\Domain\Insight\Overview\OverviewSlot;
use App\Domain\Settings\DbalSettingsRepository;
use App\Domain\Settings\SettingsGroup;
use App\Infrastructure\Time\Clock\Clock;
use App\Tests\Controller\Admin\AdminWebTestCase;

class AiOverviewOnTodayTest extends AdminWebTestCase
{
    public function testTheLatestOverviewIsOnTopOfToday(): void
    {
        $this->enableAi(true);
        $this->storeOverview('-2 hours', '{"headline":"Well rested <b>today</b>","summary":"Your sleep was within your normal.","tips":["Go for a run"]}');

        $crawler = $this->client->request('GET', '/today');

        $overview = $crawler->filter('[data-ai-overview="morning"]');
        $this->assertCount(1, $overview);
        $this->assertStringContainsString('Your sleep was within your normal.', $overview->text());
        $this->assertStringContainsString('Go for a run', $overview->text());
        $this->assertStringContainsString('Written at', $overview->text());
        // Text from the model is never HTML.
        $this->assertCount(0, $overview->filter('b'));
    }

    public function testNoOverviewWhenAiIsOff(): void
    {
        $this->enableAi(false);
        $this->storeOverview('-2 hours', '{"headline":"Hi","summary":"Fine.","tips":[]}');

        $crawler = $this->client->request('GET', '/today');

        $this->assertCount(0, $crawler->filter('[data-ai-overview]'));
    }

    public function testAnOverviewOlderThanADayIsNotShown(): void
    {
        $this->enableAi(true);
        $this->storeOverview('-2 days', '{"headline":"Old news","summary":"Fine.","tips":[]}');

        $crawler = $this->client->request('GET', '/today');

        $this->assertCount(0, $crawler->filter('[data-ai-overview]'));
    }

    private function storeOverview(string $ago, string $content): void
    {
        $writtenOn = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable()->modify($ago);
        $this->getContainer()->get(InsightRepository::class)->save(new Insight(
            id: Overview::insightId(OverviewSlot::MORNING, $writtenOn->format('Y-m-d')),
            type: Overview::INSIGHT_TYPE,
            periodStart: $writtenOn->format('Y-m-d'),
            periodEnd: $writtenOn->format('Y-m-d'),
            content: $content,
            model: 'ollama/gpt-oss:20b',
            createdOn: $writtenOn->format('Y-m-d H:i:s'),
        ));
    }

    private function enableAi(bool $enabled): void
    {
        $this->getContainer()->get(DbalSettingsRepository::class)->saveGroup(SettingsGroup::INTEGRATIONS, [
            'ai' => [
                'enabled' => $enabled,
                'provider' => 'ollama',
                'configuration' => ['model' => 'gpt-oss:20b', 'url' => 'http://192.168.1.10:11434/api'],
            ],
        ]);
    }
}
