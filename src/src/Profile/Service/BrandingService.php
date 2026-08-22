<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Profile\Dto\BrandingInput;
use App\Profile\Dto\ResolvedBranding;
use App\Profile\Entity\OrganizationBranding;
use App\Profile\Exception\ImageRejected;
use App\Profile\Repository\OrganizationBrandingRepository;
use App\Profile\ValueObject\TrainingContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reading and writing a trainer's portal branding (FR-071, FR-072, BR-069, G-26).
 *
 * **G-26 is resolved here: branding follows the active training context.** The spec says a
 * trainer's branding is visible to "the trainer's players, coaches, and parents" and never says
 * what happens to a player who trains with two branded trainers — which is the ordinary case
 * the whole of FR-070 exists for. Resolving per-user would have to pick one of the two
 * arbitrarily; resolving per-context means the page a parent is looking at carries the branding
 * of the trainer whose data it is showing, which is the only answer consistent with "no combined
 * view anywhere in the platform".
 *
 * That makes branding resolution a *read of the current context*, not a property of the session
 * user, and it is why `resolveForViewer()` takes the context resolver rather than caching per
 * user. FR-072's "changes visible immediately to all users in the trainer's organization" falls
 * out of the same choice: there is nothing cached to invalidate.
 *
 * The colour is never interpolated into markup here. It leaves as a `HexColor` — validated,
 * normalized, six hex digits — and the layout binds it to a CSS custom property. A trainer's
 * colour is user input that ends up inside a `<style>` context, which is the one place where
 * Twig's HTML escaping is not the right escaping; constraining the value to a shape that cannot
 * carry a payload is.
 */
final class BrandingService
{
    /**
     * Branding rows created during this request, by organization id.
     *
     * `forOrganization()` creates a row lazily and persists it without flushing, and it is
     * called more than once in a single request — the controller reads the current branding to
     * render the form, then `update()` reads it again to write to it. Without this map the
     * second call re-queries, still finds nothing (the first entity is persisted but unflushed,
     * so no SELECT can see it), and creates a *second* entity for the same organization. Both
     * are then inserted by the same flush and the unique index on `organization_id` rejects the
     * pair — turning the first branding save a trainer ever makes into a 500.
     *
     * Memoizing is what makes the lazy creation idempotent within a request. The service is not
     * shared between requests, so the map cannot outlive the entity manager it refers into.
     *
     * @var array<int, OrganizationBranding>
     */
    private array $created = [];

    public function __construct(
        private readonly OrganizationBrandingRepository $brandings,
        private readonly TrainingContextResolver $contexts,
        private readonly OrganizationMembershipResolver $memberships,
        private readonly ImageUploader $uploader,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Applies a trainer's branding change (FR-071, FR-072).
     *
     * @throws ImageRejected when a submitted logo is refused
     */
    public function update(Organization $organization, BrandingInput $input): OrganizationBranding
    {
        // Outside the transaction, for the same reason as a profile photo: a filesystem write is
        // not rollback-able, and a rejected image must abort the save rather than leave a file.
        $logo = null !== $input->logo ? $this->uploader->storeLogo($input->logo) : null;

        $branding = $this->forOrganization($organization);
        $replaced = null;

        $this->entityManager->wrapInTransaction(function () use ($branding, $input, $logo, &$replaced): void {
            $now = $this->clock->now();

            if (null !== $logo || $input->removeLogo) {
                $replaced = $branding->getLogoPath();
                $branding->setLogoPath($logo?->path, $now);
            }

            // A null colour is a reset to the platform default, not "leave it alone" — the form
            // sends an empty field when a trainer clears it, and FR-072 asks for that to be
            // possible. `BrandingInput::fromBranding()` pre-fills the current *chosen* colour so
            // an untouched form round-trips to the same value.
            $branding->setPrimaryColor($input->resolvedColor(), $now);

            $this->entityManager->flush();
        });

        $this->uploader->delete($replaced);

        return $branding;
    }

    /**
     * FR-072's reset-to-default.
     *
     * Clears the colour and keeps the logo: they are two separate controls in the requirement,
     * and a trainer resetting a colour they dislike would not expect their logo to vanish.
     */
    public function resetColor(Organization $organization): OrganizationBranding
    {
        $branding = $this->forOrganization($organization);

        $this->entityManager->wrapInTransaction(function () use ($branding): void {
            $branding->setPrimaryColor(null, $this->clock->now());
            $this->entityManager->flush();
        });

        return $branding;
    }

    /**
     * The branding row for an organization, created on first use.
     *
     * Created lazily rather than at organization creation: a trainer who never opens the
     * branding screen has no row, and "no row" and "reset to default" then mean the same thing,
     * which is what they are.
     */
    public function forOrganization(Organization $organization): OrganizationBranding
    {
        $organizationId = (int) $organization->getId();
        $existing = $this->brandings->findOneForOrganization($organizationId);

        if (null !== $existing) {
            return $existing;
        }

        // A row this request already created but has not flushed yet — invisible to the query
        // above, and creating a second one would violate the unique index on flush.
        if (isset($this->created[$organizationId])) {
            return $this->created[$organizationId];
        }

        $branding = new OrganizationBranding($organization, $this->clock->now());
        $this->brandings->add($branding);

        return $this->created[$organizationId] = $branding;
    }

    public function resolveForOrganization(int $organizationId, string $organizationName): ResolvedBranding
    {
        $branding = $this->brandings->findOneForOrganization($organizationId);
        $primary = $branding?->resolvePrimaryColor() ?? OrganizationBranding::defaultPrimaryColor();

        return new ResolvedBranding(
            organizationId: $organizationId,
            organizationName: $organizationName,
            logoPath: $branding?->getLogoPath(),
            primaryColor: $primary,
            foreground: $primary->accessibleForeground(),
        );
    }

    /**
     * The branding the page in front of this user should wear (G-26, BR-069).
     *
     * Three cases, in this order:
     *
     *  - A **player or parent** wears the branding of their *selected context's* trainer, which
     *    is the G-26 resolution above. A player with no context at all — a fresh account with no
     *    association — gets the platform default rather than nothing.
     *  - A **trainer or coach** wears their own organization's, because they have exactly one
     *    and no context to select.
     *  - A **Super Admin** wears the platform default. They belong to no tenant (D3), and
     *    dressing administrative screens in one trainer's colours would misrepresent whose data
     *    is on the page.
     */
    public function resolveForViewer(?User $user): ResolvedBranding
    {
        if (null === $user) {
            return ResolvedBranding::platformDefault();
        }

        $current = $this->contexts->current($user);

        if (null !== $current) {
            return $this->resolveForOrganization(
                $current->context->organizationId,
                $current->organizationName,
            );
        }

        $organizationIds = $this->memberships->organizationIdsFor($user);
        $organizationId = $organizationIds[0] ?? null;

        if (null === $organizationId) {
            return ResolvedBranding::platformDefault();
        }

        $branding = $this->brandings->findOneForOrganization($organizationId);
        $name = $branding?->getOrganization()->getName();

        if (null === $name) {
            // No branding row yet, so the organization's name has to come from somewhere. A
            // reference is enough: the layout only reads the name, and this avoids a second
            // query on every page for a tenant that has never opened the branding screen.
            $name = $this->entityManager->getReference(Organization::class, $organizationId)->getName();
        }

        return $this->resolveForOrganization($organizationId, $name);
    }

    /**
     * The branding for one explicit context, for a page that has already authorized it.
     */
    public function resolveForContext(User $user, TrainingContext $context): ResolvedBranding
    {
        $option = $this->contexts->assertAccess($user, $context);

        return $this->resolveForOrganization($option->context->organizationId, $option->organizationName);
    }
}
