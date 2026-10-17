<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy\FrontDoor;

use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\Tenants;
use App\Infrastructure\Tenancy\TenantSecretFile;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The bare address of a multi-user installation where users live under a path
 * (https://dreeve.example.com/alice): a sign-in page for every user. After a correct password
 * the browser is handed over to the user's own path, see LoginHandoff.
 */
final class FrontDoor
{
    /** Remembers who signed in here last, so the bare address takes them straight to their pages. */
    public const string USER_COOKIE = 'dreeve_user';
    private const string CSRF_COOKIE = 'dreeve_front_door';
    private const int REMEMBER_FOR_SECONDS = 604800;

    private ?Environment $twig = null;
    private ?string $dummyHash = null;

    /**
     * @param \Closure(): \DateTimeImmutable $now
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $usersDirectory,
        private readonly \Closure $now,
        private readonly string $trustedProxies = '',
    ) {
    }

    /**
     * @return Response|null null when the request is not for the front door
     */
    public function handle(Request $request, Tenants $tenants): ?Response
    {
        $usersOnThisHost = $this->findUsersOnHost($request->getHost(), $tenants);
        if ([] === $usersOnThisHost) {
            return null;
        }

        if ('' !== $this->trustedProxies) {
            Request::setTrustedProxies(
                array_map(trim(...), explode(',', $this->trustedProxies)),
                Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
            );
        }

        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        if ('/' !== $path) {
            return $this->render('not-found.html.twig', ['status' => 404], Response::HTTP_NOT_FOUND);
        }

        if ($request->isMethod('POST')) {
            return $this->signIn($request, $usersOnThisHost);
        }

        $remembered = (string) $request->cookies->get(self::USER_COOKIE, '');
        if (isset($usersOnThisHost[$remembered])) {
            return $this->noStore(new RedirectResponse($usersOnThisHost[$remembered]->getBasePath().'/'));
        }

        return $this->renderSignIn($request);
    }

    /**
     * @param array<string, Tenant> $users
     */
    private function signIn(Request $request, array $users): Response
    {
        $username = strtolower(trim((string) $request->request->get('_username', '')));
        $password = (string) $request->request->get('_password', '');
        $rememberMe = $request->request->has('_remember_me');
        $ip = (string) $request->getClientIp();
        $now = ($this->now)();

        $csrfCookie = (string) $request->cookies->get(self::CSRF_COOKIE, '');
        if ('' === $csrfCookie || !hash_equals($csrfCookie, (string) $request->request->get('_csrf_token', ''))) {
            return $this->renderSignIn($request, 'Your sign-in form expired. Please try again.', $username, Response::HTTP_FORBIDDEN);
        }

        $throttle = SignInThrottle::inDirectory($this->usersDirectory);
        if ($throttle->isBlocked($ip, $username, $now)) {
            return $this->renderSignIn($request, 'Too many failed attempts. Please try again in 15 minutes.', $username, Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $users[$username] ?? null;
        $hash = $user instanceof Tenant ? TenantSecretFile::read($user->getDataDirectory().'/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME) : null;
        // Always check a hash, so an unknown user name takes as long as a wrong password.
        $isValid = new NativePasswordHasher()->verify($hash ?? $this->dummyHash(), $password) && null !== $hash;

        if (!$user instanceof Tenant || !$isValid) {
            $throttle->recordFailure($ip, $username, $now);

            return $this->renderSignIn($request, 'Wrong user name or password.', $username, Response::HTTP_UNAUTHORIZED);
        }

        $throttle->clear($ip, $username, $now);
        $token = LoginHandoff::inDirectory($user->getDataDirectory())->create($rememberMe, $now);

        $response = new RedirectResponse($user->getBasePath().'/admin/login/handoff?token='.$token, Response::HTTP_SEE_OTHER);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->setCookie(Cookie::create(self::USER_COOKIE)
            ->withValue($user->getName())
            ->withExpires($rememberMe ? $now->getTimestamp() + self::REMEMBER_FOR_SECONDS : 0)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));

        return $this->noStore($response);
    }

    /**
     * @return array<string, Tenant>
     */
    private function findUsersOnHost(string $host, Tenants $tenants): array
    {
        $users = [];
        foreach ($tenants as $tenant) {
            if ('' !== $tenant->getBasePath() && $tenant->getHost() === strtolower($host)) {
                $users[$tenant->getName()] = $tenant;
            }
        }

        return $users;
    }

    private function renderSignIn(Request $request, ?string $error = null, string $username = '', int $status = Response::HTTP_OK): Response
    {
        $csrfToken = (string) $request->cookies->get(self::CSRF_COOKIE, '');
        if ('' === $csrfToken) {
            $csrfToken = bin2hex(random_bytes(32));
        }

        $response = $this->render('sign-in.html.twig', [
            'error' => $error,
            'username' => $username,
            'csrfToken' => $csrfToken,
        ], $status);
        $response->headers->setCookie(Cookie::create(self::CSRF_COOKIE)
            ->withValue($csrfToken)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));

        return $response;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status): Response
    {
        $html = $this->twig()->render('html/front-door/'.$template, [
            ...$context,
            'assetVersion' => $this->assetVersion(),
        ]);

        return $this->noStore(new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'DENY',
        ]));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function twig(): Environment
    {
        return $this->twig ??= new Environment(
            new FilesystemLoader($this->projectDir.'/templates'),
            ['autoescape' => 'html', 'cache' => false, 'strict_variables' => true],
        );
    }

    private function assetVersion(): string
    {
        $css = $this->projectDir.'/public/css/dist/tailwind.min.css';

        return is_file($css) ? substr((string) md5_file($css), 0, 8) : '1';
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= new NativePasswordHasher()->hash(bin2hex(random_bytes(16)));
    }
}
