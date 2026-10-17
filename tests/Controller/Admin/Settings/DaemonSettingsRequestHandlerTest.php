<?php

namespace App\Tests\Controller\Admin\Settings;

use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsRepository;
use App\Tests\Controller\Admin\AdminWebTestCase;

class DaemonSettingsRequestHandlerTest extends AdminWebTestCase
{
    public function testItRendersTheRemindersPage(): void
    {
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/settings/daemon');

        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[data-dispatch-command="update-settings"]');
        $this->assertCount(1, $form);
        $this->assertCount(1, $form->filter('input[name="group"][value="daemon"]'));
        $this->assertStringContainsString('Reminders', $form->filter('h3')->text());
        $this->assertStringContainsString('Changes take effect the next time Dreeve restarts.', $form->text());
        $this->assertStringNotContainsStringIgnoringCase('daemon', $form->text());
        $this->assertStringNotContainsStringIgnoringCase('cron', $form->text());

        // A schedule to pick instead of a cron expression to type.
        $this->assertCount(1, $crawler->filter('select[name="data[cron][gearMaintenanceNotification][expression]"]'));
        $this->assertCount(1, $crawler->filter('select[name="data[cron][appUpdateAvailableNotification][expression]"]'));
        $this->assertCount(0, $crawler->filter('input[name$="[expression]"]'));
        $this->assertGreaterThan(1, $crawler->filter('select[name="data[cron][gearMaintenanceNotification][expression]"] option')->count());

        // The settings navigation, with "Reminders" active.
        $settingsPanel = $crawler->filter('nav.contextual-panel[aria-label="Settings"]');
        $this->assertCount(1, $settingsPanel);
        $selectedLink = $settingsPanel->filter('a[aria-selected="true"]');
        $this->assertCount(1, $selectedLink);
        $this->assertStringContainsString('Reminders', $selectedLink->text());
    }

    public function testItKeepsACustomScheduleAsAnOption(): void
    {
        $this->getContainer()->get(SettingsRepository::class)->saveGroup(SettingsGroup::DAEMON, [
            'cron' => ['gearMaintenanceNotification' => ['enabled' => true, 'expression' => '15 9 * * 3']],
        ]);
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/settings/daemon');

        $selected = $crawler->filter('select[name="data[cron][gearMaintenanceNotification][expression]"] option[selected]');
        $this->assertCount(1, $selected);
        $this->assertSame('15 9 * * 3', $selected->attr('value'));
        $this->assertStringContainsString('Custom schedule (15 9 * * 3)', $selected->text());
    }
}
