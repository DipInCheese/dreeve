<?php

declare(strict_types=1);

namespace App\Domain\Insight;

use Doctrine\ORM\Mapping as ORM;

/**
 * A written analysis of a period, made by an InsightProvider from a HealthSnapshot.
 * Kept, so the Insights page can show it again without asking the provider twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'Insight')]
#[ORM\Index(name: 'Insight_typePeriod', columns: ['type', 'periodEnd'])]
final readonly class Insight
{
    public const string TYPE_WEEKLY = 'weekly';

    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        public string $id,
        #[ORM\Column(type: 'string')]
        public string $type,
        #[ORM\Column(type: 'string')]
        public string $periodStart,
        #[ORM\Column(type: 'string')]
        public string $periodEnd,
        // Markdown.
        #[ORM\Column(type: 'text')]
        public string $content,
        // Which provider and model wrote it, e.g. "ollama/llama3.1".
        #[ORM\Column(type: 'string')]
        public string $model,
        #[ORM\Column(type: 'string')]
        public string $createdOn,
    ) {
    }

    public static function idFor(string $type, string $periodStart, string $periodEnd): string
    {
        return sprintf('%s:%s..%s', $type, $periodStart, $periodEnd);
    }
}
