<?php

declare(strict_types=1);

namespace App\Approval\Controller\Family;

use App\Account\Entity\User;
use App\Approval\Dto\SpendingSettingInput;
use App\Approval\Form\SpendingSettingFormType;
use App\Approval\Service\SpendingSettingService;
use App\Profile\Dto\ChildSummary;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Security\ChildActionVoter;
use App\Profile\Security\ProfileVoter;
use App\Profile\Service\FamilyAssociationManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The per-child token-spending setting (FR-092, BR-096, FR-099).
 *
 * Two layers of authorization, as everywhere in the family section, and neither is sufficient
 * alone:
 *
 *  - `ChildActionVoter::MANAGE_PAYMENT_METHODS` at class level asks whether *this account* may
 *    touch payment controls at all. It is the capability TASK-004 declared for exactly this task,
 *    and it is what makes FR-099 a 403 for a child rather than a hidden checkbox — a child login
 *    holds `ROLE_PLAYER` just like their parent, so `access_control` admits them to `/family`.
 *  - `ProfileVoter::EDIT` on the child asks whether *this child* is theirs. Holding the capability
 *    says nothing about which family it applies to.
 *
 * The list route exists as well as the per-child one because BR-096's whole point is that the
 * setting differs between children, and a parent cannot check that a child at a time.
 */
#[IsGranted(ChildActionVoter::MANAGE_PAYMENT_METHODS)]
final class SpendingSettingController extends AbstractController
{
    /**
     * Every child's setting side by side.
     */
    #[Route('/family/spending', name: 'family_spending', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $parent,
        FamilyAssociationManager $family,
        SpendingSettingService $settings,
    ): Response {
        $children = $family->familyOf($parent);

        return $this->render('family/spending_index.html.twig', [
            'children' => $children,
            'settings' => $settings->forFamily(array_map(
                static fn (ChildSummary $child): PlayerProfile => $child->profile,
                $children,
            )),
        ]);
    }

    #[Route('/family/children/{id}/spending', name: 'family_child_spending', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    #[IsGranted(ProfileVoter::EDIT, subject: 'child')]
    public function edit(
        Request $request,
        #[MapEntity(id: 'id')] PlayerProfile $child,
        #[CurrentUser] User $parent,
        SpendingSettingService $settings,
    ): Response {
        $setting = $settings->get($child);

        $form = $this->createForm(
            SpendingSettingFormType::class,
            new SpendingSettingInput($setting->allowsTokenSpendingWithoutApproval()),
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var SpendingSettingInput $input */
            $input = $form->getData();

            $settings->update($child, $parent, $input->allowTokenSpendingWithoutApproval);

            $this->addFlash('success', $input->allowTokenSpendingWithoutApproval
                ? \sprintf('%s can now spend tokens without asking you first.', $child->getDisplayName())
                : \sprintf('%s now needs your approval to spend tokens.', $child->getDisplayName()));

            return $this->redirectToRoute('family_spending');
        }

        return $this->render('family/spending_edit.html.twig', [
            'form' => $form,
            'child' => $child,
            'setting' => $setting,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
