<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016150534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Health data: daily summaries, sleep, HRV, fitness status, body measurements and the raw source records.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE BodyMeasurement (id VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, day VARCHAR(255) NOT NULL, measuredOn VARCHAR(255) NOT NULL, weightInKg DOUBLE PRECISION DEFAULT NULL, bmi DOUBLE PRECISION DEFAULT NULL, bodyFatPercentage DOUBLE PRECISION DEFAULT NULL, muscleMassInKg DOUBLE PRECISION DEFAULT NULL, boneMassInKg DOUBLE PRECISION DEFAULT NULL, bodyWaterPercentage DOUBLE PRECISION DEFAULT NULL, visceralFat DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX BodyMeasurement_dayIndex ON BodyMeasurement (day)');
        $this->addSql('CREATE TABLE DailySummary (day VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, steps INTEGER DEFAULT NULL, distanceInMeters INTEGER DEFAULT NULL, floorsAscended DOUBLE PRECISION DEFAULT NULL, activeKilocalories INTEGER DEFAULT NULL, totalKilocalories INTEGER DEFAULT NULL, moderateIntensityMinutes INTEGER DEFAULT NULL, vigorousIntensityMinutes INTEGER DEFAULT NULL, restingHeartRate INTEGER DEFAULT NULL, minHeartRate INTEGER DEFAULT NULL, maxHeartRate INTEGER DEFAULT NULL, averageStress INTEGER DEFAULT NULL, maxStress INTEGER DEFAULT NULL, bodyBatteryHighest INTEGER DEFAULT NULL, bodyBatteryLowest INTEGER DEFAULT NULL, bodyBatteryCharged INTEGER DEFAULT NULL, bodyBatteryDrained INTEGER DEFAULT NULL, bodyBatteryMostRecent INTEGER DEFAULT NULL, averageSpo2 DOUBLE PRECISION DEFAULT NULL, lowestSpo2 INTEGER DEFAULT NULL, averageRespiration DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (day))');
        $this->addSql('CREATE TABLE FitnessStatus (day VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, vo2MaxRunning DOUBLE PRECISION DEFAULT NULL, vo2MaxCycling DOUBLE PRECISION DEFAULT NULL, trainingReadiness INTEGER DEFAULT NULL, trainingReadinessLevel VARCHAR(255) DEFAULT NULL, PRIMARY KEY (day))');
        $this->addSql('CREATE TABLE HrvSummary (day VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, lastNightAverage DOUBLE PRECISION DEFAULT NULL, weeklyAverage DOUBLE PRECISION DEFAULT NULL, lastNightHighest DOUBLE PRECISION DEFAULT NULL, status VARCHAR(255) DEFAULT NULL, balancedLow DOUBLE PRECISION DEFAULT NULL, balancedHigh DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (day))');
        $this->addSql('CREATE TABLE SleepSession (day VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, startsAt VARCHAR(255) DEFAULT NULL, endsAt VARCHAR(255) DEFAULT NULL, sleepSeconds INTEGER DEFAULT NULL, deepSleepSeconds INTEGER DEFAULT NULL, lightSleepSeconds INTEGER DEFAULT NULL, remSleepSeconds INTEGER DEFAULT NULL, awakeSeconds INTEGER DEFAULT NULL, score INTEGER DEFAULT NULL, averageRespiration DOUBLE PRECISION DEFAULT NULL, averageHrv DOUBLE PRECISION DEFAULT NULL, averageSpo2 DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (day))');
        $this->addSql('CREATE TABLE SourceRecord (id VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, kind VARCHAR(255) NOT NULL, recordKey VARCHAR(255) NOT NULL, receivedOn VARCHAR(255) NOT NULL, payload BLOB NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE BodyMeasurement');
        $this->addSql('DROP TABLE DailySummary');
        $this->addSql('DROP TABLE FitnessStatus');
        $this->addSql('DROP TABLE HrvSummary');
        $this->addSql('DROP TABLE SleepSession');
        $this->addSql('DROP TABLE SourceRecord');
    }
}
