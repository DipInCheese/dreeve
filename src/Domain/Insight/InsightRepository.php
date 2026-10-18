<?php

declare(strict_types=1);

namespace App\Domain\Insight;

interface InsightRepository
{
    public function save(Insight $insight): void;

    public function find(string $id): ?Insight;

    public function findLatest(string $type): ?Insight;
}
