<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI;

use App\Domain\Settings\SettingsRepository;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * @codeCoverageIgnore
 */
final class NeuronAIAgent extends Agent
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly ToolkitInterface $toolkit,
        private readonly MessageStoreInterface $historyStore,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function workflowId(): string
    {
        return 'chat';
    }

    #[\Override]
    protected function provider(): AIProviderInterface
    {
        return $this->settingsRepository->integrations()->getAIProvider();
    }

    #[\Override]
    protected function tools(): array
    {
        return [$this->toolkit];
    }

    #[\Override]
    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a health and training coach. You look at the user\'s sleep, recovery and training together.',
                'Judge every health number against the user\'s own normal, which the health snapshot gives, not against general population values.',
                "Take into account the athlete's heart rate zones and FTP whenever you give feedback on intensity.",
                'You are not a doctor. For pain, illness or numbers that worry the user, suggest seeing a doctor.',
            ],
            steps: [
                'Answer the user’s question clearly and accurately.',
                'For questions about how the user is doing, sleeping or recovering, get the health snapshot first.',
                'Ask the user which activity they mean if you think you need it.',
            ],
            output: [
                'Ensure your response is fluent natural text.',
                'Include markdown to structure your response, but stay away from code blocks.',
                'You may use markdown lists or bullet points if they help clarify the response, but only if they add value.',
                'You may use markdown tables if they help clarify the response, but only if they add value.',
                'If you do not know the answer to a question, admit it honestly; do not fabricate information.',
            ]
        );
    }

    #[\Override]
    protected function messageStore(): MessageStoreInterface
    {
        return $this->historyStore;
    }
}
