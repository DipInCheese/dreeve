<?php

declare(strict_types=1);

namespace App\Controller\Admin\Settings;

use App\Application\AppUrl;
use App\Domain\Athlete\HeartRateZone\HeartRateZoneConfiguration;
use App\Domain\Integration\AI\AIApiKey;
use App\Domain\Settings\DbalSettingsRepository;
use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Settings\UpdateSettings\UpdateSettings;
use App\Infrastructure\Config\AdminAllowedIpAddresses;
use App\Infrastructure\Http\ClientIpResolver;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Security\AdminUserName;
use App\Infrastructure\Security\Api\Token;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsController]
final readonly class SettingsRequestHandler
{
    public function __construct(
        private Environment $twig,
        #[Autowire(service: DbalSettingsRepository::class)]
        private SettingsRepository $settingsRepository,
        private AppUrl $appUrl,
        private UrlGeneratorInterface $urlGenerator,
        private AdminUserName $adminUserName,
        private AdminAllowedIpAddresses $adminAllowedIpAddresses,
        private ClientIpResolver $clientIpResolver,
        private Token $apiKey,
    ) {
    }

    #[Route(path: '/admin/settings', name: 'admin_settings_index', methods: ['GET'], priority: 10)]
    public function index(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('admin_settings', [
            'group' => SettingsGroup::GENERAL->value,
        ]));
    }

    #[Route(path: '/admin/settings/athlete', name: 'admin_settings_athlete', methods: ['GET'], priority: 10)]
    public function athlete(): RedirectResponse
    {
        // The first questions about you moved into the welcome flow.
        return new RedirectResponse($this->urlGenerator->generate('welcome'));
    }

    #[Route(path: '/admin/settings/{group}', name: 'admin_settings', methods: ['GET'], priority: 5)]
    public function handle(string $group, Request $request): HtmlResponse
    {
        $settingsGroup = SettingsGroup::tryFrom($group)
            ?? throw new NotFoundHttpException(sprintf('Unknown settings group "%s"', $group));

        return new HtmlResponse($this->twig->render(
            sprintf('html/admin/page/settings/%s.html.twig', $settingsGroup->value),
            [
                'dispatchCommand' => UpdateSettings::getCommandName(),
                'group' => $settingsGroup,
                'settings' => $this->settingsRepository->findGroup($settingsGroup),
                'appUrl' => $this->appUrl,
                'AIApiKey' => AIApiKey::fromServerVar(),
                'sportTypes' => $this->settingsRepository->appearance()->getSportTypesSortingOrder(),
                'defaultHeartRateZones' => HeartRateZoneConfiguration::getDefaultZones(),
                'adminUsername' => $this->adminUserName,
                'adminAllowedIpAddresses' => $this->adminAllowedIpAddresses,
                'clientIpAddress' => $this->clientIpResolver->resolve($request),
                'apiKey' => $this->apiKey,
            ],
        ));
    }
}
