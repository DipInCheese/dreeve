<?php

declare(strict_types=1);

namespace App\Domain\Insight\Overview;

use App\Domain\Insight\Insight;
use App\Domain\Insight\InsightProvider;
use App\Domain\Insight\InsightProviderUnavailable;
use App\Domain\Insight\InsightRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * Writes the AI overview of the current moment of the day (08:00, 13:00, 20:00), once.
 */
final readonly class OverviewWriter
{
    private const string INSTRUCTIONS = <<<'PROMPT'
        You are the health and training coach inside Dreeve, a personal health app. You get facts about the user as JSON:
        today so far, last night, their training (plan and the last 7 days, with a training load per day), and the last
        7 days of health, where every metric comes with the user's own normal range and whether it was below, within or above it.

        Write a short overview for the user. %s

        Rules:
        - Judge numbers against the user's own normal range, not against general population values.
        - Training plan: when training.todayIsAPlannedRestDay is true, today is a rest day. Never suggest a workout, a run,
          a ride or any training then, also not a light one: suggest recovery (an easy walk, mobility, stretching, sleep).
        - Recent training: look at training.activitiesLast7Days and training.trainingLoadPerDay. After a hard or long
          session yesterday, put recovery first, even when recovery numbers look good. Never suggest more training than the plan.
        - Only use numbers from the facts. Never invent data. If something is missing, leave it out.
        - Sleep score, stress, Body Battery and training readiness are scores from 0 to 100, not percentages.
        - Be concrete, warm and brief. Address the user as "you". Metric units only.
        - You are not a doctor. Only when numbers look worrying for several days, suggest seeing one.

        Answer with JSON only, nothing before or after it:
        {"headline": "at most 10 words", "summary": "2 or 3 sentences", "tips": ["1 to 3 short, concrete tips"]}
        PROMPT;

    public function __construct(
        private InsightProvider $insightProvider,
        private InsightRepository $insightRepository,
        private OverviewFacts $overviewFacts,
    ) {
    }

    /**
     * The overview due now, or null when none is due: AI is off, it is before 08:00, the current
     * moment already has one, or there is no health data to talk about.
     */
    public function dueAt(SerializableDateTime $now): ?OverviewSlot
    {
        if (!$this->insightProvider->isEnabled() || !($slot = OverviewSlot::dueAt($now)) instanceof OverviewSlot) {
            return null;
        }

        return $this->insightRepository->find(Overview::insightId($slot, $now->format('Y-m-d'))) instanceof Insight ? null : $slot;
    }

    /**
     * @throws InsightProviderUnavailable when the model can not be reached, try again later
     */
    public function write(OverviewSlot $slot, SerializableDateTime $now): ?Overview
    {
        $facts = $this->overviewFacts->for($slot, $now);
        if (!OverviewFacts::hasHealthData($facts)) {
            return null;
        }

        $written = $this->insightProvider->write(sprintf(self::INSTRUCTIONS, $slot->focus()), $facts);
        $content = Overview::contentFromAnswer($written->content)
            ?? throw new InsightProviderUnavailable('The model did not answer in the asked format.');

        $day = $now->format('Y-m-d');
        $insight = new Insight(
            id: Overview::insightId($slot, $day),
            type: Overview::INSIGHT_TYPE,
            periodStart: $day,
            periodEnd: $day,
            content: $content,
            model: $written->model,
            createdOn: $now->format('Y-m-d H:i:s'),
        );
        $this->insightRepository->save($insight);

        return Overview::fromInsight($insight);
    }
}
