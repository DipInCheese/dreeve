<?php

declare(strict_types=1);

namespace App\Infrastructure\Twig;

use App\Application\Navigation\NavigationSection;
use App\Application\Navigation\SideBar;
use App\Application\ProfilePictureUrl;
use App\Domain\Athlete\Athlete;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\HealthSync\HealthSync;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Lets the management pages render inside the same shell as the rest of the app:
 * one top bar, one sidebar.
 */
final readonly class AppShellTwigExtension
{
    public function __construct(
        private SideBar $sideBar,
        private SettingsRepository $settingsRepository,
        private RequestStack $requestStack,
        private HealthSync $healthSync,
    ) {
    }

    #[AsTwigFunction('canConnectGarmin')]
    public function canConnectGarmin(): bool
    {
        return $this->healthSync->isAvailable();
    }

    #[AsTwigFunction('appSidebar')]
    public function appSidebar(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $activeSection = $request instanceof Request ? NavigationSection::forManagePath($request->getPathInfo()) : null;

        return $this->sideBar->render($activeSection) ?? '';
    }

    #[AsTwigFunction('appAthlete')]
    public function appAthlete(): Athlete
    {
        return $this->settingsRepository->general()->getAthlete();
    }

    #[AsTwigFunction('appProfilePictureUrl')]
    public function appProfilePictureUrl(): ?ProfilePictureUrl
    {
        return $this->settingsRepository->general()->getProfilePictureUrl();
    }
}
