<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI;

use App\Domain\Insight\InsightProvider;
use App\Domain\Insight\InsightProviderUnavailable;
use App\Domain\Insight\WrittenInsight;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Serialization\Json;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Writes insights with the AI provider the user configured for the AI coach.
 *
 * @codeCoverageIgnore talks to a real model
 */
final readonly class NeuronInsightProvider implements InsightProvider
{
    public function __construct(
        private SettingsRepository $settingsRepository,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settingsRepository->integrations()->isAIIntegrationEnabled();
    }

    public function write(string $instructions, array $facts): WrittenInsight
    {
        $settings = $this->settingsRepository->integrations();
        try {
            $provider = $settings->getAIProvider();
            $answer = $provider
                ->systemPrompt($instructions)
                ->chat(new UserMessage("The facts, as JSON:\n".Json::encode($facts)))
                ->message()
                ->getContent();
        } catch (\Throwable $error) {
            throw new InsightProviderUnavailable($error->getMessage(), previous: $error);
        }

        if (null === $answer || '' === trim($answer)) {
            throw new InsightProviderUnavailable('The model gave an empty answer.');
        }

        return new WrittenInsight(
            content: $answer,
            model: sprintf('%s/%s', $settings->getAIProviderName(), $provider->getModel()),
        );
    }
}
