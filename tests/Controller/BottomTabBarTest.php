<?php

namespace App\Tests\Controller;

use App\Tests\Controller\Admin\AdminWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BottomTabBarTest extends AdminWebTestCase
{
    #[DataProvider('providePages')]
    public function testEveryPageHasTheTabBarWithTheRightTabSelected(string $path, string $selected): void
    {
        $crawler = $this->client->request('GET', $path);

        $tabBar = $crawler->filter('[data-bottom-tab-bar]');
        $this->assertCount(1, $tabBar, $path);
        $this->assertSame(['Today', 'Health', 'Training', 'Insights', 'More'], $tabBar->filter('a, button')->each(fn ($tab): string => trim($tab->text())));

        $current = $tabBar->filter('[aria-current="page"]');
        $this->assertCount(1, $current, $path);
        $this->assertSame($selected, trim($current->text()), $path);
    }

    public static function providePages(): iterable
    {
        yield 'today' => ['/today', 'Today'];
        yield 'health' => ['/health/sleep', 'Health'];
        yield 'activities' => ['/activities', 'Training'];
        yield 'insights' => ['/insights', 'Insights'];
        yield 'settings' => ['/admin/settings/general', 'More'];
    }

    public function testMoreOpensTheSideMenu(): void
    {
        $crawler = $this->client->request('GET', '/today');

        $this->assertCount(1, $crawler->filter('[data-bottom-tab-bar] button[data-drawer-toggle="drawer-navigation"]'));
    }

    public function testSettingsPagesRepeatTheirMenuAsTabsForPhones(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings/daemon');

        $tabs = $crawler->filter('nav.contextual-tabs');
        $this->assertCount(1, $tabs);
        $this->assertSame('Reminders', trim($tabs->filter('a[aria-selected="true"]')->text()));
        $this->assertCount(1, $crawler->filter('nav.contextual-panel'));
    }

    public function testTheWelcomeStepsHaveNoTabBar(): void
    {
        $crawler = $this->client->request('GET', '/welcome');

        $this->assertCount(0, $crawler->filter('[data-bottom-tab-bar]'));
    }
}
