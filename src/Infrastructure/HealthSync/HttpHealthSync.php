<?php

declare(strict_types=1);

namespace App\Infrastructure\HealthSync;

use App\Infrastructure\Exception\CorruptedData;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Tenancy\Tenants;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Talks to health-sync over the internal Docker network. It only answers requests that carry
 * the secret the app generated in the users folder (see docker-entrypoint.sh).
 */
final readonly class HttpHealthSync implements HealthSync
{
    public const string SECRET_FILE_NAME = '.health-sync-secret';
    // Logging in on Garmin takes a while, sometimes over half a minute.
    private const int TIMEOUT_IN_SECONDS = 90;

    private Client $client;

    public function __construct(
        #[Autowire('%env(string:HEALTH_SYNC_URL)%')]
        string $baseUrl,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%app.user%')]
        private string $currentUser,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            RequestOptions::TIMEOUT => self::TIMEOUT_IN_SECONDS,
            RequestOptions::CONNECT_TIMEOUT => 3,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
        ]);
    }

    public function isAvailable(): bool
    {
        return '' !== $this->currentUser && null !== $this->readSecret();
    }

    public function status(): GarminStatus
    {
        return GarminStatus::fromArray($this->request('GET', 'status'));
    }

    public function connect(string $email, #[\SensitiveParameter] string $password, GarminHistory $history): GarminLogin
    {
        $response = $this->request('POST', 'login', [
            'email' => $email,
            'password' => $password,
            'since' => $history->value,
        ]);

        return 'mfa_required' === ($response['status'] ?? null) ? GarminLogin::CODE_REQUIRED : GarminLogin::CONNECTED;
    }

    public function confirmCode(string $code): void
    {
        $this->request('POST', 'mfa', ['code' => $code]);
    }

    public function syncNow(): void
    {
        $this->request('POST', 'sync');
    }

    public function refetchWeighIns(): void
    {
        $this->request('POST', 'weigh-ins');
    }

    public function disconnect(): void
    {
        $this->request('POST', 'disconnect');
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $action, array $payload = []): array
    {
        $secret = $this->readSecret();
        if (null === $secret || '' === $this->currentUser) {
            throw HealthSyncFailed::unreachable();
        }

        $options = [RequestOptions::HEADERS => ['Authorization' => 'Bearer '.$secret]];
        if ('POST' === $method) {
            $options[RequestOptions::JSON] = (object) $payload;
        }

        try {
            $response = $this->client->request($method, sprintf('v1/users/%s/%s', rawurlencode($this->currentUser), $action), $options);
            $body = Json::decode($response->getBody()->getContents());
        } catch (ConnectException $exception) {
            throw HealthSyncFailed::unreachable($exception);
        } catch (GuzzleException|CorruptedData $exception) {
            throw new HealthSyncFailed('unexpected', 'The Garmin sync service gave an unexpected answer.', $exception);
        }

        $body = is_array($body) ? $body : [];
        if ($response->getStatusCode() >= 400) {
            throw new HealthSyncFailed((string) ($body['error'] ?? 'unexpected'), (string) ($body['message'] ?? 'The Garmin sync service refused the request.'));
        }

        return $body;
    }

    private function readSecret(): ?string
    {
        $file = Tenants::getUsersDirectory($this->projectDir).'/'.self::SECRET_FILE_NAME;
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $secret = trim((string) file_get_contents($file));

        return '' === $secret ? null : $secret;
    }
}
