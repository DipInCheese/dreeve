<?php

namespace App\Tests\Domain\Settings;

use App\Domain\Activity\Eddington\Config\EddingtonConfiguration;
use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsName;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SettingsInvalidateCacheTagsListenerTest extends ContainerTestCase
{
    private SettingsRepository $settingsRepository;
    private RenderCache $renderCache;

    #[DataProvider('provideSettingsGroups')]
    public function testItInvalidatesTheUpdatedGroup(SettingsGroup $updatedGroup): void
    {
        foreach (SettingsGroup::cases() as $group) {
            $cacheTag = RootCacheTag::forSettingsGroup($group);
            $this->renderCache->get(
                cacheKey: $cacheTag->value,
                cacheability: Cacheability::for('stub', CacheTags::of($cacheTag)),
                callback: fn (): string => 'rendered',
            );
        }

        $this->settingsRepository->saveGroup($updatedGroup, ['some' => 'setting']);

        $updatedGroupIsCrossCutting = in_array(
            RootCacheTag::forSettingsGroup($updatedGroup),
            RootCacheTag::crossCutting(),
            true
        );

        foreach (SettingsGroup::cases() as $group) {
            $cacheTag = RootCacheTag::forSettingsGroup($group);
            $this->assertEquals(
                !$updatedGroupIsCrossCutting && $group !== $updatedGroup,
                $this->renderCache->get(
                    cacheKey: $cacheTag->value,
                    cacheability: Cacheability::for('stub', CacheTags::of($cacheTag)),
                    callback: fn (): string => 'rendered',
                )->wasServedFromCache()
            );
        }
    }

    public function testSavingASingleSettingInvalidatesItsGroup(): void
    {
        foreach ([SettingsGroup::METRICS, SettingsGroup::IMPORT] as $group) {
            $cacheTag = RootCacheTag::forSettingsGroup($group);
            $this->renderCache->get(
                cacheKey: $cacheTag->value,
                cacheability: Cacheability::for('stub', CacheTags::of($cacheTag)),
                callback: fn (): string => 'rendered',
            );
        }

        $this->settingsRepository->save(SettingsName::EDDINGTON, EddingtonConfiguration::getDefaultConfig());

        foreach ([SettingsGroup::METRICS->value => false, SettingsGroup::IMPORT->value => true] as $group => $servedFromCache) {
            $cacheTag = RootCacheTag::forSettingsGroup(SettingsGroup::from($group));
            $this->assertSame(
                $servedFromCache,
                $this->renderCache->get(
                    cacheKey: $cacheTag->value,
                    cacheability: Cacheability::for('stub', CacheTags::of($cacheTag)),
                    callback: fn (): string => 'rendered',
                )->wasServedFromCache()
            );
        }
    }

    public static function provideSettingsGroups(): \Generator
    {
        foreach (SettingsGroup::cases() as $group) {
            yield $group->value => [$group];
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsRepository = $this->getContainer()->get(SettingsRepository::class);
        $this->renderCache = $this->getContainer()->get(RenderCache::class);
        $this->renderCache->clear();
    }
}
