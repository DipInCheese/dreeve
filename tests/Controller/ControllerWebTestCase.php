<?php

namespace App\Tests\Controller;

use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\ProvideSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

abstract class ControllerWebTestCase extends WebTestCase
{
    use ProvideSettings;

    protected const string ADMIN_USERNAME = 'admin';
    protected const string ADMIN_PASSWORD = 'admin-password';

    protected KernelBrowser $client;

    private ?string $originalApiKey;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApiKey = $_ENV['DREEVE_API_KEY'] ?? null;

        $_SERVER['ADMIN_USERNAME'] = $_ENV['ADMIN_USERNAME'] = self::ADMIN_USERNAME;
        $_SERVER['ADMIN_PASSWORD_HASH'] = $_ENV['ADMIN_PASSWORD_HASH'] = password_hash(self::ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

        $this->prepareEnvironment();

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->logInWhenNeeded();

        $this->provideSettings();

        if ($this->shouldSeedActivity()) {
            $this->seedActivity();
        }
    }

    protected function prepareEnvironment(): void
    {
    }

    /**
     * Every page requires a login. Tests about anonymous visitors return false.
     */
    protected function shouldLogIn(): bool
    {
        return true;
    }

    protected function adminUser(): InMemoryUser
    {
        return new InMemoryUser(self::ADMIN_USERNAME, (string) $_SERVER['ADMIN_PASSWORD_HASH'], ['ROLE_ADMIN']);
    }

    private function logInWhenNeeded(): void
    {
        if ($this->shouldLogIn()) {
            $this->client->loginUser($this->adminUser());
        }
    }

    protected function shouldSeedActivity(): bool
    {
        return true;
    }

    protected function seedActivity(): void
    {
        /** @var ActivityIdRepository $activityIdRepository */
        $activityIdRepository = $this->getContainer()->get(ActivityIdRepository::class);
        if ($activityIdRepository->count() > 0) {
            return;
        }

        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()->build(),
            [],
        ));
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Superglobals outlive the kernel, and the suite runs order-randomized, so a test that
        // configures an API key would otherwise leak it into one asserting there is none.
        if (null === $this->originalApiKey) {
            unset($_SERVER['DREEVE_API_KEY'], $_ENV['DREEVE_API_KEY']);
        } else {
            $_SERVER['DREEVE_API_KEY'] = $_ENV['DREEVE_API_KEY'] = $this->originalApiKey;
        }

        parent::tearDown();
    }

    protected function withApiKey(string $apiKey): void
    {
        $_SERVER['DREEVE_API_KEY'] = $_ENV['DREEVE_API_KEY'] = $apiKey;

        $this->rebootClient();
    }

    private function rebootClient(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->logInWhenNeeded();

        // Booting a new kernel wiped the in memory build storage, mark the app as built again.
        if ($this->shouldSeedActivity()) {
            $this->seedActivity();
        }
    }
}
