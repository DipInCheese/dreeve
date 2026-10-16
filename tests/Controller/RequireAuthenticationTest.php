<?php

namespace App\Tests\Controller;

use App\Tests\Controller\Admin\AdminWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RequireAuthenticationTest extends AdminWebTestCase
{
    #[\Override]
    protected function shouldLogIn(): bool
    {
        return false;
    }

    #[DataProvider('provideProtectedPaths')]
    public function testItSendsAnonymousVisitorsToTheLoginPage(string $path): void
    {
        $this->client->request('GET', $path);

        $this->assertResponseRedirects('http://localhost/admin/login');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideProtectedPaths(): iterable
    {
        yield 'dashboard' => ['/'];
        yield 'activities' => ['/activities'];
        yield 'ai chat' => ['/chat'];
        yield 'internal api' => ['/api/internal/dashboard/widget/dashboardWidget-introText'];
        yield 'badge' => ['/badge/dreeve.svg'];
        yield 'images' => ['/files/gear/bike.png'];
    }

    #[DataProvider('provideAdminPaths')]
    public function testItAlwaysSendsAnonymousVisitorsAwayFromTheAdmin(string $method, string $path): void
    {
        $this->client->request($method, $path);

        $this->assertResponseRedirects('/admin/login');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAdminPaths(): iterable
    {
        yield 'admin root' => ['GET', '/admin'];
        yield 'unknown admin page' => ['GET', '/admin/dmzdmzd'];
        yield 'dispatch command' => ['POST', '/admin/dispatch-command'];
        yield 'import status' => ['GET', '/admin/import-status'];
        yield 'upload' => ['GET', '/admin/upload'];
        yield 'activities' => ['GET', '/admin/activities'];
        yield 'search activities' => ['GET', '/admin/activities/search'];
        yield 'add activity' => ['GET', '/admin/activities/add'];
        yield 'edit activity' => ['GET', '/admin/activities/activity-1/edit'];
        yield 'delete activity' => ['GET', '/admin/activities/activity-1/delete'];
        yield 'automation rules' => ['GET', '/admin/automation-rules'];
        yield 'add automation rule' => ['GET', '/admin/automation-rules/add'];
        yield 'edit automation rule' => ['GET', '/admin/automation-rules/automationRule-1/edit'];
        yield 'delete automation rule' => ['GET', '/admin/automation-rules/automationRule-1/delete'];
        yield 'test automation rules' => ['GET', '/admin/automation-rules/test'];
        yield 'backfill automation rules' => ['GET', '/admin/automation-rules/backfill'];
        yield 'file imports' => ['GET', '/admin/file-imports'];
        yield 'download file import' => ['GET', '/admin/file-imports/fileImport-1/download'];
        yield 'delete file import' => ['GET', '/admin/file-imports/fileImport-1/delete'];
        yield 'gear' => ['GET', '/admin/gear'];
        yield 'add gear' => ['GET', '/admin/gear/add'];
        yield 'edit gear' => ['GET', '/admin/gear/gear-1/edit'];
        yield 'delete gear' => ['GET', '/admin/gear/gear-1/delete'];
        yield 'gear maintenance config' => ['GET', '/admin/gear/maintenance-config'];
        yield 'add gear component' => ['GET', '/admin/gear/maintenance-config/component/add'];
        yield 'edit gear component' => ['GET', '/admin/gear/maintenance-config/component/gearComponent-chain/edit'];
        yield 'delete gear component' => ['GET', '/admin/gear/maintenance-config/component/gearComponent-chain/delete'];
        yield 'gear maintenance logs' => ['GET', '/admin/gear/maintenance-logs'];
        yield 'register gear maintenance log' => ['GET', '/admin/gear/maintenance-logs/register'];
        yield 'edit gear maintenance log' => ['GET', '/admin/gear/maintenance-logs/gearMaintenance-1/edit'];
        yield 'delete gear maintenance log' => ['GET', '/admin/gear/maintenance-logs/gearMaintenance-1/delete'];
        yield 'recording devices' => ['GET', '/admin/gear/recording-devices'];
        yield 'edit recording device' => ['GET', '/admin/gear/recording-devices/recordingDevice-garmin-edge-530/edit'];
        yield 'settings' => ['GET', '/admin/settings'];
        yield 'api key generation' => ['GET', '/admin/settings/api-key/generate'];
        yield 'dashboard settings' => ['GET', '/admin/settings/dashboard'];
        yield 'delete dashboard widget' => ['GET', '/admin/settings/dashboard/widget/dashboardWidget-eddington/delete'];
        yield 'athlete settings' => ['GET', '/admin/settings/athlete'];
        yield 'appearance settings' => ['GET', '/admin/settings/appearance'];
        yield 'daemon settings' => ['GET', '/admin/settings/daemon'];
        yield 'general settings' => ['GET', '/admin/settings/general'];
        yield 'import settings' => ['GET', '/admin/settings/import'];
        yield 'integrations settings' => ['GET', '/admin/settings/integrations'];
        yield 'maps settings' => ['GET', '/admin/settings/maps'];
        yield 'metrics settings' => ['GET', '/admin/settings/metrics'];
        yield 'security settings' => ['GET', '/admin/settings/security'];
        yield 'zwift settings' => ['GET', '/admin/settings/zwift'];
    }

    #[DataProvider('providePublicPaths')]
    public function testItKeepsThePathsNeededToLogInPublic(string $path): void
    {
        $this->client->request('GET', $path);

        $this->assertNotEquals(
            'http://localhost/admin/login',
            $this->client->getResponse()->headers->get('Location')
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePublicPaths(): iterable
    {
        yield 'manifest' => ['/manifest.json'];
        yield 'login' => ['/admin/login'];
        yield 'invite' => ['/invite/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
    }

    public function testItServesEverythingToAnAuthenticatedVisitor(): void
    {
        $this->client->loginUser($this->adminUser());

        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }
}
