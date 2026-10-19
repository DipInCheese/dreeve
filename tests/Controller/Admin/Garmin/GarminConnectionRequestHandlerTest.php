<?php

namespace App\Tests\Controller\Admin\Garmin;

use App\Infrastructure\HealthSync\GarminLogin;
use App\Infrastructure\HealthSync\HealthSync;
use App\Infrastructure\HealthSync\HealthSyncFailed;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Infrastructure\HealthSync\SpyHealthSync;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class GarminConnectionRequestHandlerTest extends AdminWebTestCase
{
    private SpyHealthSync $healthSync;

    public function testItAsksForTheGarminLogin(): void
    {
        $crawler = $this->client->request('GET', '/admin/garmin');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $form = $crawler->filter('form[action="/admin/garmin/connect"]');
        $this->assertCount(1, $form);
        $this->assertCount(1, $form->filter('input[name="email"]'));
        $this->assertCount(1, $form->filter('input[type="password"][name="password"]'));
        $this->assertSame(['90d', '365d', 'all'], $form->filter('select[name="history"] option')->each(fn (Crawler $option): string => (string) $option->attr('value')));
        // The Manage group points here.
        $this->assertSame('true', $crawler->filter('aside a[href="/admin/garmin"]')->attr('aria-selected'));
    }

    public function testConnectingWithoutASecurityCode(): void
    {
        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->client->submit($crawler->filter('form[action="/admin/garmin/connect"]')->form([
            'email' => 'me@example.com',
            'password' => 'secret-password',
            'history' => '365d',
        ]));

        $this->assertResponseRedirects('/admin/garmin');
        $this->assertSame([['connect', ['email' => 'me@example.com', 'password' => 'secret-password', 'history' => '365d']]], $this->healthSync->calls);
        $this->assertFlash('Garmin is connected.');
    }

    public function testConnectingWithASecurityCode(): void
    {
        $this->healthSync->login = GarminLogin::CODE_REQUIRED;
        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->client->submit($crawler->filter('form[action="/admin/garmin/connect"]')->form([
            'email' => 'me@example.com',
            'password' => 'secret-password',
        ]));
        $this->assertResponseRedirects('/admin/garmin');

        $this->healthSync->status = ['mfaPending' => true];
        $crawler = $this->client->followRedirect();
        $this->assertCount(0, $crawler->filter('form[action="/admin/garmin/connect"]'));
        $this->client->submit($crawler->filter('form[action="/admin/garmin/code"]')->form(['code' => '123456']));

        $this->assertResponseRedirects('/admin/garmin');
        $this->assertSame(['confirmCode', ['code' => '123456']], $this->healthSync->calls[1]);
        $this->assertFlash('Garmin is connected.');
    }

    #[DataProvider('provideFailures')]
    public function testItExplainsWhyConnectingFailed(string $reason, string $expectedMessage): void
    {
        $this->healthSync->failure = new HealthSyncFailed($reason, 'Something');
        $this->client->request('POST', '/admin/garmin/connect', [
            '_csrf_token' => $this->csrfToken(),
            'email' => 'me@example.com',
            'password' => 'wrong',
        ]);

        $this->assertResponseRedirects('/admin/garmin');
        $this->healthSync->failure = null;
        $this->assertFlash($expectedMessage);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideFailures(): iterable
    {
        yield 'wrong password' => ['login_failed', 'Garmin did not accept this email and password.'];
        yield 'wrong code' => ['mfa_failed', 'Garmin did not accept this code.'];
        yield 'too slow' => ['mfa_expired', 'The code took too long.'];
        yield 'too many attempts' => ['too_many_attempts', 'Too many attempts.'];
        yield 'garmin down' => ['garmin_unavailable', 'Garmin can not be reached right now.'];
        yield 'service down' => ['unreachable', 'Check that the health-sync container is running.'];
    }

    public function testItRequiresTheEmailAndPassword(): void
    {
        $this->client->request('POST', '/admin/garmin/connect', ['_csrf_token' => $this->csrfToken(), 'email' => 'me@example.com']);

        $this->assertResponseRedirects('/admin/garmin');
        $this->assertSame([], $this->healthSync->calls);
        $this->assertFlash('Fill in the email and password of your Garmin account.');
    }

    public function testItIgnoresAFormWithoutAValidToken(): void
    {
        foreach (['connect', 'code', 'sync', 'disconnect'] as $action) {
            $this->client->request('POST', '/admin/garmin/'.$action, ['_csrf_token' => 'nope', 'email' => 'a@b.c', 'password' => 'x', 'code' => '123456']);
            $this->assertResponseRedirects('/admin/garmin');
        }

        $this->assertSame([], $this->healthSync->calls);
    }

    public function testItShowsTheConnection(): void
    {
        $this->healthSync->status = [
            'connected' => true,
            'displayName' => 'Test <Runner>',
            'lastSyncAt' => '2026-10-16T10:00:00+00:00',
            'since' => '2026-07-18',
            'activitiesSynced' => 12,
            'lastError' => 'Garmin is rate limiting, it will be retried later.',
        ];

        $crawler = $this->client->request('GET', '/admin/garmin');

        $text = $crawler->filter('[data-garmin-connection]')->text();
        $this->assertStringContainsString('Connected as Test <Runner>.', $text);
        $this->assertStringNotContainsString('<Runner>', (string) $crawler->filter('[data-garmin-connection]')->html(), 'Names coming from Garmin are escaped.');
        $this->assertStringContainsString('16-10-2026', $text);
        $this->assertStringContainsString('12', $text);
        $this->assertStringContainsString('Garmin is rate limiting', $text);
        $this->assertCount(1, $crawler->filter('form[action="/admin/garmin/sync"]'));
        $this->assertCount(1, $crawler->filter('form[action="/admin/garmin/disconnect"]'));
        $this->assertCount(0, $crawler->filter('form[action="/admin/garmin/connect"]'));
    }

    public function testItAsksToLogInAgainWhenGarminEndedTheSession(): void
    {
        $this->healthSync->status = ['needsLogin' => true];

        $crawler = $this->client->request('GET', '/admin/garmin');

        $this->assertStringContainsString('Garmin ended the session.', $crawler->filter('[data-garmin-connection]')->text());
        $this->assertCount(1, $crawler->filter('form[action="/admin/garmin/connect"]'));
    }

    public function testSyncingNowAndDisconnecting(): void
    {
        $this->healthSync->status = ['connected' => true];
        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->client->submit($crawler->filter('form[action="/admin/garmin/sync"]')->form());
        $this->assertResponseRedirects('/admin/garmin');
        $this->assertFlash('Syncing with Garmin.');

        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->client->submit($crawler->filter('form[action="/admin/garmin/disconnect"]')->form());
        $this->assertResponseRedirects('/admin/garmin');
        $this->assertFlash('Garmin is disconnected.');

        $this->assertSame(['syncNow', 'disconnect'], array_column($this->healthSync->calls, 0));
    }

    public function testFetchingAllWeighInsAgain(): void
    {
        $this->healthSync->status = ['connected' => true];
        $crawler = $this->client->request('GET', '/admin/garmin');

        $this->client->submit($crawler->filter('form[data-refetch-weigh-ins]')->form());

        $this->assertResponseRedirects('/admin/garmin');
        $this->assertFlash('Fetching all your weigh-ins from Garmin again.');
        $this->assertSame(['refetchWeighIns'], array_column($this->healthSync->calls, 0));
    }

    public function testItSaysSoWhenTheServiceCanNotBeReached(): void
    {
        $this->healthSync->failure = HealthSyncFailed::unreachable();

        $crawler = $this->client->request('GET', '/admin/garmin');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('The Garmin sync service can not be reached.', $crawler->filter('[data-garmin-connection]')->text());
        $this->assertCount(0, $crawler->filter('form[action="/admin/garmin/connect"]'));
    }

    public function testItIsNotAvailableWithoutHealthSync(): void
    {
        $this->healthSync->available = false;

        $this->client->request('GET', '/admin/garmin');

        $this->assertResponseStatusCodeSame(404);
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/garmin');

        return (string) $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');
    }

    private function assertFlash(string $expected): void
    {
        $crawler = $this->client->request('GET', '/admin/garmin');
        $this->assertStringContainsString($expected, (string) $crawler->filter('#flash-messages')->attr('data-messages'));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $healthSync = $this->getContainer()->get(HealthSync::class);
        assert($healthSync instanceof SpyHealthSync);
        $this->healthSync = $healthSync;
    }
}
