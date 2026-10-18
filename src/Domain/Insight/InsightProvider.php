<?php

declare(strict_types=1);

namespace App\Domain\Insight;

/**
 * Writes an analysis from facts Dreeve prepared, for example with an AI model.
 *
 * A provider only gets those facts (a HealthSnapshot and today's numbers, already compared with the
 * user's normal), never the raw data. It must only be used when the user switched AI on, because the
 * facts then leave the server for the chosen AI provider (unless that is a model on the own network).
 */
interface InsightProvider
{
    /**
     * Whether the user switched AI on and it is configured.
     */
    public function isEnabled(): bool;

    /**
     * @param array<string, mixed> $facts
     *
     * @throws InsightProviderUnavailable when the model can not be reached right now
     */
    public function write(string $instructions, array $facts): WrittenInsight;
}
