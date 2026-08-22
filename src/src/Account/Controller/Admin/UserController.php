<?php

declare(strict_types=1);

namespace App\Account\Controller\Admin;

use App\Account\Dto\CreateTrainerInput;
use App\Account\Dto\EditUserInput;
use App\Account\Dto\UserDirectoryFilter;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Exception\LastSuperAdminProtected;
use App\Account\Form\CreateTrainerFormType;
use App\Account\Form\EditUserFormType;
use App\Account\Service\TrainerAccountCreator;
use App\Account\Service\UserDirectoryQuery;
use App\Account\Service\UserProfileEditor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The Users tool (FR-020, FR-021, FR-023).
 *
 * The whole `/admin` tree is already Super-Admin-only through `access_control`, so these
 * actions carry no role checks of their own — the gate is one rule in configuration rather
 * than an attribute per method that someone can forget.
 */
final class UserController extends AbstractController
{
    #[Route('/admin/users', name: 'admin_users_index', methods: ['GET'])]
    public function index(Request $request, UserDirectoryQuery $directory): Response
    {
        $filter = UserDirectoryFilter::fromRequest($request);

        // KnpPaginator reads the sort field from the query string itself rather than from the
        // filter, and throws on one it does not recognize. `fromRequest()` has already turned
        // an unrecognized value into null; dropping it here is what stops a stale bookmark or
        // a hand-edited URL from becoming a 500 inside the paginator.
        if (null === $filter->sort) {
            $request->query->remove('sort');
            $request->query->remove('direction');
        }

        return $this->render('admin/users/index.html.twig', [
            'users' => $directory->search($filter, $request->query->getInt('page', 1)),
            'filter' => $filter,
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    #[Route('/admin/users/new', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[CurrentUser] User $actor,
        TrainerAccountCreator $creator,
    ): Response {
        $form = $this->createForm(CreateTrainerFormType::class, new CreateTrainerInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CreateTrainerInput $input */
            $input = $form->getData();

            try {
                $result = $creator->create($input, $actor);
            } catch (EmailAlreadyRegistered $e) {
                // Reported on the field that caused it rather than as a page-level flash, so
                // the message sits next to the input the operator has to change (FR-021).
                $form->get('email')->addError(new FormError($e->getMessage()));

                return $this->render('admin/users/new.html.twig', ['form' => $form]);
            }

            if ($result->invitationSent) {
                $this->addFlash('success', \sprintf(
                    'Trainer %s created. An invitation with their temporary password has been sent to %s.',
                    $result->user->getName(),
                    $result->user->getEmail(),
                ));
            } else {
                // The account exists either way — the mail is sent after the transaction
                // commits. Saying so is the difference between an operator who resends the
                // invitation and one who wonders why the trainer never signed in.
                $this->addFlash('warning', \sprintf(
                    'Trainer %s was created, but the invitation email could not be sent. Use "Forgot password" to get them a link, or check the mail transport.',
                    $result->user->getName(),
                ));
            }

            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/users/new.html.twig', ['form' => $form]);
    }

    #[Route('/admin/users/{id}/edit', name: 'admin_users_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        // Explicit because `doctrine.orm.controller_resolver.auto_mapping` is false in this
        // project: without the attribute the argument is not resolved at all.
        #[MapEntity(id: 'id')] User $user,
        #[CurrentUser] User $actor,
        UserProfileEditor $editor,
    ): Response {
        $form = $this->createForm(EditUserFormType::class, EditUserInput::fromUser($user));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var EditUserInput $input */
            $input = $form->getData();

            try {
                $editor->apply($user, $input, $actor);
            } catch (EmailAlreadyRegistered $e) {
                $form->get('email')->addError(new FormError($e->getMessage()));

                return $this->render('admin/users/edit.html.twig', ['form' => $form, 'user' => $user]);
            } catch (LastSuperAdminProtected $e) {
                $form->get('role')->addError(new FormError($e->getMessage()));

                return $this->render('admin/users/edit.html.twig', ['form' => $form, 'user' => $user]);
            }

            $this->addFlash('success', \sprintf('%s has been updated.', $user->getDisplayName()));

            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/users/edit.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }
}
