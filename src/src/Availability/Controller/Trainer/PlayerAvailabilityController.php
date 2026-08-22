<?php

declare(strict_types=1);

namespace App\Availability\Controller\Trainer;

use App\Account\Service\TenantContext;
use App\Availability\Dto\AvailabilityFilterInput;
use App\Availability\Dto\PlayerAvailabilityView;
use App\Availability\Dto\RosterPlayer;
use App\Availability\Enum\AvailabilitySubjectType;
use App\Availability\Form\AvailabilityFilterFormType;
use App\Availability\Service\AvailabilityMatcher;
use App\Availability\Service\AvailabilityService;
use App\Availability\Service\AvailabilitySummarizer;
use App\Availability\Service\OrganizationRosterProvider;
use App\Availability\Service\TimeGrid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The trainer's view of player availability, and the filter over it (FR-083, FR-084, BR-082,
 * BR-087).
 *
 * **Read-only, structurally.** BR-082 says a trainer may view availability and never edit it, and
 * this controller has no write path at all — no POST route, and the view is handed
 * `PlayerAvailabilityView` read models rather than profiles it could submit back. A trainer who
 * requests a player's own availability form gets a 403 from `AvailabilityVoter`, which is the
 * other half of the same rule and the one the tests pin down.
 *
 * **Organization-scoped, structurally.** The candidate list comes from
 * `OrganizationRosterProvider` for the trainer's own tenant, and `AvailabilityMatcher` has no
 * method that omits a candidate list — so BR-087 is not a `WHERE` clause somebody has to
 * remember, it is the only way the query can be called. `TenantContext::requireOrganizationId()`
 * is what refuses a trainer with no organization rather than quietly matching across all of them.
 *
 * The counts are advisory (FR-088). Nothing here can prevent a session being scheduled at a time
 * nobody has declared they are free.
 */
final class PlayerAvailabilityController extends AbstractController
{
    #[Route('/trainer/players/availability', name: 'trainer_players_availability', methods: ['GET'])]
    public function index(
        Request $request,
        TenantContext $tenant,
        OrganizationRosterProvider $roster,
        AvailabilityService $availability,
        AvailabilityMatcher $matcher,
        AvailabilitySummarizer $summarizer,
        TimeGrid $grid,
    ): Response {
        $organizationId = $tenant->requireOrganizationId();
        $players = $roster->playersFor($organizationId);
        $playerIds = array_map(static fn (RosterPlayer $player): int => $player->playerProfileId, $players);

        $filter = new AvailabilityFilterInput();
        $form = $this->createForm(AvailabilityFilterFormType::class, $filter);
        $form->handleRequest($request);

        // A GET form is submitted by the presence of its fields, so an unfiltered visit lands
        // here with nothing submitted and the page renders the whole roster.
        $applied = $form->isSubmitted() && $form->isValid() && $filter->isApplied();
        $window = $applied ? $filter->window() : null;

        $availableIds = [];
        $tally = null;

        if ($applied && null !== $window && null !== $filter->day) {
            $availableIds = $matcher->playersAvailableAt($playerIds, $filter->day, $window);
            $tally = $matcher->tallyPlayers($playerIds, $filter->day, $window);
        }

        // One query for every player's week (see `AvailabilitySlotRepository::forSubjects()`),
        // rather than one per card.
        $weeks = $availability->weeksFor(AvailabilitySubjectType::Player, $playerIds);

        $views = array_map(
            static fn (RosterPlayer $player): PlayerAvailabilityView => new PlayerAvailabilityView(
                playerProfileId: $player->playerProfileId,
                playerName: $player->displayName,
                schedule: $weeks[$player->playerProfileId],
                summary: $summarizer->summarize($weeks[$player->playerProfileId]),
                matchesFilter: $applied ? \in_array($player->playerProfileId, $availableIds, true) : null,
            ),
            $players,
        );

        return $this->render('trainer/availability/players.html.twig', [
            'form' => $form,
            'players' => $views,
            'filterApplied' => $applied,
            'filterDay' => $filter->day,
            'filterWindow' => $window,
            'tally' => $tally,
            'grid' => $grid,
        ]);
    }
}
