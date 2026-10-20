<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Tool;

use App\Domain\Health\Snapshot\HealthSnapshotBuilder;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Time\Clock\Clock;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class GetHealthSnapshot extends Tool
{
    private const int MAX_DAYS = 90;

    #[\Override]
    protected string $name = 'get_health_snapshot';

    #[\Override]
    protected ?string $description = <<<DESC
        Retrieves a summary of the user's health and training over the last given number of days, up to and including today.
        Per metric (sleep, sleep score, resting heart rate, HRV, stress, Body Battery, steps, intensity minutes,
        training readiness, weight) it gives the average for the period, the user's own normal range from before the period,
        whether the average was below, within or above that normal, and the change from the period before.
        It also gives the sleep rhythm (average bedtime and wake time, how much the bedtime moved), a training summary, and
        the user's training plan: which weekdays they train and whether today is a planned rest day.
        Use this tool whenever the user asks how they are doing, how they slept, whether they recovered, or what to adjust.
        Always judge a number against the user's own normal, not against general population values.
        DESC;

    public function __construct(
        private readonly HealthSnapshotBuilder $healthSnapshotBuilder,
        private readonly Clock $clock,
        private readonly SettingsRepository $settingsRepository,
    ) {
    }

    /**
     * @return \NeuronAI\Tools\ToolPropertyInterface[]
     *
     * @codeCoverageIgnore
     */
    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'days',
                type: PropertyType::INTEGER,
                description: sprintf('How many days to summarise, from 1 to %d. Use 7 for "this week".', self::MAX_DAYS),
                required: true
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $days): array
    {
        $now = $this->clock->getCurrentDateTimeImmutable();
        $general = $this->settingsRepository->general();

        return [
            ...$this->healthSnapshotBuilder->lastDays(max(1, min(self::MAX_DAYS, $days)), $now)->toArray(),
            'trainingPlan' => [
                'today' => $now->format('l'),
                // null when the user did not set training days.
                'todayIsAPlannedRestDay' => $general->isPlannedRestDay($now),
                'plannedTrainingDays' => $general->getTrainingDays(),
            ],
        ];
    }
}
