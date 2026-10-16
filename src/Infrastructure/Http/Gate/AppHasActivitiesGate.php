<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Gate;

use App\Domain\Activity\ActivityIdRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsTaggedItem(priority: 70)]
final class AppHasActivitiesGate extends ConditionalRedirectGate
{
    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        private readonly ActivityIdRepository $activityIdRepository,
    ) {
        parent::__construct($urlGenerator);
    }

    protected function shouldGuard(): bool
    {
        return $this->activityIdRepository->count() <= 0;
    }

    protected function allowedPaths(): array
    {
        // Keep the admin panel reachable, it is the only way to upload files.
        return ['/admin'];
    }

    protected function redirectToRouteName(): string
    {
        return 'finish_setup';
    }
}
