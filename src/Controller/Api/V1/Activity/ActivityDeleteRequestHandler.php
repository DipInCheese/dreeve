<?php

declare(strict_types=1);

namespace App\Controller\Api\V1\Activity;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\DeleteActivity\DeleteActivity;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Exception\EntityNotFound;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ActivityDeleteRequestHandler
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private CommandBus $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/activities/{activityId}', name: 'api_v1_activity_delete', methods: ['DELETE'], priority: 3)]
    public function handle(string $activityId): Response
    {
        try {
            $activity = $this->activityRepository->find(ActivityId::fromString($activityId));
        } catch (EntityNotFound|\InvalidArgumentException) {
            throw new NotFoundHttpException(sprintf('Activity "%s" not found', $activityId));
        }

        $this->commandBus->dispatch(new DeleteActivity($activity->getId()));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
