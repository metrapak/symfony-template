<?php

declare(strict_types=1);

namespace App\Approval\Controller\Player;

use App\Account\Entity\User;
use App\Approval\Dto\CheckoutInput;
use App\Approval\Form\CheckoutFormType;
use App\Approval\Payment\PaymentFailed;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use App\Approval\Security\ApprovalVoter;
use App\Approval\Service\ChildCheckout;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\PlayerProfileRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What a player sees of their own purchases, and the checkout that creates them (FR-090, FR-095).
 *
 * **The checkout screen is a stand-in for Epic-02's, and says so on the page.** There are no
 * events, so there is nothing with a price to select; without something that reaches checkout,
 * FR-090's "the system detects a child account at checkout" could not be exercised at all, and
 * the workflow behind it would ship untested end to end. `ChildCheckout` is the seam Epic-02
 * calls, and this screen is a thin caller of it — the same shape TASK-005 used for the coach
 * conflict check before assignments existed.
 *
 * **No amount of money moves.** `FakePaymentProcessor` is the implementation of the payment port
 * until Epic-05 (FR-097, D-04), and it records the intent and succeeds.
 *
 * The list is scoped to the profiles the viewer actually is or manages, resolved server-side.
 * A child sees their own purchases; a parent sees the whole family's, because they are the
 * account that answers for them.
 */
final class ReservationController extends AbstractController
{
    /**
     * FR-090, FR-095 — the child's own status list: Pending, then Confirmed.
     */
    #[Route('/reservations', name: 'player_reservations', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $viewer,
        PlayerProfileRepository $profiles,
        PurchaseApprovalRequestRepository $requests,
    ): Response {
        $visible = self::visibleProfilesFor($viewer, $profiles);

        return $this->render('player/reservations.html.twig', [
            'requests' => $requests->forProfiles($visible),
            'profiles' => $visible,
        ]);
    }

    /**
     * The stand-in checkout. Reachable for one profile at a time, and only your own.
     */
    #[Route('/reservations/checkout/{id}', name: 'player_checkout', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    #[IsGranted(ApprovalVoter::START, subject: 'player')]
    public function checkout(
        Request $request,
        #[MapEntity(id: 'id')] PlayerProfile $player,
        #[CurrentUser] User $actor,
        ChildCheckout $checkout,
    ): Response {
        $form = $this->createForm(CheckoutFormType::class, new CheckoutInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CheckoutInput $input */
            $input = $form->getData();

            try {
                $outcome = $checkout->requestPurchase(
                    $player,
                    $actor,
                    self::standInReference(),
                    $input->requireDescription(),
                    $input->toMoney(),
                    $input->requirePaymentType(),
                );
            } catch (PaymentFailed $e) {
                $this->addFlash('error', \sprintf('The payment could not be taken: %s', $e->getMessage()));

                return $this->redirectToRoute('player_reservations');
            }

            $this->addFlash('success', $outcome->awaitingApproval
                // FR-090's status, in the words the child reads on the reservation.
                ? \sprintf('Sent to %s for approval. You will see this as "Pending parent approval" until they answer.', $outcome->request->getParent()->getDisplayName())
                : \sprintf('Confirmed. %s is registered for %s.', $player->getDisplayName(), $outcome->request->getPurchaseDescription()));

            return $this->redirectToRoute('player_reservations');
        }

        return $this->render('player/checkout.html.twig', [
            'form' => $form,
            'player' => $player,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The profiles this account may see purchases for.
     *
     * Their own, plus — for a parent — every profile they manage. Resolved from the database and
     * never from a submitted id, which is what keeps the list free of the IDOR that a
     * `?profile=` parameter would introduce.
     *
     * @return list<PlayerProfile>
     */
    private static function visibleProfilesFor(User $viewer, PlayerProfileRepository $profiles): array
    {
        $managed = $profiles->findManagedBy($viewer);
        $own = $profiles->findProfileForAccount($viewer);

        if (null === $own) {
            return $managed;
        }

        foreach ($managed as $profile) {
            if ($profile->getId() === $own->getId()) {
                return $managed;
            }
        }

        return [...$managed, $own];
    }

    /**
     * A purchase reference for something that has no id yet.
     *
     * Epic-02 supplies a real one. Until then each checkout invents a unique opaque value, so the
     * column is never empty and two purchases of "the same thing" are still two purchases —
     * which is what the idempotency key derived from the request id depends on.
     */
    private static function standInReference(): string
    {
        return 'stand-in:' . bin2hex(random_bytes(8));
    }
}
