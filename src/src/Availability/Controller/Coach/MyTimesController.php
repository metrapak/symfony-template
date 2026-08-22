<?php

declare(strict_types=1);

namespace App\Availability\Controller\Coach;

use App\Account\Entity\User;
use App\Availability\Dto\WeeklyAvailabilityInput;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Form\WeeklyAvailabilityFormType;
use App\Availability\Repository\CoachAvailabilityOverrideRepository;
use App\Availability\Security\AvailabilityVoter;
use App\Availability\Service\AvailabilityService;
use App\Availability\Service\AvailabilitySummarizer;
use App\Availability\Service\TimeGrid;
use App\Availability\ValueObject\AvailabilitySubject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A coach's "My Times" (FR-082, US-01.10, BR-081).
 *
 * The same grid the family sees, on the coach's own subject. Split shifts — US-01.10's "Monday
 * 4-6pm AND 7-9pm" — need no special handling: two runs of ticked blocks with a gap between them
 * normalize into two ranges, while two adjacent runs become one, which is what a coach means when
 * they tick 4 through 9.
 *
 * The page also lists the overrides trainers have recorded against this coach (FR-087). That is
 * as far as FR-087 can honestly go in this task: "the coach sees the assignment" needs Epic-02's
 * assignments to see, and "can accept or request a change" has no defined workflow, recipient or
 * state anywhere in the spec (G-30). What a coach can have today is the truthful record that
 * somebody scheduled them outside their times and why — which is also the part Q-01.06 asks
 * about, answered without inventing a notification nobody specified.
 */
final class MyTimesController extends AbstractController
{
    #[Route('/coach/my-times', name: 'coach_my_times', methods: ['GET', 'POST'])]
    public function myTimes(
        Request $request,
        #[CurrentUser] User $coach,
        AvailabilityService $availability,
        AvailabilitySummarizer $summarizer,
        CoachAvailabilityOverrideRepository $overrides,
        TimeGrid $grid,
    ): Response {
        $subject = AvailabilitySubject::coach($coach);

        // Redundant against `access_control`'s `^/coach` rule and kept anyway: the rule says
        // "a coach", the voter says "this coach", and the subject here is built from the token
        // rather than from the URL — so this is the check that stays correct if the route ever
        // grows an id.
        $this->denyAccessUnlessGranted(AvailabilityVoter::EDIT, $subject);

        $form = $this->createForm(
            WeeklyAvailabilityFormType::class,
            WeeklyAvailabilityInput::fromSchedule($availability->weekFor($subject), $grid),
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var WeeklyAvailabilityInput $input */
            $input = $form->getData();

            $availability->replaceWeek($subject, $input->toSchedule($grid));

            $this->addFlash('success', 'Your times are saved. Trainers see them when they schedule sessions.');

            return $this->redirectToRoute('coach_my_times');
        }

        $schedule = $availability->weekFor($subject);

        return $this->render('coach/my_times.html.twig', [
            'form' => $form,
            'grid' => $grid,
            'days' => DayOfWeek::week(),
            'summary' => $summarizer->summarize($schedule),
            'weekLines' => $summarizer->describeWeek($schedule),
            'overrides' => $overrides->forCoach($coach),
        ]);
    }
}
