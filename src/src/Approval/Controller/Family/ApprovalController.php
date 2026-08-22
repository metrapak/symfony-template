<?php

declare(strict_types=1);

namespace App\Approval\Controller\Family;

use App\Account\Entity\User;
use App\Approval\Dto\ApprovalDecisionInput;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Exception\ApprovalAlreadyDecided;
use App\Approval\Form\ApprovalDecisionFormType;
use App\Approval\Payment\PaymentFailed;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use App\Approval\Security\ApprovalVoter;
use App\Approval\Service\ApprovalWorkflow;
use App\Approval\ValueObject\Money;
use App\Profile\Security\ChildActionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The parent's review screen and the two decisions it leads to (FR-094, FR-095, NFR-090).
 *
 * **Approve and Deny are separate POST routes**, as FR-094 lists them, and both bind the same
 * `ApprovalDecisionFormType` so the note travels with either. The page renders one form whose
 * Deny button carries `formaction`; see the form type for why that beats two forms or two
 * buttons on one route.
 *
 * **"Request more info" is not here, and its absence is the point** (G-31). US-01.05 lists it as
 * a third action and the spec never says who receives it, on what channel, what state the request
 * lands in, or whether the 48-hour clock keeps running. A button that put purchases into a state
 * with no exit would be worse than no button: those requests would never expire and never resolve.
 * The screen says so in as many words rather than silently dropping a listed feature.
 *
 * Every decision is a POST with a CSRF token (NFR-X03). A GET would let any page on the internet
 * approve a child's purchase with an `<img src>`.
 */
final class ApprovalController extends AbstractController
{
    /**
     * FR-094 — everything waiting on this parent, plus what they have already decided.
     */
    #[Route('/family/approvals', name: 'family_approvals', methods: ['GET'])]
    // The list is a parent's screen with no subject to vote on, so the capability is the whole
    // check: without it a child login — which holds ROLE_PLAYER like their parent — would reach
    // an empty but perfectly rendered approvals page (FR-099). It is on this action rather than
    // the class because `show` deliberately admits the child whose purchase it is (FR-095).
    #[IsGranted(ChildActionVoter::MANAGE_PAYMENT_METHODS)]
    public function index(
        #[CurrentUser] User $parent,
        PurchaseApprovalRequestRepository $requests,
    ): Response {
        $pending = $requests->pendingForParent($parent);

        return $this->render('family/approvals/index.html.twig', [
            'pending' => $pending,
            'decided' => $requests->decidedForParent($parent),
            // Totalled per currency, because dollars and tokens do not add up — see `Money`.
            'totals' => self::totalsOf($pending),
        ]);
    }

    /**
     * FR-094 — one request in full, with the form that decides it.
     */
    #[Route('/family/approvals/{id}', name: 'family_approval_show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    #[IsGranted(ApprovalVoter::VIEW, subject: 'purchase')]
    public function show(
        #[MapEntity(id: 'id')] PurchaseApprovalRequest $purchase,
    ): Response {
        return $this->renderRequest($purchase, $this->decisionForm($purchase));
    }

    #[Route('/family/approvals/{id}/approve', name: 'family_approval_approve', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    #[IsGranted(ApprovalVoter::DECIDE, subject: 'purchase')]
    public function approve(
        Request $httpRequest,
        #[MapEntity(id: 'id')] PurchaseApprovalRequest $purchase,
        #[CurrentUser] User $parent,
        ApprovalWorkflow $workflow,
    ): Response {
        return $this->decide($httpRequest, $purchase, static fn (?string $notes): mixed => $workflow->approve($purchase, $parent, $notes), \sprintf(
            'Approved. %s is confirmed for %s.',
            $purchase->getChildProfile()->getDisplayName(),
            $purchase->getPurchaseDescription(),
        ));
    }

    #[Route('/family/approvals/{id}/deny', name: 'family_approval_deny', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    #[IsGranted(ApprovalVoter::DECIDE, subject: 'purchase')]
    public function deny(
        Request $httpRequest,
        #[MapEntity(id: 'id')] PurchaseApprovalRequest $purchase,
        #[CurrentUser] User $parent,
        ApprovalWorkflow $workflow,
    ): Response {
        return $this->decide($httpRequest, $purchase, static fn (?string $notes): mixed => $workflow->deny($purchase, $parent, $notes), \sprintf(
            'Denied. %s has been told, and nothing was charged.',
            $purchase->getChildProfile()->getDisplayName(),
        ));
    }

    /**
     * The half the two decisions share: bind the note, run the workflow, and say what happened.
     *
     * @param callable(?string):mixed $act
     */
    private function decide(Request $httpRequest, PurchaseApprovalRequest $purchase, callable $act, string $successMessage): Response
    {
        $form = $this->decisionForm($purchase);
        $form->handleRequest($httpRequest);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderRequest($purchase, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var ApprovalDecisionInput $input */
        $input = $form->getData();

        try {
            $act($input->notes);
        } catch (ApprovalAlreadyDecided $e) {
            // NFR-092's second submit. Not an error page: the parent double-clicked, exactly one
            // payment happened, and the honest thing to tell them is which answer stands.
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('family_approval_show', ['id' => $purchase->getId()]);
        } catch (PaymentFailed $e) {
            // The approval was rolled back with the payment, so the request is still pending and
            // the parent can try again. Nothing partial was left behind.
            $this->addFlash('error', \sprintf('The payment could not be taken, so nothing was approved: %s', $e->getMessage()));

            return $this->redirectToRoute('family_approval_show', ['id' => $purchase->getId()]);
        }

        $this->addFlash('success', $successMessage);

        return $this->redirectToRoute('family_approvals');
    }

    private function decisionForm(PurchaseApprovalRequest $purchase): FormInterface
    {
        return $this->createForm(ApprovalDecisionFormType::class, new ApprovalDecisionInput(), [
            'action' => $this->generateUrl('family_approval_approve', ['id' => $purchase->getId()]),
        ]);
    }

    private function renderRequest(PurchaseApprovalRequest $purchase, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('family/approvals/show.html.twig', [
            'purchase' => $purchase,
            'form' => $form,
            // Whether the viewer may act, as opposed to only look: the child reaches this page
            // too (FR-095), and they see the status without the buttons.
            'canDecide' => $this->isGranted(ApprovalVoter::DECIDE, $purchase),
        ], new Response(status: $status));
    }

    /**
     * @param list<PurchaseApprovalRequest> $requests
     *
     * @return list<Money> one total per currency present, so nothing is added that should not be
     */
    private static function totalsOf(array $requests): array
    {
        $totals = [];

        foreach ($requests as $request) {
            $amount = $request->getAmount();
            $totals[$amount->currency] = isset($totals[$amount->currency])
                ? $totals[$amount->currency]->plus($amount)
                : $amount;
        }

        return array_values($totals);
    }
}
