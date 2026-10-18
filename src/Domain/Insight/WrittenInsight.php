<?php

declare(strict_types=1);

namespace App\Domain\Insight;

final readonly class WrittenInsight
{
    public function __construct(
        public string $content,
        // Which provider and model wrote it, e.g. "ollama/gpt-oss:20b".
        public string $model,
    ) {
    }
}
