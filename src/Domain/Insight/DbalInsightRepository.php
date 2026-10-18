<?php

declare(strict_types=1);

namespace App\Domain\Insight;

use App\Infrastructure\Repository\DbalRepository;

final readonly class DbalInsightRepository extends DbalRepository implements InsightRepository
{
    public function save(Insight $insight): void
    {
        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO Insight (id, type, periodStart, periodEnd, content, model, createdOn)
             VALUES (:id, :type, :periodStart, :periodEnd, :content, :model, :createdOn)',
            get_object_vars($insight),
        );
    }

    public function find(string $id): ?Insight
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM Insight WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function findLatest(string $type): ?Insight
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM Insight WHERE type = :type ORDER BY createdOn DESC LIMIT 1',
            ['type' => $type],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Insight
    {
        return new Insight(
            id: (string) $row['id'],
            type: (string) $row['type'],
            periodStart: (string) $row['periodStart'],
            periodEnd: (string) $row['periodEnd'],
            content: (string) $row['content'],
            model: (string) $row['model'],
            createdOn: (string) $row['createdOn'],
        );
    }
}
