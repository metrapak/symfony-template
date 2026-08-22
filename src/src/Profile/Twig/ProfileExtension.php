<?php

declare(strict_types=1);

namespace App\Profile\Twig;

use App\Account\Entity\User;
use App\Profile\Dto\ResolvedBranding;
use App\Profile\Dto\TrainingContextOption;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Service\BrandingService;
use App\Profile\Service\TrainingContextResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the layout needs to know about the viewer: their branding, and their contexts.
 *
 * Functions rather than Twig globals, because both are resolved from the current request and a
 * global is evaluated eagerly — every page in the application, including the login form and the
 * public redemption flow, would run the association query whether or not it renders a switcher.
 *
 * `photo_url` and its siblings return a *route*, never a stored path. The paths in the database
 * point inside `var/uploads/`, which nginx does not serve (NFR-066); a template that built a URL
 * from one would produce a 404 at best and, if the directory were ever moved under `public/`, an
 * unauthenticated read of somebody's child's photograph at worst. Routing every image through the
 * controller keeps the authorization check on the path the browser actually takes.
 */
final class ProfileExtension extends AbstractExtension
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly TrainingContextResolver $contexts,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('viewer_branding', $this->viewerBranding(...)),
            new TwigFunction('training_contexts', $this->trainingContexts(...)),
            new TwigFunction('current_training_context', $this->currentTrainingContext(...)),
            new TwigFunction('gender_label', $this->genderLabel(...)),
            new TwigFunction('account_photo_url', $this->accountPhotoUrl(...)),
            new TwigFunction('player_photo_url', $this->playerPhotoUrl(...)),
            new TwigFunction('branding_logo_url', $this->brandingLogoUrl(...)),
        ];
    }

    public function viewerBranding(): ResolvedBranding
    {
        $user = $this->security->getUser();

        return $this->branding->resolveForViewer($user instanceof User ? $user : null);
    }

    /**
     * @return array{own: list<TrainingContextOption>, children: list<TrainingContextOption>}
     */
    public function trainingContexts(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ['own' => [], 'children' => []];
        }

        $options = $this->contexts->availableFor($user);

        return [
            'own' => array_values(array_filter($options, static fn (TrainingContextOption $o): bool => $o->own)),
            'children' => array_values(array_filter($options, static fn (TrainingContextOption $o): bool => !$o->own)),
        ];
    }

    public function currentTrainingContext(): ?TrainingContextOption
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->contexts->current($user) : null;
    }

    /**
     * The route serving an account's photograph, or null when there is none.
     *
     * Null rather than a placeholder image, so the template decides what "no photo" looks like.
     * `MediaController` still authorizes the request — this only builds the URL, and a template
     * that is allowed to link to a photo is not thereby allowed to read it.
     */
    public function accountPhotoUrl(?User $account, bool $thumbnail = true): ?string
    {
        if (null === $account || !$account->hasPhoto()) {
            return null;
        }

        return $this->urls->generate(
            $thumbnail ? 'media_account_photo_thumbnail' : 'media_account_photo',
            ['id' => $account->getId()],
        );
    }

    public function playerPhotoUrl(?PlayerProfile $profile, bool $thumbnail = true): ?string
    {
        if (null === $profile || !$profile->hasPhoto()) {
            return null;
        }

        return $this->urls->generate(
            $thumbnail ? 'media_player_photo_thumbnail' : 'media_player_photo',
            ['id' => $profile->getId()],
        );
    }

    public function brandingLogoUrl(ResolvedBranding $branding): ?string
    {
        if (!$branding->hasLogo() || null === $branding->organizationId) {
            return null;
        }

        return $this->urls->generate('media_organization_logo', ['id' => $branding->organizationId]);
    }

    public function genderLabel(PlayerProfile $profile): string
    {
        return $profile->getGender()?->label() ?? 'Not recorded';
    }
}
