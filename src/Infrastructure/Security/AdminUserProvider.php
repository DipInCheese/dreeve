<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\TenantSecretFile;
use App\Infrastructure\ValueObject\String\DataDir;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<InMemoryUser>
 */
final readonly class AdminUserProvider implements UserProviderInterface
{
    public function __construct(
        private AdminUserName $adminUsername,
        private AdminPasswordHash $adminPasswordHash,
        private ?DataDir $dataDir = null,
        #[Autowire('%app.user%')]
        private string $tenantName = '',
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $passwordHash = $this->getPasswordHash();
        if ($passwordHash->isEmpty() || $identifier !== (string) $this->adminUsername) {
            throw new UserNotFoundException();
        }

        return new InMemoryUser(
            username: (string) $this->adminUsername,
            password: (string) $passwordHash,
            roles: ['ROLE_ADMIN'],
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }

    /**
     * On a multi-user installation the password hash lives in the user's directory, where an
     * accepted invite writes it. It is read on every request: a new password works right away,
     * and changing it ends every existing session of that user.
     */
    private function getPasswordHash(): AdminPasswordHash
    {
        if ('' === $this->tenantName || !$this->dataDir instanceof DataDir) {
            return $this->adminPasswordHash;
        }

        return AdminPasswordHash::fromString(
            TenantSecretFile::read($this->dataDir.'/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME) ?? ''
        );
    }
}
