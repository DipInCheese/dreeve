<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Settings\DbalSettingsRepository;
use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Settings\UpdateAthleteSettings\UpdateAthleteSettings;
use App\Infrastructure\HealthSync\HealthSync;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The first visit: a few questions about you, then where your data comes from. Nothing here is a
 * wall: after the first step, everything is optional.
 */
#[AsController]
final readonly class WelcomeRequestHandler
{
    // Remembers that Garmin was connected from the welcome flow, to land on Today afterwards.
    public const string SESSION_KEY = 'welcome_in_progress';

    public function __construct(
        private Environment $twig,
        #[Autowire(service: DbalSettingsRepository::class)]
        private SettingsRepository $settingsRepository,
        private HealthSync $healthSync,
    ) {
    }

    #[Route(path: '/welcome', name: 'welcome', methods: ['GET'])]
    public function aboutYou(): Response
    {
        return $this->noStore(new HtmlResponse($this->twig->render('html/welcome/about-you.html.twig', [
            'dispatchCommand' => UpdateAthleteSettings::getCommandName(),
            'settings' => $this->settingsRepository->findGroup(SettingsGroup::GENERAL),
            'step' => 1,
        ])));
    }

    #[Route(path: '/welcome/connect', name: 'welcome_connect', methods: ['GET'])]
    public function connect(Request $request): Response
    {
        $request->getSession()->set(self::SESSION_KEY, true);

        return $this->noStore(new HtmlResponse($this->twig->render('html/welcome/connect.html.twig', [
            'canConnectGarmin' => $this->healthSync->isAvailable(),
            'step' => 2,
        ])));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
