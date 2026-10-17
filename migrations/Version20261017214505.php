<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017214505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights: written analyses of a period, kept so they are not made twice.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE Insight (id VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, periodStart VARCHAR(255) NOT NULL, periodEnd VARCHAR(255) NOT NULL, content CLOB NOT NULL, model VARCHAR(255) NOT NULL, createdOn VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX Insight_typePeriod ON Insight (type, periodEnd)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE Insight');
    }
}
