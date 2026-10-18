<?php

namespace App\Tests\Domain\Insight\Overview;

use App\Domain\Insight\Insight;
use App\Domain\Insight\Overview\Overview;
use App\Domain\Insight\Overview\OverviewSlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OverviewTest extends TestCase
{
    #[DataProvider('provideMoments')]
    public function testTheMomentOfTheDayDecidesWhichOverviewIsDue(string $time, ?OverviewSlot $expected): void
    {
        $this->assertSame($expected, OverviewSlot::dueAt(new \DateTimeImmutable('2026-10-18 '.$time)));
    }

    public static function provideMoments(): iterable
    {
        yield 'night' => ['03:00', null];
        yield 'just before eight' => ['07:59', null];
        yield 'eight' => ['08:00', OverviewSlot::MORNING];
        // Still the morning one: when the PC was off at 08:00, it is tried again until 13:00.
        yield 'late morning' => ['12:45', OverviewSlot::MORNING];
        yield 'one' => ['13:00', OverviewSlot::AFTERNOON];
        yield 'eight in the evening' => ['20:00', OverviewSlot::EVENING];
        yield 'late evening' => ['23:59', OverviewSlot::EVENING];
    }

    public function testItTakesTheJsonOutOfTheModelsAnswer(): void
    {
        $content = Overview::contentFromAnswer("Sure! Here it is:\n```json\n{\"headline\": \"A calm day\", \"summary\": \"You slept well.\", \"tips\": [\"Walk\", \"\", \"Stretch\", \"Read\", \"Too many\"]}\n```");

        $this->assertSame('{"headline":"A calm day","summary":"You slept well.","tips":["Walk","Stretch","Read"]}', $content);
    }

    public function testAnAnswerInAnotherShapeIsRefused(): void
    {
        $this->assertNull(Overview::contentFromAnswer('You slept well today.'));
        $this->assertNull(Overview::contentFromAnswer('{"title": "no headline or summary"}'));
    }

    public function testHtmlFromTheModelIsDropped(): void
    {
        $content = (string) Overview::contentFromAnswer('{"headline": "<b>Rested</b><script>alert(1)</script>", "summary": "Fine.", "tips": []}');

        $this->assertStringNotContainsString('<', $content);
        $this->assertStringContainsString('Rested', $content);
    }

    public function testItIsReadBackFromTheStoredInsight(): void
    {
        $overview = Overview::fromInsight(new Insight(
            id: Overview::insightId(OverviewSlot::AFTERNOON, '2026-10-18'),
            type: Overview::INSIGHT_TYPE,
            periodStart: '2026-10-18',
            periodEnd: '2026-10-18',
            content: '{"headline":"Steady","summary":"All within your normal.","tips":["Drink water"]}',
            model: 'ollama/gpt-oss:20b',
            createdOn: '2026-10-18 13:00:12',
        ));

        $this->assertNotNull($overview);
        $this->assertSame(OverviewSlot::AFTERNOON, $overview->slot);
        $this->assertSame('Steady', $overview->headline);
        $this->assertSame(['Drink water'], $overview->tips);
        $this->assertSame('2026-10-18 13:00', $overview->writtenOn->format('Y-m-d H:i'));
    }
}
