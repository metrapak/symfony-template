<?php

declare(strict_types=1);

namespace App\Profile\Controller\Family;

use App\Account\Entity\User;
use App\Profile\Dto\AddTrainerInput;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ProfileNotManaged;
use App\Profile\Exception\TrainerNotJoinable;
use App\Profile\Form\AddTrainerFormType;
use App\Profile\Security\ChildActionVoter;
use App\Profile\Security\ProfileVoter;
use App\Profile\Service\FamilyAssociationManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Adding and removing one family member's trainers (FR-066, BR-062, BR-065, BR-066).
 *
 * Three authorization layers, and every one of them is load-bearing:
 *
 *  - `access_control` puts `/family/*` behind `ROLE_PLAYER`.
 *  - `MANAGE_ASSOCIATIONS` is FR-068's "cannot change trainer associations", and it is the only
 *    thing standing between a child login and this controller — a child holds `ROLE_PLAYER`
 *    exactly like their parent, so the role check above cannot tell them apart. BR-065 as a 403,
 *    on the endpoint, not as a button the template declines to draw.
 *  - `ProfileVoter::EDIT` on the subject is the object-level rule: holding the capability says
 *    nothing about *whose* child this is, and without it `/family/children/7/trainers` would let
 *    any parent enrol another family's child with their own trainer.
 *
 * The removal is a two-step GET-then-POST rather than a single link, because FR-066 specifies a
 * confirmation carrying a consequence ("This will cancel all upcoming RSVPs") and that sentence
 * has to be read before the action happens. The confirm page is a real page for the same reason
 * the duplicate-name warning is a real round trip: a dialog that only exists in JavaScript is a
 * warning the server cannot guarantee anybody saw.
 *
 * `POST` for the removal rather than `DELETE`, which the task breakdown suggested. An HTML form
 * cannot issue `DELETE` without JavaScript, and this flow has to work without it; a method
 * override would put the real verb in a field the client controls, which buys nothing here.
 */
#[IsGranted(ChildActionVoter::MANAGE_ASSOCIATIONS)]
final class AssociationController extends AbstractController
{
    #[Route(
        '/family/children/{id}/trainers',
        name: 'family_trainer_add',
        methods: ['GET', 'POST'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::EDIT, subject: 'player')]
    public function add(
        Request $request,
        #[CurrentUser] User $parent,
        #[MapEntity(id: 'id')] PlayerProfile $player,
        FamilyAssociationManager $family,
    ): Response {
        // The trainers this *player* is not already with. Rendering the parent's full list would
        // offer an association that already exists, and the add would silently do nothing.
        $addable = $family->addableTrainersFor($parent, $player);

        $form = $this->createForm(AddTrainerFormType::class, new AddTrainerInput(), ['trainers' => $addable]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var AddTrainerInput $input */
            $input = $form->getData();

            try {
                if ($input->usesShareLink()) {
                    $organizationId = $family->addTrainerByShareLink($parent, $player, (string) $input->shareLinkCode);
                    $trainerName = $family->trainerNameFor($organizationId);
                } else {
                    $organizationId = (int) $input->organizationId;
                    $family->addKnownTrainer($parent, $player, $organizationId);
                    $trainerName = $family->trainerNameFor($organizationId);
                }
            } catch (TrainerNotJoinable $e) {
                // FR-049's rule, inherited: an unusable code and an unknown one get the same
                // answer, so pasting codes here cannot be used to discover which ones exist.
                // A tampered `organizationId` lands here too and is told the same thing, which
                // is honest — from the parent's side both are "that trainer is not available".
                $form->get($input->usesShareLink() ? 'shareLinkCode' : 'organizationId')
                    ->addError(new FormError($e->getMessage()));

                return $this->renderAddForm($form, $player, $addable);
            } catch (ProfileNotManaged) {
                // The voter above already refused another family's child; reaching here means
                // ownership changed between the check and the write.
                throw $this->createAccessDeniedException('That profile is not yours to manage.');
            }

            $this->addFlash('success', \sprintf(
                '%s now trains with %s.',
                $player->getDisplayName(),
                $trainerName ?? 'their new trainer',
            ));

            return $this->redirectToRoute('family_index');
        }

        return $this->renderAddForm($form, $player, $addable);
    }

    /**
     * FR-066's removal confirmation. A GET that changes nothing and states what the POST will do.
     */
    #[Route(
        '/family/children/{id}/trainers/{organizationId}/remove',
        name: 'family_trainer_remove_confirm',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS, 'organizationId' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::EDIT, subject: 'player')]
    public function confirmRemoval(
        #[MapEntity(id: 'id')] PlayerProfile $player,
        int $organizationId,
        FamilyAssociationManager $family,
    ): Response {
        // Refused here as well as on the POST, so the confirmation page cannot be used to probe
        // which associations a profile has: an organization this player does not train with is a
        // 404 whether or not it exists.
        if (!$family->hasActiveTrainer($player, $organizationId)) {
            throw $this->createNotFoundException('That trainer is not one of this player\'s.');
        }

        return $this->render('family/trainer_remove.html.twig', [
            'player' => $player,
            'organizationId' => $organizationId,
            'trainerName' => $family->trainerNameFor($organizationId) ?? 'this trainer',
        ]);
    }

    #[Route(
        '/family/children/{id}/trainers/{organizationId}/remove',
        name: 'family_trainer_remove',
        methods: ['POST'],
        requirements: ['id' => Requirement::DIGITS, 'organizationId' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::EDIT, subject: 'player')]
    public function remove(
        Request $request,
        #[CurrentUser] User $parent,
        #[MapEntity(id: 'id')] PlayerProfile $player,
        int $organizationId,
        FamilyAssociationManager $family,
    ): Response {
        $this->assertCsrf($request);

        $trainerName = $family->trainerNameFor($organizationId) ?? 'that trainer';

        try {
            $cancelled = $family->removeTrainer($parent, $player, $organizationId);
        } catch (TrainerNotJoinable) {
            throw $this->createNotFoundException('That trainer is not one of this player\'s.');
        } catch (ProfileNotManaged) {
            throw $this->createAccessDeniedException('That profile is not yours to manage.');
        }

        // The reservation count is reported because FR-066 promised it would happen. It is zero
        // until Epic-02 ships reservations, and saying "and 0 reservations were cancelled" would
        // be a worse message than not mentioning them.
        $this->addFlash('success', 0 === $cancelled
            ? \sprintf('%s no longer trains with %s. Their history with them is kept.', $player->getDisplayName(), $trainerName)
            : \sprintf(
                '%s no longer trains with %s. %d upcoming reservation%s cancelled, and their history is kept.',
                $player->getDisplayName(),
                $trainerName,
                $cancelled,
                1 === $cancelled ? ' was' : 's were',
            ));

        return $this->redirectToRoute('family_index');
    }

    /**
     * @param list<\App\Profile\Dto\AssociationRecord> $addable
     */
    private function renderAddForm(FormInterface $form, PlayerProfile $player, array $addable): Response
    {
        return $this->render('family/trainer_add.html.twig', [
            'form' => $form,
            'player' => $player,
            'addable' => $addable,
        ]);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
