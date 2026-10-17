<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Best efforts over metric distances only: the stored ones are recalculated on the next import.';
    }

    public function up(Schema $schema): void
    {
        // Every activity without best efforts gets them calculated again, now over metric distances.
        $this->addSql('DELETE FROM ActivityBestEffort');
    }

    public function down(Schema $schema): void
    {
    }
}
