<?php

declare(strict_types=1);

namespace App\Profile\Controller;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Service\TenantContext;
use App\Profile\Dto\UpdateProfileInput;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ImageRejected;
use App\Profile\Form\UpdateProfileFormType;
use App\Profile\Repository\AdminPreferencesRepository;
use App\Profile\Repository\CoachProfileRepository;
use App\Profile\Repository\PlayerProfileRepository;
use App\Profile\Repository\TrainerProfileRepository;
use App\Profile\Service\ProfileUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Self-service profile editing for every role (FR-060, FR-061).
 *
 * `/account/*` is already `ROLE_USER`, and that is the whole of the authorization this needs:
 * there is no id in the URL and no way to name another account. The subject is always
 * `#[CurrentUser]`, so the classic IDOR this epic guards against elsewhere cannot arise — a user
 * can only edit themselves because there is no parameter with which to ask for anyone else.
 *
 * The role decides which form is built (`UpdateProfileFormType`) and which rows are written
 * (`ProfileUpdater`), and the read-only fields FR-060 lists are rendered as text because they
 * exist nowhere in the form or the DTO.
 */
final class ProfileController extends AbstractController
{
    #[Route('/account/profile', name: 'account_profile', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[CurrentUser] User $user,
        ProfileUpdater $updater,
        PlayerProfileRepository $playerProfiles,
        CoachProfileRepository $coachProfiles,
        TrainerProfileRepository $trainerProfiles,
        AdminPreferencesRepository $adminPreferences,
        TenantContext $tenant,
    ): Response {
        $playerProfile = UserRole::Player === $user->getRole()
            ? $playerProfiles->findSelfProfileFor($user)
            : null;

        $organizationId = \in_array($user->getRole(), [UserRole::Coach, UserRole::Trainer], true)
            ? $tenant->currentOrganizationId()
            : null;

        $coachProfile = UserRole::Coach === $user->getRole() && null !== $organizationId
            ? $coachProfiles->findOneFor($user, $organizationId)
            : null;

        $trainerProfile = UserRole::Trainer === $user->getRole() && null !== $organizationId
            ? $trainerProfiles->findOneForOrganization($organizationId)
            : null;

        $preferences = UserRole::SuperAdmin === $user->getRole()
            ? $adminPreferences->findOneForUser($user)
            : null;

        $input = UpdateProfileInput::forUser(
            $user,
            $playerProfile,
            $coachProfile,
            $trainerProfile,
            $preferences?->notifiesOnTrainerCreated() ?? true,
            $preferences?->notifiesOnAccountErasure() ?? true,
        );

        $form = $this->createForm(UpdateProfileFormType::class, $input, ['role' => $user->getRole()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UpdateProfileInput $submitted */
            $submitted = $form->getData();

            try {
                $updater->updateFor($user, $submitted);
            } catch (ImageRejected $e) {
                // On the photo field, because that is where the fix is. The rest of the form is
                // re-rendered with what the user typed rather than discarded.
                $form->get('photo')->addError(new FormError($e->getMessage()));

                return $this->renderForm($user, $form, $playerProfile);
            }

            $this->addFlash('success', 'Your profile has been saved.');

            return $this->redirectToRoute('account_profile');
        }

        return $this->renderForm($user, $form, $playerProfile);
    }

    private function renderForm(User $user, FormInterface $form, ?PlayerProfile $playerProfile): Response
    {
        return $this->render('profile/edit.html.twig', [
            'form' => $form,
            'user' => $user,
            // FR-060's read-only block. Passed explicitly so the template does not have to know
            // which of the two places a photo can live in for this role.
            'playerProfile' => $playerProfile,
            'photoOwner' => UserRole::Player === $user->getRole() ? 'profile' : 'account',
        ]);
    }
}
