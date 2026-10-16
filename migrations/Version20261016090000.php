<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the Strava import: its challenges, webhook events and import settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS Challenge');
        $this->addSql('DROP TABLE IF EXISTS WebhookEvent');
        $this->addSql("DELETE FROM Setting WHERE settingsGroup = 'import'");
        $this->addSql("DELETE FROM Setting WHERE settingsGroup = 'security'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The Strava import was removed.');
    }
}
