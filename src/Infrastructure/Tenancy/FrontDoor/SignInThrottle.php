<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy\FrontDoor;

/**
 * Limits failed sign-ins on the bare address, like the users' own login pages do: at most
 * 5 per 15 minutes for a user name from one IP address, and 20 per 15 minutes from one IP address.
 * The failures are kept in a small file next to the users.
 */
final readonly class SignInThrottle
{
    public const string FILE_NAME = '.front-door-throttle.json';
    private const int WINDOW_SECONDS = 900;
    private const int MAX_PER_USER_AND_IP = 5;
    private const int MAX_PER_IP = 20;

    private function __construct(
        private string $file,
    ) {
    }

    public static function inDirectory(string $usersDirectory): self
    {
        return new self(rtrim($usersDirectory, '/\\').'/'.self::FILE_NAME);
    }

    public function isBlocked(string $ip, string $username, \DateTimeImmutable $now): bool
    {
        $failures = $this->withFailures($now, static fn (array $failures): array => $failures);

        return count($failures[self::key($ip, $username)] ?? []) >= self::MAX_PER_USER_AND_IP
            || count($failures[self::key($ip, null)] ?? []) >= self::MAX_PER_IP;
    }

    public function recordFailure(string $ip, string $username, \DateTimeImmutable $now): void
    {
        $this->withFailures($now, static function (array $failures) use ($ip, $username, $now): array {
            foreach ([self::key($ip, $username), self::key($ip, null)] as $key) {
                $failures[$key][] = $now->getTimestamp();
            }

            return $failures;
        });
    }

    public function clear(string $ip, string $username, \DateTimeImmutable $now): void
    {
        $this->withFailures($now, static function (array $failures) use ($ip, $username): array {
            unset($failures[self::key($ip, $username)]);

            return $failures;
        });
    }

    private static function key(string $ip, ?string $username): string
    {
        return hash('sha256', $ip.'|'.($username ?? '*'));
    }

    /**
     * @param \Closure(array<string, int[]>): array<string, int[]> $change
     *
     * @return array<string, int[]>
     */
    private function withFailures(\DateTimeImmutable $now, \Closure $change): array
    {
        $handle = fopen($this->file, 'c+');
        if (false === $handle) {
            return [];
        }

        try {
            flock($handle, LOCK_EX);
            $data = json_decode((string) stream_get_contents($handle), true);
            $failures = [];
            // Only failures within the window count; older ones are dropped.
            foreach (is_array($data) ? $data : [] as $key => $timestamps) {
                $recent = array_values(array_filter(
                    is_array($timestamps) ? $timestamps : [],
                    static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now->getTimestamp() - self::WINDOW_SECONDS,
                ));
                if ([] !== $recent) {
                    $failures[(string) $key] = $recent;
                }
            }

            $changed = $change($failures);
            if ($changed !== $failures || $failures !== $data) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($changed, JSON_THROW_ON_ERROR));
            }

            return $changed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
