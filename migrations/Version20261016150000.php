<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016150000 extends AbstractMigration
{
    private const float KILOGRAMS_PER_POUND = 0.45359237;

    public function getDescription(): string
    {
        return 'Metric units only: weights entered in pounds become kilograms, the unit system setting goes.';
    }

    public function up(Schema $schema): void
    {
        $unitSystem = $this->connection->fetchOne(
            "SELECT value FROM Setting WHERE settingsGroup = 'appearance' AND name = 'unitSystem'"
        );

        if (is_string($unitSystem) && 'imperial' === json_decode($unitSystem)) {
            // Weights are stored in the unit system they were entered in.
            $weightHistory = $this->connection->fetchOne(
                "SELECT value FROM Setting WHERE settingsGroup = 'general' AND name = 'weightHistory'"
            );
            $weights = is_string($weightHistory) ? json_decode($weightHistory, true) : null;
            if (is_array($weights)) {
                foreach ($weights as $key => $entry) {
                    if (is_array($entry) && is_numeric($entry['weight'] ?? null)) {
                        $weights[$key]['weight'] = round((float) $entry['weight'] * self::KILOGRAMS_PER_POUND, 1);
                    }
                }
                $this->addSql(
                    "UPDATE Setting SET value = :value WHERE settingsGroup = 'general' AND name = 'weightHistory'",
                    ['value' => json_encode($weights, JSON_THROW_ON_ERROR)]
                );
            }
        }

        $this->addSql("DELETE FROM Setting WHERE settingsGroup = 'appearance' AND name = 'unitSystem'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dreeve only uses metric units.');
    }
}
