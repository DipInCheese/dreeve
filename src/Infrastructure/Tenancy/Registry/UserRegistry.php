<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy\Registry;

use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\TenantSecretFile;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * The users of a multi-user installation, stored in users/.users.json.
 *
 * The file is only readable by its owner, every change happens under an exclusive lock
 * and replaces the file atomically. Invite tokens are only stored as a hash.
 */
final readonly class UserRegistry
{
    public const string FILE_NAME = '.users.json';
    public const string REMOVED_DIRECTORY_NAME = '.removed';
    public const int MIN_PASSWORD_LENGTH = 12;
    public const int MAX_PASSWORD_LENGTH = 4096;
    public const string INVITE_VALIDITY = '+7 days';
    private const string NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * With addresses like https://dreeve.example.com/{user}, the user name is the first part of
     * every URL of that user. It can not be the first part of a URL of Dreeve itself (its pages
     * and static files), or links of that user would become ambiguous.
     */
    public const array RESERVED_NAMES = [
        // Pages and endpoints, see ReservedUserNamesTest.
        'activities', 'admin', 'api', 'badge', 'badges', 'best-efforts', 'challenges', 'chat', 'dashboard',
        'eddington', 'files', 'finish-setup', 'gear', 'heatmap', 'invite', 'milestones', 'monthly-stats',
        'photos', 'rewind', 'segments', 'strava', 'strava-oauth',
        // Static files in public/.
        'assets', 'css', 'js', 'libraries',
        // Kept free for the future.
        'activity', 'login', 'logout', 'segment', 'settings', 'setup', 'user', 'users', 'www',
    ];

    public function __construct(
        private string $usersDirectory,
    ) {
    }

    public static function isValidName(string $name): bool
    {
        return 1 === preg_match(self::NAME_PATTERN, $name);
    }

    public static function isReservedName(string $name): bool
    {
        return in_array($name, self::RESERVED_NAMES, true);
    }

    public function exists(): bool
    {
        return is_file($this->getPath());
    }

    /**
     * A fingerprint of the current registry, changes whenever a user is added, changed or removed.
     */
    public function getRevision(): string
    {
        $path = $this->getPath();
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return '';
        }

        return (string) hash_file('xxh128', $path);
    }

    /**
     * @return array<string, RegisteredUser>
     */
    public function findAll(): array
    {
        return $this->read();
    }

    public function find(string $name): ?RegisteredUser
    {
        return $this->read()[$name] ?? null;
    }

    public function get(string $name): RegisteredUser
    {
        return $this->find($name) ?? throw UserRegistryException::notFound($name);
    }

    public function findOwner(): ?RegisteredUser
    {
        foreach ($this->read() as $user) {
            if ($user->isOwner()) {
                return $user;
            }
        }

        return null;
    }

    public function hasPassword(string $name): bool
    {
        return null !== TenantSecretFile::read($this->getUserDirectory($name).'/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME);
    }

    public function getUserDirectory(string $name): string
    {
        if (!self::isValidName($name)) {
            throw UserRegistryException::invalidName($name);
        }

        return $this->usersDirectory.'/'.$name;
    }

    /**
     * Adds a user and returns the (plain) invite token. Only its hash is stored.
     */
    public function add(string $name, bool $isOwner, \DateTimeImmutable $now): string
    {
        if (!self::isValidName($name)) {
            throw UserRegistryException::invalidName($name);
        }
        if (self::isReservedName($name)) {
            throw UserRegistryException::reservedName($name);
        }

        $token = $this->generateInviteToken();
        $this->modify(function (array $users) use ($name, $isOwner, $now, $token): array {
            if (isset($users[$name])) {
                throw UserRegistryException::alreadyExists($name);
            }
            if (is_dir($this->getUserDirectory($name)) && null !== TenantSecretFile::read($this->getUserDirectory($name).'/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME)) {
                // Left-overs of an earlier user with the same name must never give access to the new one.
                unlink($this->getUserDirectory($name).'/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME);
            }

            $users[$name] = RegisteredUser::create($name, $isOwner, $now)
                ->withInvite($token, $now->modify(self::INVITE_VALIDITY));

            return $users;
        });

        return $token;
    }

    /**
     * Creates a new invite link for the user, which replaces any earlier one. Accepting it sets a new
     * password, which makes it the way to reset a forgotten password. The current password keeps
     * working until the invite is accepted.
     */
    public function createInvite(string $name, \DateTimeImmutable $now): string
    {
        $token = $this->generateInviteToken();
        $this->modify(static function (array $users) use ($name, $now, $token): array {
            $user = $users[$name] ?? throw UserRegistryException::notFound($name);
            $users[$name] = $user->withInvite($token, $now->modify(self::INVITE_VALIDITY));

            return $users;
        });

        return $token;
    }

    public function isValidInvite(string $name, #[\SensitiveParameter] string $token, \DateTimeImmutable $now): bool
    {
        $user = $this->find($name);

        return $user instanceof RegisteredUser && !$user->isDisabled() && $user->inviteTokenMatches($token, $now);
    }

    /**
     * Sets the user's password with a valid invite token. The token can only be used once.
     */
    public function acceptInvite(
        string $name,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $password,
        \DateTimeImmutable $now,
    ): void {
        self::guardPassword($password);

        $this->modify(function (array $users) use ($name, $token, $password, $now): array {
            $user = $users[$name] ?? null;
            if (!$user instanceof RegisteredUser || $user->isDisabled() || !$user->inviteTokenMatches($token, $now)) {
                throw UserRegistryException::invalidInvite();
            }

            $directory = $this->getUserDirectory($name);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw UserRegistryException::couldNotWrite($directory);
            }
            TenantSecretFile::write(
                $directory.'/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME,
                new NativePasswordHasher()->hash($password),
            );

            $users[$name] = $user->withoutInvite();

            return $users;
        });
    }

    public function setDisabled(string $name, bool $isDisabled): void
    {
        $this->modify(static function (array $users) use ($name, $isDisabled): array {
            $user = $users[$name] ?? throw UserRegistryException::notFound($name);
            if ($user->isOwner()) {
                throw UserRegistryException::ownerCanNotBeChanged($name);
            }
            $users[$name] = $user->withDisabled($isDisabled);

            return $users;
        });
    }

    /**
     * Removes the user. Nothing is deleted: the user's data directory is moved to
     * users/.removed/<name>-<date>, from where it can be restored or deleted by hand.
     */
    public function remove(string $name, \DateTimeImmutable $now): void
    {
        $this->modify(function (array $users) use ($name, $now): array {
            $user = $users[$name] ?? throw UserRegistryException::notFound($name);
            if ($user->isOwner()) {
                throw UserRegistryException::ownerCanNotBeChanged($name);
            }

            $directory = $this->getUserDirectory($name);
            if (is_dir($directory)) {
                $removedDirectory = $this->usersDirectory.'/'.self::REMOVED_DIRECTORY_NAME;
                if (!is_dir($removedDirectory) && !mkdir($removedDirectory, 0o700, true) && !is_dir($removedDirectory)) {
                    throw UserRegistryException::couldNotWrite($removedDirectory);
                }
                if (!@rename($directory, $removedDirectory.'/'.$name.'-'.$now->format('Ymd-His'))) {
                    throw UserRegistryException::couldNotMoveData($directory, $removedDirectory);
                }
            }

            unset($users[$name]);

            return $users;
        });
    }

    public static function guardPassword(#[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw UserRegistryException::passwordTooShort(self::MIN_PASSWORD_LENGTH);
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new UserRegistryException(sprintf('The password can be at most %d characters', self::MAX_PASSWORD_LENGTH));
        }
    }

    private function getPath(): string
    {
        return $this->usersDirectory.'/'.self::FILE_NAME;
    }

    /**
     * @return array<string, RegisteredUser>
     */
    private function read(): array
    {
        $path = $this->getPath();
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw UserRegistryException::corrupt($path);
        }

        try {
            $data = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UserRegistryException::corrupt($path);
        }
        if (!is_array($data) || !is_array($data['users'] ?? null)) {
            throw UserRegistryException::corrupt($path);
        }

        $users = [];
        foreach ($data['users'] as $userData) {
            if (!is_array($userData) || !self::isValidName((string) ($userData['name'] ?? ''))) {
                throw UserRegistryException::corrupt($path);
            }
            $user = RegisteredUser::fromArray($userData);
            $users[$user->getName()] = $user;
        }
        ksort($users);

        return $users;
    }

    /**
     * @param \Closure(array<string, RegisteredUser>): array<string, RegisteredUser> $change
     */
    private function modify(\Closure $change): void
    {
        if (!is_dir($this->usersDirectory) && !mkdir($this->usersDirectory, 0o775, true) && !is_dir($this->usersDirectory)) {
            throw UserRegistryException::couldNotWrite($this->usersDirectory);
        }

        $lock = fopen($this->usersDirectory.'/.users.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX)) {
            throw UserRegistryException::couldNotWrite($this->getPath());
        }

        try {
            $users = $change($this->read());
            ksort($users);

            TenantSecretFile::write($this->getPath(), json_encode(
                ['users' => array_values(array_map(static fn (RegisteredUser $user): array => $user->toArray(), $users))],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function generateInviteToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
