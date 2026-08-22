<?php

declare(strict_types=1);

namespace App\Profile\Controller\Family;

use App\Account\Entity\User;
use App\Profile\Dto\CreateChildInput;
use App\Profile\Dto\EditChildInput;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ChildAgeOutOfRange;
use App\Profile\Exception\ImageRejected;
use App\Profile\Exception\TrainerNotJoinable;
use App\Profile\Form\CreateChildFormType;
use App\Profile\Form\EditChildFormType;
use App\Profile\Repository\EmergencyContactRepository;
use App\Profile\Security\ChildActionVoter;
use App\Profile\Security\ProfileVoter;
use App\Profile\Service\ChildProfileCreator;
use App\Profile\Service\FamilyAssociationManager;
use App\Profile\Service\ProfileUpdater;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Family / Player Profiles section (FR-063, FR-064, FR-066).
 *
 * Two layers of authorization, both necessary and neither sufficient:
 *
 *  - `#[IsGranted(ChildActionVoter::MANAGE_CHILDREN)]` asks whether *this account* may manage
 *    children at all. `access_control` cannot: `/family` is `ROLE_PLAYER`, and a child login holds
 *    `ROLE_PLAYER` exactly like their parent, so without this a child could add a sibling. This is
 *    FR-068 enforced as a 403 rather than as a hidden link.
 *  - `#[IsGranted(ProfileVoter::EDIT, subject: 'child')]` asks whether *this child* is theirs.
 *    Every route with an id needs it, because holding the capability says nothing about which
 *    family it applies to.
 *
 * The duplicate-name warning (FR-063) is a two-pass POST rather than a JavaScript prompt: the
 * first submit comes back with the warning and the parent's data intact, and a second submit
 * carrying the acknowledgement goes through. It therefore works with JavaScript off, which
 * matters because the alternative — enforcing it client-side — would make a *server* rule
 * dependent on the client honouring it.
 */
#[IsGranted(ChildActionVoter::MANAGE_CHILDREN)]
final class PlayerProfileController extends AbstractController
{
    /**
     * `/family/players` rather than `/family`, which TASK-001 already gave the player dashboard.
     *
     * The dashboard has to stay where it is because it is where a *child* login lands, and a
     * child is refused this whole controller by the class-level `MANAGE_CHILDREN` check — moving
     * the family page onto `/family` would have sent every child straight into a 403 on sign-in.
     * The path also matches what FR-066 calls the screen: "Family / Player Profiles".
     */
    #[Route('/family/players', name: 'family_index', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $parent,
        FamilyAssociationManager $family,
        ChildProfileCreator $creator,
        EmergencyContactRepository $contacts,
    ): Response {
        // FR-065 / BR-060: the parent is a player too, and the page has to be able to say so
        // even for an account that has never had a profile of its own.
        $self = $creator->parentProfileFor($parent);

        return $this->render('family/index.html.twig', [
            'children' => $family->familyOf($parent),
            'selfProfile' => $self,
            'trainers' => $family->trainersOf($parent),
            'emergencyContacts' => $contacts->findForParent($parent),
        ]);
    }

    #[Route('/family/children/new', name: 'family_child_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $parent,
        ChildProfileCreator $creator,
        FamilyAssociationManager $family,
    ): Response {
        $trainers = $family->trainersOf($parent);
        $form = $this->createForm(CreateChildFormType::class, new CreateChildInput(), ['trainers' => $trainers]);
        $form->handleRequest($request);

        $lookalikes = [];

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CreateChildInput $input */
            $input = $form->getData();

            $lookalikes = $creator->findLookalikes($parent, $input);

            // FR-063: a warning, never a rejection. The parent knows their own family; all this
            // has to guarantee is that they saw the question.
            if ([] !== $lookalikes && !$input->acknowledgedDuplicate) {
                return $this->renderCreateForm($form, $trainers, $lookalikes);
            }

            try {
                $child = $creator->create($parent, $input, $family->trainerOrganizationIdsFor($parent));
            } catch (ImageRejected $e) {
                $form->get('photo')->addError(new FormError($e->getMessage()));

                return $this->renderCreateForm($form, $trainers, []);
            } catch (ChildAgeOutOfRange $e) {
                $form->get('age')->addError(new FormError($e->getMessage()));

                return $this->renderCreateForm($form, $trainers, []);
            } catch (TrainerNotJoinable) {
                // A submitted organization the parent does not train with. The form's Choice
                // constraint normally catches it; reaching here means the page was tampered with
                // or the association ended between rendering and submitting.
                throw $this->createAccessDeniedException('That trainer is not one of yours.');
            }

            $this->addFlash('success', \sprintf('%s has been added to your family.', $child->getDisplayName()));

            return $this->redirectToRoute('family_index');
        }

        return $this->renderCreateForm($form, $trainers, $lookalikes);
    }

    #[Route('/family/children/{id}/edit', name: 'family_child_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    #[IsGranted(ProfileVoter::EDIT, subject: 'child')]
    public function edit(
        Request $request,
        #[MapEntity(id: 'id')] PlayerProfile $child,
        ProfileUpdater $updater,
        ClockInterface $clock,
    ): Response {
        $form = $this->createForm(EditChildFormType::class, EditChildInput::fromProfile($child));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var EditChildInput $input */
            $input = $form->getData();

            try {
                $updater->updateChild($child, $input);
            } catch (ImageRejected $e) {
                $form->get('photo')->addError(new FormError($e->getMessage()));

                return $this->renderEditForm($form, $child, $clock);
            } catch (ChildAgeOutOfRange $e) {
                $form->get('birthDate')->addError(new FormError($e->getMessage()));

                return $this->renderEditForm($form, $child, $clock);
            }

            $this->addFlash('success', \sprintf('%s\'s profile has been saved.', $child->getDisplayName()));

            return $this->redirectToRoute('family_index');
        }

        return $this->renderEditForm($form, $child, $clock);
    }

    /**
     * @param list<\App\Profile\Dto\AssociationRecord> $trainers
     * @param list<PlayerProfile> $lookalikes
     */
    private function renderCreateForm(FormInterface $form, array $trainers, array $lookalikes): Response
    {
        return $this->render('family/child_new.html.twig', [
            'form' => $form,
            'trainers' => $trainers,
            'lookalikes' => $lookalikes,
        ]);
    }

    private function renderEditForm(FormInterface $form, PlayerProfile $child, ClockInterface $clock): Response
    {
        return $this->render('family/child_edit.html.twig', [
            'form' => $form,
            'child' => $child,
            'age' => $child->ageOn($clock->now()),
        ]);
    }
}
