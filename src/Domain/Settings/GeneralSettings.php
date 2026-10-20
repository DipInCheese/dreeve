<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Application\AppSubTitle;
use App\Application\ProfilePictureUrl;
use App\Domain\Athlete\Athlete;
use App\Domain\Athlete\AthleteBirthDate;
use App\Domain\Athlete\HeartRateZone\HeartRateZoneConfiguration;
use App\Domain\Athlete\MaxHeartRate\MaxHeartRateFormulas;
use App\Domain\Athlete\RestingHeartRate\RestingHeartRateFormulas;
use App\Domain\Ftp\FtpHistory;

final readonly class GeneralSettings
{
    public const array WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param array<string, mixed> $weightHistory
     * @param list<string>         $trainingDays
     */
    private function __construct(
        private ?AppSubTitle $appSubTitle,
        private ?ProfilePictureUrl $profilePictureUrl,
        private Athlete $athlete,
        private HeartRateZoneConfiguration $heartRateZoneConfiguration,
        private FtpHistory $ftpHistory,
        private array $weightHistory,
        private array $trainingDays,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        $birthday = $data['birthday'] ?? null;
        if (!is_string($birthday) || '' === trim($birthday)) {
            throw AthleteHasNotBeenConfigured::because('A "birthday" is required for the athlete in the general settings');
        }

        $firstName = $data['firstName'] ?? null;
        if (!is_string($firstName) || '' === trim($firstName)) {
            throw AthleteHasNotBeenConfigured::because('A "firstName" is required for the athlete in the general settings');
        }

        $lastName = $data['lastName'] ?? null;
        if (!is_string($lastName) || '' === trim($lastName)) {
            throw AthleteHasNotBeenConfigured::because('A "lastName" is required for the athlete in the general settings');
        }

        $maxHeartRateFormula = $data['maxHeartRateFormula'] ?? null;
        if (!is_string($maxHeartRateFormula) && !is_array($maxHeartRateFormula)) {
            throw AthleteHasNotBeenConfigured::because('A "maxHeartRateFormula" is required for the athlete in the general settings');
        }

        $restingHeartRateFormula = $data['restingHeartRateFormula'] ?? 'heuristicAgeBased';
        if (!is_array($restingHeartRateFormula) && !is_int($restingHeartRateFormula)
            && (!is_string($restingHeartRateFormula) || '' === trim($restingHeartRateFormula))) {
            $restingHeartRateFormula = 'heuristicAgeBased';
        }

        $athleteBirthDate = AthleteBirthDate::fromString($birthday);
        $gender = $data['gender'] ?? null;

        return new self(
            appSubTitle: AppSubTitle::fromOptionalString($data['appSubTitle'] ?? null),
            profilePictureUrl: ProfilePictureUrl::fromOptionalString(is_string($data['profilePictureUrl'] ?? null) ? $data['profilePictureUrl'] : null),
            athlete: Athlete::create(
                athleteId: substr(hash('sha256', sprintf('%s|%s|%s', $firstName, $lastName, $athleteBirthDate->format('Y-m-d'))), 0, 12),
                birthDate: $athleteBirthDate,
                firstName: $firstName,
                lastName: $lastName,
                gender: $gender,
                maxHeartRateFormula: new MaxHeartRateFormulas()->determineFormula($maxHeartRateFormula),
                restingHeartRateFormula: new RestingHeartRateFormulas()->determineFormula($restingHeartRateFormula),
            ),
            heartRateZoneConfiguration: HeartRateZoneConfiguration::fromArray($data['heartRateZones'] ?? []),
            ftpHistory: FtpHistory::fromArray($data['ftpHistory'] ?? []),
            weightHistory: $data['weightHistory'] ?? [],
            trainingDays: array_values(array_intersect(self::WEEKDAYS, is_array($data['trainingDays'] ?? null) ? $data['trainingDays'] : [])),
        );
    }

    public function getAppSubTitle(): ?AppSubTitle
    {
        return $this->appSubTitle;
    }

    public function getProfilePictureUrl(): ?ProfilePictureUrl
    {
        return $this->profilePictureUrl;
    }

    public function getAthlete(): Athlete
    {
        return $this->athlete;
    }

    public function getHeartRateZoneConfiguration(): HeartRateZoneConfiguration
    {
        return $this->heartRateZoneConfiguration;
    }

    public function getFtpHistory(): FtpHistory
    {
        return $this->ftpHistory;
    }

    /**
     * The weights typed in by hand. Use AthleteWeightHistoryProvider for the full history.
     *
     * @return array<string, mixed>
     */
    public function getWeightHistory(): array
    {
        return $this->weightHistory;
    }

    /**
     * The weekdays the user plans to train ("monday", ...); the other days are rest days.
     * Empty when the user did not say: then there is no plan to follow.
     *
     * @return list<string>
     */
    public function getTrainingDays(): array
    {
        return $this->trainingDays;
    }

    /**
     * Whether the day is a planned rest day, or null when the user has no plan.
     */
    public function isPlannedRestDay(\DateTimeInterface $day): ?bool
    {
        if ([] === $this->trainingDays) {
            return null;
        }

        return !in_array(strtolower($day->format('l')), $this->trainingDays, true);
    }
}
