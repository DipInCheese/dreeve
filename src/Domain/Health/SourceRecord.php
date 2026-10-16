<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Doctrine\ORM\Mapping as ORM;

/**
 * A payload exactly as its source delivered it, compressed. When parsing improves, the health data
 * can be rebuilt from these without fetching anything again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'SourceRecord')]
final readonly class SourceRecord
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        public string $id,
        #[ORM\Column(type: 'string')]
        public string $source,
        #[ORM\Column(type: 'string')]
        public string $kind,
        #[ORM\Column(type: 'string')]
        public string $recordKey,
        #[ORM\Column(type: 'string')]
        public string $receivedOn,
        #[ORM\Column(type: 'blob')]
        public string $payload,
    ) {
    }
}
