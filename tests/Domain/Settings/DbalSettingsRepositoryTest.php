<?php

declare(strict_types=1);

namespace App\Tests\Domain\Settings;

use App\Domain\Activity\Eddington\Config\EddingtonConfiguration;
use App\Domain\Settings\DaemonSettings;
use App\Domain\Settings\DbalSettingsRepository;
use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsName;
use App\Domain\Settings\SettingsRepository;
use App\Tests\ContainerTestCase;

class DbalSettingsRepositoryTest extends ContainerTestCase
{
    private SettingsRepository $settingsRepository;

    public function testFindGroupReturnsDefaultsWhenNothingIsStored(): void
    {
        $this->assertSame([], $this->settingsRepository->findGroup(SettingsGroup::DAEMON));
        $this->assertEquals(
            DaemonSettings::fromArray([]),
            $this->settingsRepository->daemon()
        );
    }

    public function testSaveGroupAndFindGroupKeepValueTypes(): void
    {
        $data = [
            'zwiftLevel' => 42,
            'zwiftRacingScore' => 500,
        ];
        $this->settingsRepository->saveGroup(SettingsGroup::ZWIFT, $data);

        $settings = $this->settingsRepository->findGroup(SettingsGroup::ZWIFT);
        ksort($data);
        ksort($settings);

        $this->assertSame($data, $settings);
    }

    public function testSaveGroupReplacesTheWholeGroup(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::ZWIFT, ['level' => 80, 'racingScore' => 495]);
        $this->settingsRepository->saveGroup(SettingsGroup::ZWIFT, ['level' => 81]);

        $this->assertSame(['level' => 81], $this->settingsRepository->findGroup(SettingsGroup::ZWIFT));
    }

    public function testSaveGroupDoesNotTouchOtherGroups(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::MAPS, ['polylineColor' => 'red']);
        $this->settingsRepository->saveGroup(SettingsGroup::SECURITY, []);

        $this->assertSame(['polylineColor' => 'red'], $this->settingsRepository->findGroup(SettingsGroup::MAPS));
        $this->assertSame([], $this->settingsRepository->findGroup(SettingsGroup::SECURITY));
    }

    public function testSaveUpdatesASingleSettingAndAddsNewOnes(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::APPEARANCE, [
            'locale' => 'en_US',
            'timeFormat' => 24,
        ]);

        $this->settingsRepository->save(SettingsName::LOCALE, 'nl_BE');
        $this->settingsRepository->save(SettingsName::WEIGHT_HISTORY, []);

        $this->assertEquals([
            'locale' => 'nl_BE',
            'timeFormat' => 24,
        ], $this->settingsRepository->findGroup(SettingsGroup::APPEARANCE));
    }

    public function testFindReturnsASingleSetting(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::APPEARANCE, ['locale' => 'nl_BE']);

        $this->assertSame('nl_BE', $this->settingsRepository->find(SettingsName::LOCALE));
    }

    public function testFindKeepsAnEmptyValueWithoutADefault(): void
    {
        $this->settingsRepository->save(SettingsName::LOCALE, false);

        $this->assertFalse($this->settingsRepository->find(SettingsName::LOCALE));
    }

    public function testFindAppliesDefaultsToASingleSetting(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::GENERAL, []);

        $this->assertSame('fox', $this->settingsRepository->find(SettingsName::MAX_HEART_RATE_FORMULA));
        $this->assertSame('heuristicAgeBased', $this->settingsRepository->find(SettingsName::RESTING_HEART_RATE_FORMULA));
    }

    public function testFindGroupAppliesTheDefaultHeartRateFormulas(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::GENERAL, [
            'birthday' => '1990-01-01',
        ]);

        $this->assertEquals(
            [
                'birthday' => '1990-01-01',
                'maxHeartRateFormula' => 'fox',
                'restingHeartRateFormula' => 'heuristicAgeBased',
            ],
            $this->settingsRepository->findGroup(SettingsGroup::GENERAL)
        );
    }

    public function testFindGroupDoesNotOverrideConfiguredHeartRateFormulas(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::GENERAL, [
            'maxHeartRateFormula' => ['2023-01-01' => 180],
            'restingHeartRateFormula' => 58,
        ]);

        $this->assertEquals(
            [
                'maxHeartRateFormula' => ['2023-01-01' => 180],
                'restingHeartRateFormula' => 58,
            ],
            $this->settingsRepository->findGroup(SettingsGroup::GENERAL)
        );
    }

    public function testFindGroupAppliesTheDefaultEddingtonConfiguration(): void
    {
        $this->settingsRepository->saveGroup(SettingsGroup::METRICS, ['excludeActivitiesFromPeakPowerOutputs' => []]);

        $this->assertEquals(
            [
                'excludeActivitiesFromPeakPowerOutputs' => [],
                'eddington' => EddingtonConfiguration::getDefaultConfig(),
            ],
            $this->settingsRepository->findGroup(SettingsGroup::METRICS)
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsRepository = $this->getContainer()->get(DbalSettingsRepository::class);
    }
}
