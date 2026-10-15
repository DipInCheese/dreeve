<?php

declare(strict_types=1);

namespace App\Controller\Admin\User;

use App\Domain\UserManagement\AddUser\AddUser;
use App\Domain\UserManagement\ChangeUserStatus\ChangeUserStatus;
use App\Domain\UserManagement\InviteLinkFlash;
use App\Domain\UserManagement\InviteUser\InviteUser;
use App\Domain\UserManagement\RemoveUser\RemoveUser;
use App\Domain\UserManagement\UserManagementGuard;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Tenancy\Registry\RegisteredUser;
use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\UserAdministration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Lets the owner of a multi-user installation add, invite, disable and remove users.
 */
#[AsController]
final readonly class ManageUsersRequestHandler
{
    public function __construct(
        private Environment $twig,
        private UserManagementGuard $guard,
        private UserAdministration $userAdministration,
    ) {
    }

    #[Route(path: '/admin/users', name: 'admin_manage_users', methods: ['GET'], priority: 10)]
    public function handle(Request $request): Response
    {
        $this->guardAccess();

        $session = $request->getSession();
        $inviteLinks = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(InviteLinkFlash::TYPE) : [];

        $users = array_map(fn (RegisteredUser $user): array => [
            'name' => $user->getName(),
            'url' => $this->userAdministration->getUserUrl($user->getName()),
            'isOwner' => $user->isOwner(),
            'isDisabled' => $user->isDisabled(),
            'hasPassword' => $this->userAdministration->hasPassword($user->getName()),
            'isCurrentUser' => $user->getName() === $this->guard->getCurrentUser(),
            'createdOn' => $user->getCreatedOn(),
        ], array_values($this->userAdministration->findAll()));

        return $this->noStore(new HtmlResponse($this->twig->render('html/admin/page/user/manage-users.html.twig', [
            'users' => $users,
            'inviteLinks' => $inviteLinks,
            'addUserCommand' => AddUser::getCommandName(),
            'inviteUserCommand' => InviteUser::getCommandName(),
            'changeUserStatusCommand' => ChangeUserStatus::getCommandName(),
            'exampleUserUrl' => $this->userAdministration->getUserUrl('name'),
        ])));
    }

    #[Route(path: '/admin/users/{name}/remove', name: 'admin_remove_user', methods: ['GET'], priority: 10)]
    public function handleRemove(string $name): Response
    {
        $this->guardAccess();

        $user = UserRegistry::isValidName($name) ? ($this->userAdministration->findAll()[$name] ?? null) : null;
        if (!$user instanceof RegisteredUser || $user->isOwner()) {
            throw new NotFoundHttpException(sprintf('User "%s" not found', $name));
        }

        return $this->noStore(new HtmlResponse($this->twig->render('html/admin/page/user/remove-user.html.twig', [
            'dispatchCommand' => RemoveUser::getCommandName(),
            'userName' => $user->getName(),
        ])));
    }

    private function guardAccess(): void
    {
        // Pretend the page does not exist for anyone but the owner.
        if (!$this->guard->isAllowed()) {
            throw new NotFoundHttpException();
        }
    }

    private function noStore(Response $response): Response
    {
        // The page can hold a freshly created invite link, never cache it.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
