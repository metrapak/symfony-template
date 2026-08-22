<?php

declare(strict_types=1);

namespace App\Availability\Controller\Trainer;

use App\Account\Entity\User;
use App\Account\Service\TenantContext;
use App\Availability\Dto\CoachConflictInput;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Form\CoachConflictFormType;
use App\Availability\Repository\CoachAvailabilityOverrideRepository;
use App\Availability\Security\AvailabilityVoter;
use App\Availability\Service\AvailabilityService;
use App\Availability\Service\AvailabilitySummarizer;
use App\Availability\Service\CoachAvailabilityChecker;
use App\Availability\Service\ConflictOverrideRecorder;
use App\Availability\Service\OrganizationRosterProvider;
use App\Availability\Service\TimeGrid;
use App\Availability\ValueObject\AvailabilitySubject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Checking a coach against their stated times, and recording an override (FR-085, FR-086,
 * FR-088, BR-084, BR-085).
 *
 * **What this screen is, and what it is not.** Coach assignment belongs to Epic-02, which does
 * not exist: there are no events, so there is nothing to assign a coach *to*. What this task owns
 * is the decision either side of that assignment — the conflict warning, the required reason, and
 * the record — so it ships as a pre-assignment check a trainer runs against a session they are
 * planning. Epic-02's assignment screen will call the same `CoachAvailabilityChecker` and the
 * same `ConflictOverrideRecorder` with an event id, and this page will keep working as the
 * standalone check it is. Nothing here will need rewriting for that; the only column waiting on
 * Epic-02 is `coach_availability_override.event_id`, which is nullable until then.
 *
 * **Availability never blocks (FR-088).** There is no branch in this controller that refuses
 * anything on availability grounds. A conflict produces a warning and a second submit; the second
 * submit records a reason and succeeds. A coach with no declared times produces no warning at
 * all — see `CoachAvailabilityVerdict` on why "not available" and "has not said" must not be the
 * same answer.
 *
 * The verdict is recomputed on the confirming submit rather than trusted from the page. A grid
 * saved by the coach between the warning and the confirmation would otherwise be overridden
 * retroactively — and an override recorded against a conflict that no longer exists is a false
 * record of a trainer's judgement.
 */
final class CoachConflictController extends AbstractController
{
    #[Route(
        '/trainer/coaches/{id}/availability-check',
        name: 'trainer_coach_availability_check',
        methods: ['GET', 'POST'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    public function check(
        Request $request,
        int $id,
        #[CurrentUser] User $trainer,
        TenantContext $tenant,
        OrganizationRosterProvider $roster,
        CoachAvailabilityChecker $checker,
        ConflictOverrideRecorder $recorder,
        AvailabilityService $availability,
        AvailabilitySummarizer $summarizer,
        CoachAvailabilityOverrideRepository $overrides,
        TimeGrid $grid,
    ): Response {
        $organizationId = $tenant->requireOrganizationId();

        // A coach outside the trainer's own organization is a 404, not a 403: the two answers
        // together would tell one academy who coaches for another (BR-087, and the same rule
        // `EmergencyContactController` applies to a family's contacts).
        $rosterCoach = $roster->coachFor($organizationId, $id)
            ?? throw $this->createNotFoundException('No such coach in your organization.');

        $coach = $rosterCoach->coach;

        // Reading a coach's declared times is a view, and the voter is what says a trainer of
        // *this* organization may do it.
        $this->denyAccessUnlessGranted(AvailabilityVoter::VIEW, AvailabilitySubject::coach($coach));

        $form = $this->createForm(CoachConflictFormType::class, new CoachConflictInput());
        $form->handleRequest($request);

        $verdict = null;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CoachConflictInput $input */
            $input = $form->getData();
            $input->confirm = self::confirmClicked($form);

            $day = $input->requireDay();
            $window = $input->requireWindow();

            $verdict = $checker->check($coach, $day, $window);

            if ($input->confirm && $verdict->conflict()) {
                $recorder->record(
                    coach: $coach,
                    trainer: $trainer,
                    organizationId: $organizationId,
                    day: $day,
                    window: $window,
                    reason: (string) $input->reason,
                    // Epic-02 supplies this. Null here is honest: there is no event yet.
                    eventId: $input->eventId,
                );

                $this->addFlash('success', \sprintf(
                    'Override recorded. %s can be scheduled for %s %s, and your reason is on file.',
                    $coach->getDisplayName(),
                    $day->label(),
                    $window->format(),
                ));

                return $this->redirectToRoute('trainer_coach_availability_check', ['id' => $id]);
            }

            if ($input->confirm && !$verdict->conflict()) {
                // The coach's week changed under the trainer's feet, or they confirmed a check
                // that never conflicted. Nothing to override, and saying so is better than
                // filing a record of a conflict that does not exist.
                $this->addFlash('warning', \sprintf(
                    '%s has no conflict at that time any more, so nothing was recorded.',
                    $coach->getDisplayName(),
                ));

                return $this->redirectToRoute('trainer_coach_availability_check', ['id' => $id]);
            }
        }

        $schedule = $availability->weekFor(AvailabilitySubject::coach($coach));

        return $this->render('trainer/availability/coach_check.html.twig', [
            'form' => $form,
            'coach' => $coach,
            'active' => $rosterCoach->active,
            'verdict' => $verdict,
            'summary' => $summarizer->summarize($schedule),
            'weekLines' => $summarizer->describeWeek($schedule),
            'days' => DayOfWeek::week(),
            'grid' => $grid,
            'overrides' => $overrides->forCoachInOrganization($coach, $organizationId),
        ]);
    }

    private static function confirmClicked(FormInterface $form): bool
    {
        $confirm = $form->has('confirm') ? $form->get('confirm') : null;

        return $confirm instanceof ClickableInterface && $confirm->isClicked();
    }
}
