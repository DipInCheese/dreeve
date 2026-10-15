<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy\Registry;

/**
 * A user as stored in the user registry. Passwords are not stored here, every user's
 * password hash lives in their own data directory.
 */
final readonly class RegisteredUser
{
    private function __construct(
        private string $name,
        private bool $isOwner,
        private bool $isDisabled,
        private \DateTimeImmutable $createdOn,
        private ?string $inviteTokenHash,
        private ?\DateTimeImmutable $inviteExpiresOn,
    ) {
    }

    public static function create(string $name, bool $isOwner, \DateTimeImmutable $createdOn): self
    {
        return new self(
            name: $name,
            isOwner: $isOwner,
            isDisabled: false,
            createdOn: $createdOn,
            inviteTokenHash: null,
            inviteExpiresOn: null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            isOwner: (bool) ($data['isOwner'] ?? false),
            isDisabled: (bool) ($data['isDisabled'] ?? false),
            createdOn: new \DateTimeImmutable((string) $data['createdOn']),
            inviteTokenHash: isset($data['inviteTokenHash']) ? (string) $data['inviteTokenHash'] : null,
            inviteExpiresOn: isset($data['inviteExpiresOn']) ? new \DateTimeImmutable((string) $data['inviteExpiresOn']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'isOwner' => $this->isOwner,
            'isDisabled' => $this->isDisabled,
            'createdOn' => $this->createdOn->format(\DateTimeInterface::ATOM),
            'inviteTokenHash' => $this->inviteTokenHash,
            'inviteExpiresOn' => $this->inviteExpiresOn?->format(\DateTimeInterface::ATOM),
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isOwner(): bool
    {
        return $this->isOwner;
    }

    public function isDisabled(): bool
    {
        return $this->isDisabled;
    }

    public function getCreatedOn(): \DateTimeImmutable
    {
        return $this->createdOn;
    }

    public function getInviteExpiresOn(): ?\DateTimeImmutable
    {
        return $this->inviteExpiresOn;
    }

    public function hasValidInvite(\DateTimeImmutable $now): bool
    {
        return null !== $this->inviteTokenHash && $this->inviteExpiresOn instanceof \DateTimeImmutable && $this->inviteExpiresOn > $now;
    }

    public function inviteTokenMatches(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): bool
    {
        if (!$this->hasValidInvite($now) || '' === $token) {
            return false;
        }

        return hash_equals((string) $this->inviteTokenHash, $this->hashInviteToken($token));
    }

    public function withInvite(#[\SensitiveParameter] string $token, \DateTimeImmutable $expiresOn): self
    {
        return new self($this->name, $this->isOwner, $this->isDisabled, $this->createdOn, $this->hashInviteToken($token), $expiresOn);
    }

    public function withoutInvite(): self
    {
        return new self($this->name, $this->isOwner, $this->isDisabled, $this->createdOn, null, null);
    }

    public function withDisabled(bool $isDisabled): self
    {
        return new self($this->name, $this->isOwner, $isDisabled, $this->createdOn, $this->inviteTokenHash, $this->inviteExpiresOn);
    }

    /**
     * Invite tokens are random 256-bit values, a fast hash is enough: there is nothing to brute force.
     * Only the hash is stored, so the registry file alone can not be used to accept an invite.
     */
    private function hashInviteToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
