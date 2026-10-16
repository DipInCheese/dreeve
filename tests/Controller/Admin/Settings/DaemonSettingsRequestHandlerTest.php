<?php

namespace App\Tests\Controller\Admin\Settings;

use App\Tests\Controller\Admin\AdminWebTestCase;

class DaemonSettingsRequestHandlerTest extends AdminWebTestCase
{
    public function testItRendersTheDaemonSettingsPage(): void
    {
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/settings/daemon');

        $this->assertResponseIsSuccessful();

        // The daemon settings form.
        $this->assertCount(1, $crawler->filter('form[data-dispatch-command="update-settings"]'));
        $this->assertCount(1, $crawler->filter('form[data-dispatch-command="update-settings"] input[name="group"][value="daemon"]'));
        $this->assertCount(1, $crawler->filter('input[name="data[cron][gearMaintenanceNotification][expression]"]'));
        $this->assertCount(1, $crawler->filter('input[name="data[cron][appUpdateAvailableNotification][expression]"]'));
        $this->assertStringContainsString(
            'Changes to these settings only take effect after you restart the daemon container.',
            $crawler->filter('form[data-dispatch-command="update-settings"]')->text(),
        );

        // The settings navigation, with "Daemon" active.
        $settingsPanel = $crawler->filter('nav.contextual-panel[aria-label="Settings"]');
        $this->assertCount(1, $settingsPanel);
        $selectedLink = $settingsPanel->filter('a[aria-selected="true"]');
        $this->assertCount(1, $selectedLink);
        $this->assertStringContainsString('Daemon', $selectedLink->text());
    }
}
