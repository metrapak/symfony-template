<?php

declare(strict_types=1);

namespace App\Membership\Controller;

use App\Account\Entity\User;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Service\RoleDashboardResolver;
use App\Membership\Dto\AssociationSummary;
use App\Membership\Dto\CoachRegistrationInput;
use App\Membership\Dto\FamilySelectionInput;
use App\Membership\Dto\PlayerRegistrationInput;
use App\Membership\Dto\RedemptionPlan;
use App\Membership\Dto\ShareLinkResolution;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\RedemptionAction;
use App\Membership\Enum\ShareLinkState;
use App\Membership\Exception\CoachAlreadyAssignedElsewhere;
use App\Membership\Exception\InvalidFamilySelection;
use App\Membership\Exception\ShareLinkNotUsable;
use App\Membership\Form\CoachRegistrationFormType;
use App\Membership\Form\FamilySelectionFormType;
use App\Membership\Form\PlayerRegistrationFormType;
use App\Membership\Service\AssociationService;
use App\Membership\Service\ChildJoinRequestNotifier;
use App\Membership\Service\CoachInvitationService;
use App\Membership\Service\PlayerRegistrationService;
use App\Membership\Service\RedemptionPlanner;
use App\Membership\Service\ShareLinkResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The public redemption flow (FR-042 … FR-045, FR-048, FR-049).
 *
 * This is the application's largest external attack surface: unauthenticated, reachable by
 * anyone with a URL, and it creates accounts. Four things follow from that, and all four are
 * in this class rather than spread across the templates:
 *
 *  - **Every entry point is rate limited**, by IP. Landing pages and registration submissions
 *    have separate budgets, because a person retrying a rejected form is not the same traffic
 *    as a script walking the code space.
 *  - **Failure is uniform.** Unknown, deactivated and consumed codes render one page with one
 *    status. The only distinguishable failure is an expired coach invitation, which FR-046
 *    requires so the holder can ask for a new one.
 *  - **Nothing is decided here.** `RedemptionPlanner` decides what the visitor may do and the
 *    services enforce it again when they are called, so a page rendered before a link was
 *    withdrawn cannot authorize the submit that follows it.
 *  - **State changes are POSTs with CSRF tokens**, except the one FR-048 puts on a GET: a
 *    child opening a link is refused and their parent is notified, which is the click itself.
 */
final class JoinController extends AbstractController
{
    private const TOO_MANY_REQUESTS_MESSAGE = 'Too many requests from this connection. Please wait a few minutes and try again.';

    #[Route('/join/{code}', name: 'membership_join', methods: ['GET'])]
    public function landing(
        Request $request,
        string $code,
        #[CurrentUser] ?User $user,
        ShareLinkResolver $resolver,
        RedemptionPlanner $planner,
        ChildJoinRequestNotifier $childNotifier,
        RateLimiterFactoryInterface $joinRedemptionLimiter,
    ): Response {
        if (!$joinRedemptionLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->renderThrottled();
        }

        $resolution = $resolver->resolve($code);

        if (!$resolution->isValid()) {
            return $this->renderUnusable($resolution);
        }

        $link = $resolution->requireLink();
        $plan = $planner->planFor($link, $user);

        if ($plan->is(RedemptionAction::BlockChild)) {
            $child = $plan->childProfile ?? throw new \LogicException('A blocked-child plan must name the child.');

            return $this->render('join/blocked_child.html.twig', [
                'organizationName' => $link->getOrganization()->getName(),
                'childName' => $child->getDisplayName(),
                'parentEmail' => $child->getOwner()->getEmail(),
                'notified' => $childNotifier->refuseAndNotifyParent($link, $child),
            ]);
        }

        if ($plan->is(RedemptionAction::NotEligible)) {
            return $this->renderNotEligible($link, (string) $plan->reason);
        }

        return $this->render('join/landing.html.twig', [
            'link' => $link,
            'organizationName' => $link->getOrganization()->getName(),
            'plan' => $plan,
            'action' => $plan->action->value,
            // Only rendered on the family branch; a single-profile account gets a plain
            // confirmation button instead of a checklist with one box in it.
            'familyForm' => $this->needsFamilyChecklist($plan)
                ? $this->createFamilyForm($code, $plan)->createView()
                : null,
        ]);
    }

    /**
     * Registration for a visitor with no account (FR-042, FR-045).
     */
    #[Route('/join/{code}/register', name: 'membership_join_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        string $code,
        #[CurrentUser] ?User $user,
        ShareLinkResolver $resolver,
        RedemptionPlanner $planner,
        PlayerRegistrationService $playerRegistration,
        CoachInvitationService $coachInvitations,
        Security $security,
        RoleDashboardResolver $dashboards,
        RateLimiterFactoryInterface $joinRegistrationLimiter,
    ): Response {
        if (!$joinRegistrationLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->renderThrottled();
        }

        $resolution = $resolver->resolve($code);

        if (!$resolution->isValid()) {
            return $this->renderUnusable($resolution);
        }

        $link = $resolution->requireLink();
        $plan = $planner->planFor($link, $user);

        // Signed in already: registration is not the branch they need, and the landing page
        // knows which one is.
        if (!$plan->is(RedemptionAction::RegisterPlayer) && !$plan->is(RedemptionAction::RegisterCoach)) {
            return $this->redirectToRoute('membership_join', ['code' => $code]);
        }

        return $plan->is(RedemptionAction::RegisterCoach)
            ? $this->registerCoach($request, $link, $coachInvitations, $security, $dashboards)
            : $this->registerPlayer($request, $link, $playerRegistration, $security, $dashboards);
    }

    /**
     * Attaches an account that already exists: a player's family (FR-043, FR-044) or a coach
     * accepting their invitation (FR-045).
     */
    #[Route('/join/{code}/associate', name: 'membership_join_associate', methods: ['POST'])]
    public function associate(
        Request $request,
        string $code,
        #[CurrentUser] User $user,
        ShareLinkResolver $resolver,
        RedemptionPlanner $planner,
        AssociationService $associations,
        CoachInvitationService $coachInvitations,
        RoleDashboardResolver $dashboards,
    ): Response {
        $resolution = $resolver->resolve($code);

        if (!$resolution->isValid()) {
            return $this->renderUnusable($resolution);
        }

        $link = $resolution->requireLink();
        $plan = $planner->planFor($link, $user);
        $organizationName = $link->getOrganization()->getName();

        if ($plan->is(RedemptionAction::AcceptCoachInvitation)) {
            $this->assertCsrf($request);

            try {
                $coachInvitations->accept($link, $user);
            } catch (CoachAlreadyAssignedElsewhere $e) {
                return $this->renderNotEligible($link, $e->getMessage());
            } catch (ShareLinkNotUsable) {
                return $this->renderUnusable(ShareLinkResolution::unusable());
            }

            $this->addFlash('success', \sprintf('You are now coaching for %s.', $organizationName));

            return $this->redirectToRoute($dashboards->resolveRouteName($user->getRole()));
        }

        if (!$plan->is(RedemptionAction::AssociatePlayer)) {
            throw $this->createAccessDeniedException('This link cannot be redeemed by this account.');
        }

        try {
            $summary = $this->needsFamilyChecklist($plan)
                ? $this->associateSelectedFamily($request, $code, $plan, $link, $user, $associations)
                : $this->associateOnlyProfile($request, $plan, $link, $user, $associations);
        } catch (InvalidFamilySelection $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('membership_join', ['code' => $code]);
        } catch (ShareLinkNotUsable) {
            return $this->renderUnusable(ShareLinkResolution::unusable());
        }

        if (null === $summary) {
            // The checklist came back invalid (nothing selected). Re-render the landing page,
            // which is where the checklist lives.
            return $this->redirectToRoute('membership_join', ['code' => $code]);
        }

        // FR-043: a repeat redemption is a success that changed nothing, not an error.
        $this->addFlash('success', $summary->changedAnything()
            ? \sprintf('%s now train with %s.', implode(', ', $summary->associatedNames()), $organizationName)
            : \sprintf('You already train with %s.', $organizationName));

        return $this->redirectToRoute($dashboards->resolveRouteName($user->getRole()));
    }

    private function registerPlayer(
        Request $request,
        ShareLink $link,
        PlayerRegistrationService $registration,
        Security $security,
        RoleDashboardResolver $dashboards,
    ): Response {
        $form = $this->createForm(PlayerRegistrationFormType::class, new PlayerRegistrationInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlayerRegistrationInput $input */
            $input = $form->getData();

            try {
                $result = $registration->registerViaShareLink($link, $input);
            } catch (EmailAlreadyRegistered $e) {
                // On the field that caused it: this address most likely belongs to the person
                // filling the form, and the fix is to sign in rather than to try another one.
                $form->get('email')->addError(new FormError($e->getMessage() . ' Sign in and open the link again to add this trainer.'));

                return $this->renderRegistration($link, $form, 'join/register_player.html.twig');
            } catch (ShareLinkNotUsable) {
                return $this->renderUnusable(ShareLinkResolution::unusable());
            }

            if (!$result->confirmationSent) {
                $this->addFlash('warning', 'Your account was created, but we could not send your confirmation email. Use "Forgot password" or request a new confirmation link if you need one.');
            }

            return $this->finishRegistration(
                $security,
                $dashboards,
                $result->user,
                $result->verificationRequired,
                \sprintf('Welcome. You now train with %s.', $link->getOrganization()->getName()),
            );
        }

        return $this->renderRegistration($link, $form, 'join/register_player.html.twig');
    }

    private function registerCoach(
        Request $request,
        ShareLink $link,
        CoachInvitationService $invitations,
        Security $security,
        RoleDashboardResolver $dashboards,
    ): Response {
        $input = new CoachRegistrationInput();
        $input->email = $link->getTargetEmail();
        $input->name = $link->getTargetName();

        $form = $this->createForm(CoachRegistrationFormType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CoachRegistrationInput $submitted */
            $submitted = $form->getData();

            try {
                $result = $invitations->registerAndAccept($link, $submitted);
            } catch (EmailAlreadyRegistered $e) {
                $form->get('email')->addError(new FormError($e->getMessage() . ' Sign in and open the invitation again.'));

                return $this->renderRegistration($link, $form, 'join/register_coach.html.twig');
            } catch (CoachAlreadyAssignedElsewhere $e) {
                $form->get('email')->addError(new FormError($e->getMessage()));

                return $this->renderRegistration($link, $form, 'join/register_coach.html.twig');
            } catch (ShareLinkNotUsable) {
                return $this->renderUnusable(ShareLinkResolution::unusable());
            }

            return $this->finishRegistration(
                $security,
                $dashboards,
                $result->user,
                $result->verificationRequired,
                \sprintf('Welcome. You are now coaching for %s.', $link->getOrganization()->getName()),
            );
        }

        return $this->renderRegistration($link, $form, 'join/register_coach.html.twig');
    }

    /**
     * Signs the new account in when the configuration allows it.
     *
     * While `EMAIL_VERIFICATION_REQUIRED` is on, the firewall's user checker refuses players
     * and coaches until they confirm their address (Q-01.05), so attempting the login and
     * catching the refusal is the honest way to cover both settings: with the gate off the
     * visitor lands on their dashboard, with it on they are told to check their inbox instead
     * of being bounced off an unexplained sign-in failure.
     */
    private function finishRegistration(
        Security $security,
        RoleDashboardResolver $dashboards,
        User $user,
        bool $verificationRequired,
        string $welcomeMessage,
    ): Response {
        if (!$verificationRequired) {
            try {
                $security->login($user, 'form_login', 'main');

                $this->addFlash('success', $welcomeMessage);

                return $this->redirectToRoute($dashboards->resolveRouteName($user->getRole()));
            } catch (AuthenticationException) {
                // Fall through to the verification notice: the account exists either way.
            }
        }

        $this->addFlash('success', 'Your account is ready. Confirm your email address using the link we just sent, then sign in.');

        return $this->redirectToRoute('app_login');
    }

    /**
     * @throws InvalidFamilySelection
     * @throws ShareLinkNotUsable
     *
     * @return AssociationSummary|null null when the submitted checklist was invalid
     */
    private function associateSelectedFamily(
        Request $request,
        string $code,
        RedemptionPlan $plan,
        ShareLink $link,
        User $user,
        AssociationService $associations,
    ): ?AssociationSummary {
        $form = $this->createFamilyForm($code, $plan);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Select at least one family member.');

            return null;
        }

        /** @var FamilySelectionInput $input */
        $input = $form->getData();

        return $associations->associateFamilyMembers($link, $user, $input->profileIds);
    }

    /**
     * @throws ShareLinkNotUsable
     */
    private function associateOnlyProfile(
        Request $request,
        RedemptionPlan $plan,
        ShareLink $link,
        User $user,
        AssociationService $associations,
    ): AssociationSummary {
        $this->assertCsrf($request);

        $profile = $plan->profiles[0] ?? throw $this->createAccessDeniedException('This account has no player profile to associate.');

        return $associations->associate($link, $user, $profile);
    }

    private function needsFamilyChecklist(RedemptionPlan $plan): bool
    {
        return $plan->is(RedemptionAction::AssociatePlayer) && \count($plan->profiles) > 1;
    }

    private function createFamilyForm(string $code, RedemptionPlan $plan): FormInterface
    {
        return $this->createForm(FamilySelectionFormType::class, new FamilySelectionInput(), [
            'profiles' => $plan->profiles,
            'action' => $this->generateUrl('membership_join_associate', ['code' => $code]),
        ]);
    }

    private function renderRegistration(ShareLink $link, FormInterface $form, string $template): Response
    {
        return $this->render($template, [
            'form' => $form,
            'link' => $link,
            'organizationName' => $link->getOrganization()->getName(),
        ]);
    }

    /**
     * One page and one status code for every "no" except an expired invitation (FR-049).
     */
    private function renderUnusable(ShareLinkResolution $resolution): Response
    {
        if (ShareLinkState::Expired === $resolution->state) {
            $link = $resolution->requireLink();

            return $this->render('join/expired.html.twig', [
                'organizationName' => $link->getOrganization()->getName(),
                'targetEmail' => $link->getTargetEmail(),
            ], new Response('', Response::HTTP_GONE));
        }

        return $this->render(
            'join/unavailable.html.twig',
            [],
            new Response('', Response::HTTP_NOT_FOUND),
        );
    }

    private function renderNotEligible(ShareLink $link, string $reason): Response
    {
        return $this->render('join/not_eligible.html.twig', [
            'organizationName' => $link->getOrganization()->getName(),
            'reason' => $reason,
        ]);
    }

    private function renderThrottled(): Response
    {
        return $this->render(
            'join/throttled.html.twig',
            ['message' => self::TOO_MANY_REQUESTS_MESSAGE],
            new Response('', Response::HTTP_TOO_MANY_REQUESTS),
        );
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
