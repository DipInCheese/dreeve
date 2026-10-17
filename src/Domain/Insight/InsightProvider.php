<?php

declare(strict_types=1);

namespace App\Domain\Insight;

use App\Domain\Health\Snapshot\HealthSnapshot;

/**
 * Writes an analysis of a period, for example with an AI model. Not implemented yet.
 *
 * A provider only gets the HealthSnapshot: the facts, already compared with the user's normal,
 * never the raw data. It must only be used when the user switched AI on, because the snapshot
 * then leaves the server for the chosen AI provider (unless that is a local model).
 */
interface InsightProvider
{
    /**
     * Whether the user switched this on and it is configured.
     */
    public function isEnabled(): bool;

    public function analyse(HealthSnapshot $snapshot, string $type): Insight;
}
