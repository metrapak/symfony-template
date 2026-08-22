<?php

declare(strict_types=1);

namespace App\Availability\Controller;

use App\Account\Entity\User;
use App\Availability\Dto\WeeklyAvailabilityInput;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Form\WeeklyAvailabilityFormType;
use App\Availability\Security\AvailabilityVoter;
use App\Availability\Service\AvailabilityService;
use App\Availability\Service\AvailabilitySubjectResolver;
use App\Availability\Service\AvailabilitySummarizer;
use App\Availability\Service\TimeGrid;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\PlayerProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Best Times" for a player or a parent's child (FR-080, FR-081, US-01.09, spec §10 Flow 6).
 *
 * `/availability` is a redirect to a profile's own page rather than a screen of its own. One
 * canonical URL per grid means the switcher FR-081 asks for is a set of links, a parent can
 * bookmark one child's week, and there is no second code path where "whose week is this?" could
 * be answered differently.
 *
 * Authorization is per profile and object-level. `access_control` puts `^/availability` behind
 * `ROLE_PLAYER`, which every parent and every child login holds, so the role rule stops mattering
 * as soon as the URL carries an id — `AvailabilityVoter` is what makes `/availability/player/7`
 * another family's business. The profile is loaded through the repository rather than by
 * `#[MapEntity]` so nothing is hydrated before the check, and a refused id is a 403 while an
 * absent one is a 404.
 */
final class AvailabilityController extends AbstractController
{
    #[Route('/availability', name: 'availability_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, AvailabilitySubjectResolver $subjects): Response
    {
        $profile = $subjects->defaultProfileFor($user);

        if (!$profile instanceof PlayerProfile) {
            // An account with no player profile at all: a parent who has not added a child yet.
            // Sent where the profile is created rather than shown an empty grid for nobody.
            $this->addFlash('warning', 'Add a player profile first, then you can set the times that suit them.');

            return $this->redirectToRoute('family_index');
        }

        return $this->redirectToRoute('availability_player', ['playerProfileId' => $profile->getId()]);
    }

    #[Route(
        '/availability/player/{playerProfileId}',
        name: 'availability_player',
        methods: ['GET', 'POST'],
        requirements: ['playerProfileId' => Requirement::DIGITS],
    )]
    public function player(
        Request $request,
        int $playerProfileId,
        #[CurrentUser] User $user,
        PlayerProfileRepository $profiles,
        AvailabilitySubjectResolver $subjects,
        AvailabilityService $availability,
        AvailabilitySummarizer $summarizer,
        TimeGrid $grid,
    ): Response {
        $profile = $profiles->findOneById($playerProfileId)
            ?? throw $this->createNotFoundException('No such player profile.');

        $subject = AvailabilitySubject::player($profile);
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

            // FR-080's confirmation, verbatim — it is the sentence that tells a family what the
            // data is *for*, which is the only reason anybody fills the grid in.
            $this->addFlash('success', 'Availability saved. Trainers can see these preferences when planning sessions.');

            return $this->redirectToRoute('availability_player', ['playerProfileId' => $profile->getId()]);
        }

        $schedule = $availability->weekFor($subject);

        return $this->render('availability/player.html.twig', [
            'form' => $form,
            'profile' => $profile,
            // The switcher of FR-081. Built from what this account may edit, not from the family
            // tree, so a child login gets a list of one rather than a list of their siblings.
            'switchable' => $subjects->editableProfilesFor($user),
            'grid' => $grid,
            'days' => DayOfWeek::week(),
            'summary' => $summarizer->summarize($schedule),
            'weekLines' => $summarizer->describeWeek($schedule),
        ]);
    }
}
