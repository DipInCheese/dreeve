<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Health and activity data is personal: every page requires a login. Only the pages needed to get
 * a login, and the static files, are public.
 */
#[AsDecorator('security.access_map')]
final readonly class AppAccessMap implements AccessMapInterface
{
    /**
     * @var list<string>
     */
    private const array PUBLIC_PATHS = [
        // Invited users pick their password here, before they can log in. Protected by the invite token.
        '/invite',
        '/manifest.json',
        // Caddy serves these.
        '/assets',
        '/css',
        '/js',
        '/images',
        '/libraries',
    ];

    public function __construct(
        #[AutowireDecorated]
        private AccessMapInterface $accessMap,
    ) {
    }

    /**
     * @return array{0: array<string>|null, 1: string|null}
     */
    public function getPatterns(Request $request): array
    {
        [$attributes, $channel] = $this->accessMap->getPatterns($request);
        if (null !== $attributes) {
            // security.yaml has the first say, ^/admin/login stays public.
            return [$attributes, $channel];
        }

        if ($this->isPublicPath($request->getPathInfo())) {
            return [null, null];
        }

        return [['ROLE_ADMIN'], null];
    }

    private function isPublicPath(string $path): bool
    {
        return array_any(self::PUBLIC_PATHS, fn (string $publicPath): bool => $path === $publicPath || str_starts_with($path, $publicPath.'/'));
    }
}
