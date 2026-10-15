<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\Registry\RegisteredUser;
use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\Registry\UserRegistryException;

/**
 * Everything the owner of a multi-user installation can do with users,
 * shared by the admin panel and bin/dreeve-users.
 */
final readonly class UserAdministration
{
    public const string INVITE_PATH = '/invite/';

    public function __construct(
        private UserRegistry $registry,
        private ?UserUrlTemplate $userUrlTemplate,
        private UserProvisioner $provisioner,
    ) {
    }

    public static function fromProjectDir(string $projectDir, string $userUrl): self
    {
        return new self(
            registry: new UserRegistry(Tenants::getUsersDirectory($projectDir)),
            userUrlTemplate: '' === trim($userUrl) ? null : UserUrlTemplate::fromString($userUrl),
            provisioner: new MigratingUserProvisioner($projectDir),
        );
    }

    public function isMultiUser(): bool
    {
        return $this->userUrlTemplate instanceof UserUrlTemplate;
    }

    public function isOwner(string $name): bool
    {
        return '' !== $name && true === $this->registry->find($name)?->isOwner();
    }

    /**
     * @return array<string, RegisteredUser>
     */
    public function findAll(): array
    {
        return $this->registry->findAll();
    }

    public function hasPassword(string $name): bool
    {
        return $this->registry->hasPassword($name);
    }

    public function getUserUrl(string $name): string
    {
        return $this->getUserUrlTemplate()->forUser($name);
    }

    /**
     * Adds a user, prepares its database and returns the invite link.
     */
    public function addUser(string $name, \DateTimeImmutable $now, bool $isOwner = false): string
    {
        $token = $this->registry->add($name, $isOwner, $now);
        $this->provisioner->provision($name);

        return $this->buildInviteUrl($name, $token);
    }

    /**
     * Creates the owner on the very first start, and hands out a fresh invite link for as long
     * as the owner has not picked a password. Returns null when there is nothing to do.
     */
    public function bootstrapOwner(string $ownerName, \DateTimeImmutable $now): ?string
    {
        $owner = $this->registry->findOwner();
        if (!$owner instanceof RegisteredUser) {
            if ([] !== $this->registry->findAll()) {
                return null;
            }
            if ('' === $ownerName) {
                throw new UserRegistryException('Set DREEVE_OWNER to the user name of the owner (you), e.g. DREEVE_OWNER=alice');
            }

            return $this->addUser($ownerName, $now, isOwner: true);
        }

        if ($this->registry->hasPassword($owner->getName())) {
            return null;
        }

        return $this->createInviteUrl($owner->getName(), $now);
    }

    public function createInviteUrl(string $name, \DateTimeImmutable $now): string
    {
        return $this->buildInviteUrl($name, $this->registry->createInvite($name, $now));
    }

    public function isValidInvite(string $name, #[\SensitiveParameter] string $token, \DateTimeImmutable $now): bool
    {
        return $this->registry->isValidInvite($name, $token, $now);
    }

    public function acceptInvite(string $name, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password, \DateTimeImmutable $now): void
    {
        $this->registry->acceptInvite($name, $token, $password, $now);
    }

    public function setDisabled(string $name, bool $isDisabled): void
    {
        $this->registry->setDisabled($name, $isDisabled);
    }

    public function remove(string $name, \DateTimeImmutable $now): void
    {
        $this->registry->remove($name, $now);
    }

    private function buildInviteUrl(string $name, #[\SensitiveParameter] string $token): string
    {
        return $this->getUserUrlTemplate()->forUser($name).self::INVITE_PATH.$token;
    }

    private function getUserUrlTemplate(): UserUrlTemplate
    {
        return $this->userUrlTemplate ?? throw new UserRegistryException(sprintf('%s is not set, users can not be managed', UserUrlTemplate::VARIABLE));
    }
}
