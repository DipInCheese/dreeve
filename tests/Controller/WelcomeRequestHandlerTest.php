<?php

namespace App\Tests\Controller;

use App\Domain\Settings\DbalSettingsRepository;
use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsRepository;
use App\Tests\Controller\Admin\AdminWebTestCase;

class WelcomeRequestHandlerTest extends AdminWebTestCase
{
    public function testItAsksAboutYouFirst(): void
    {
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/welcome');

        $this->assertResponseIsSuccessful();
        $this->assertSame('1', $crawler->filter('[aria-current="step"] span')->first()->text());
        $this->assertCount(1, $crawler->filter('form[data-dispatch-command="update-athlete-settings"][data-redirect="/welcome/connect"]'));

        $this->assertCount(0, $crawler->filter('input[name="group"]'));
        $this->assertCount(1, $crawler->filter('input[name="athlete[firstName]"][required]'));
        $this->assertCount(1, $crawler->filter('input[name="athlete[lastName]"][required]'));
        $this->assertCount(1, $crawler->filter('input[name="athlete[birthday]"]'));
        $this->assertCount(1, $crawler->filter('select[name="athlete[gender]"]'));
        $this->assertCount(1, $crawler->filter('select[name="athlete[maxHeartRateFormula]"]'));
        $this->assertCount(1, $crawler->filter('select[name="athlete[restingHeartRateFormula]"]'));
        $this->assertCount(1, $crawler->filter('input[name="athlete[restingHeartRateFormulaFixedValue]"]'));

        $this->assertCount(1, $crawler->filter('select[name="athlete[maxHeartRateFormula]"] option[value="fox"][selected]'));
        $this->assertCount(1, $crawler->filter('select[name="athlete[restingHeartRateFormula]"] option[value="heuristicAgeBased"][selected]'));

        // A focused page: no navigation to wander off to.
        $this->assertCount(0, $crawler->filter('#drawer-navigation'));
        $this->assertCount(0, $crawler->filter('nav.contextual-panel'));
        $this->assertCount(0, $crawler->filter('[data-drawer-toggle="drawer-navigation"]'));
        $this->assertStringNotContainsString('Return to app', $crawler->filter('body')->text());
    }

    public function testItRendersDateRangeBasedFormulasAsRows(): void
    {
        /** @var SettingsRepository $settingsRepository */
        $settingsRepository = $this->getContainer()->get(DbalSettingsRepository::class);
        $settings = $settingsRepository->findGroup(SettingsGroup::GENERAL);
        $settings['maxHeartRateFormula'] = ['2023-01-01' => 180];
        $settings['restingHeartRateFormula'] = ['2023-01-01' => 58];
        $settingsRepository->saveGroup(SettingsGroup::GENERAL, $settings);

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/welcome');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('select[name="athlete[maxHeartRateFormula]"] option[value="dateRangeBased"][selected]'));
        $this->assertCount(1, $crawler->filter('select[name="athlete[restingHeartRateFormula]"] option[value="dateRangeBased"][selected]'));

        $rows = $crawler->filter('[data-repeater-list]')->extract(['data-repeater-initial']);
        $this->assertSame([
            '[{"on":"2023-01-01","bpm":180}]',
            '[{"on":"2023-01-01","bpm":58}]',
        ], $rows);
    }

    public function testItThenAsksWhereYourDataComesFrom(): void
    {
        $crawler = $this->client->request('GET', '/welcome/connect');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['garmin', 'upload', 'skip'], $crawler->filter('[data-welcome-choice]')->each(fn ($node) => $node->attr('data-welcome-choice')));
        $this->assertSame('/admin/garmin', $crawler->filter('[data-welcome-choice="garmin"]')->attr('href'));
        $this->assertSame('/today', $crawler->filter('[data-welcome-choice="skip"]')->attr('href'));
    }

    public function testConnectingGarminFromTheWelcomeFlowEndsOnToday(): void
    {
        $this->client->request('GET', '/welcome/connect');
        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->client->submit($crawler->filter('form[action="/admin/garmin/connect"]')->form(['email' => 'me@example.com', 'password' => 'secret']));

        $this->assertResponseRedirects('/today');

        // Only once: later connections stay on the Garmin page.
        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->client->submit($crawler->filter('form[action="/admin/garmin/connect"]')->form(['email' => 'me@example.com', 'password' => 'secret']));
        $this->assertResponseRedirects('/admin/garmin');
    }

    public function testTheOldProfilePageLeadsHere(): void
    {
        $this->client->request('GET', '/admin/settings/athlete');

        $this->assertResponseRedirects('/welcome');
    }
}
