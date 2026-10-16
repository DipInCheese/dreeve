<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Application\AppVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class StatusRequestHandler
{
    #[Route(path: '/api/v1/status', name: 'api_v1_status', methods: ['GET'], priority: 3)]
    public function handle(): JsonResponse
    {
        return new JsonResponse([
            'app' => 'dreeve',
            'version' => AppVersion::getSemanticVersion(),
            'canUpload' => true,
        ]);
    }
}
