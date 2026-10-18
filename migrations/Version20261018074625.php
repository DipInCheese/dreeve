<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018074625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When the watch last uploaded to Garmin, to tell a stuck upload from a sync problem.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE DailySummary ADD COLUMN watchUploadedAt VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__DailySummary AS SELECT day, source, steps, distanceInMeters, floorsAscended, activeKilocalories, totalKilocalories, moderateIntensityMinutes, vigorousIntensityMinutes, restingHeartRate, minHeartRate, maxHeartRate, averageStress, maxStress, bodyBatteryHighest, bodyBatteryLowest, bodyBatteryCharged, bodyBatteryDrained, bodyBatteryMostRecent, averageSpo2, lowestSpo2, averageRespiration FROM DailySummary');
        $this->addSql('DROP TABLE DailySummary');
        $this->addSql('CREATE TABLE DailySummary (day VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, steps INTEGER DEFAULT NULL, distanceInMeters INTEGER DEFAULT NULL, floorsAscended DOUBLE PRECISION DEFAULT NULL, activeKilocalories INTEGER DEFAULT NULL, totalKilocalories INTEGER DEFAULT NULL, moderateIntensityMinutes INTEGER DEFAULT NULL, vigorousIntensityMinutes INTEGER DEFAULT NULL, restingHeartRate INTEGER DEFAULT NULL, minHeartRate INTEGER DEFAULT NULL, maxHeartRate INTEGER DEFAULT NULL, averageStress INTEGER DEFAULT NULL, maxStress INTEGER DEFAULT NULL, bodyBatteryHighest INTEGER DEFAULT NULL, bodyBatteryLowest INTEGER DEFAULT NULL, bodyBatteryCharged INTEGER DEFAULT NULL, bodyBatteryDrained INTEGER DEFAULT NULL, bodyBatteryMostRecent INTEGER DEFAULT NULL, averageSpo2 DOUBLE PRECISION DEFAULT NULL, lowestSpo2 INTEGER DEFAULT NULL, averageRespiration DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (day))');
        $this->addSql('INSERT INTO DailySummary (day, source, steps, distanceInMeters, floorsAscended, activeKilocalories, totalKilocalories, moderateIntensityMinutes, vigorousIntensityMinutes, restingHeartRate, minHeartRate, maxHeartRate, averageStress, maxStress, bodyBatteryHighest, bodyBatteryLowest, bodyBatteryCharged, bodyBatteryDrained, bodyBatteryMostRecent, averageSpo2, lowestSpo2, averageRespiration) SELECT day, source, steps, distanceInMeters, floorsAscended, activeKilocalories, totalKilocalories, moderateIntensityMinutes, vigorousIntensityMinutes, restingHeartRate, minHeartRate, maxHeartRate, averageStress, maxStress, bodyBatteryHighest, bodyBatteryLowest, bodyBatteryCharged, bodyBatteryDrained, bodyBatteryMostRecent, averageSpo2, lowestSpo2, averageRespiration FROM __temp__DailySummary');
        $this->addSql('DROP TABLE __temp__DailySummary');
    }
}
