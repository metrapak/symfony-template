<?php

declare(strict_types=1);

namespace App\Profile\Controller;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\OrganizationBrandingRepository;
use App\Profile\Security\AccountPhotoVoter;
use App\Profile\Security\BrandingVoter;
use App\Profile\Security\ProfileVoter;
use App\Profile\Service\ImageUploader;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The only way a stored image reaches a browser (NFR-066, FR-062, FR-071).
 *
 * Uploads live in `var/uploads/`, which nginx does not serve, so there is no URL that reaches a
 * file directly. Everything goes through here, and here every request is authorized *before* a
 * byte is read — which is the entire reason the directory is outside the web root. A photograph
 * of a named child is the most identifying thing this platform stores; a static URL to it, once
 * shared or logged or guessed, is permanent and unrevokable.
 *
 * Three routes, three different rules, and they are genuinely different rather than three
 * spellings of "is the user logged in":
 *
 *  - An **account photo** is `AccountPhotoVoter` — yourself, a Super Admin, or a coach who
 *    published their profile to your organization.
 *  - A **player photo** is `ProfileVoter::VIEW` — the family, and a trainer or coach of an
 *    organization the player *actively* trains with. BR-066's "the trainer no longer sees the
 *    child" therefore covers the photograph too, without this controller knowing about it.
 *  - A **logo** is `BrandingVoter::VIEW` — the organization's members. An organization's mark
 *    should not be enumerable out of the platform by any authenticated stranger.
 *
 * Two response headers do real work. `X-Content-Type-Options: nosniff` stops a browser
 * second-guessing the type we declare, and the declared type comes from the file's own extension
 * — which `ImageUploader` derived from the *content* it parsed, never from what was uploaded.
 * `Content-Disposition: inline` with a generated filename means a saved file cannot carry a name
 * an uploader chose. Caching is `private`: these are per-viewer authorized bytes, and a shared
 * cache holding them would serve them to somebody the voter would have refused.
 *
 * A missing file is a 404 rather than a 500. A row can outlive its bytes — a failed deploy, a
 * restored database, a half-finished migration — and a broken avatar should not take the page
 * with it.
 */
final class MediaController extends AbstractController
{
    /**
     * Content types this controller will declare, keyed by the extension `ImageUploader` stored.
     *
     * An allow-list rather than a lookup: whatever ends up on disk, the type sent to the browser
     * is one of two values we chose. Guessing it from the file at read time would put the
     * decision back in the hands of whatever wrote it.
     */
    private const CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    #[Route(
        '/media/accounts/{id}/photo',
        name: 'media_account_photo',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(AccountPhotoVoter::VIEW, subject: 'account')]
    public function accountPhoto(
        #[MapEntity(id: 'id')] User $account,
        ImageUploader $uploader,
    ): Response {
        return $this->serve($uploader, $account->getPhotoPath(), 'avatar');
    }

    #[Route(
        '/media/accounts/{id}/photo/thumbnail',
        name: 'media_account_photo_thumbnail',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(AccountPhotoVoter::VIEW, subject: 'account')]
    public function accountPhotoThumbnail(
        #[MapEntity(id: 'id')] User $account,
        ImageUploader $uploader,
    ): Response {
        // Falls back to the full image when no thumbnail was produced — a resizer that could not
        // run leaves the original, and a page asking for a small avatar should still get one.
        return $this->serve($uploader, $account->getPhotoThumbnailPath() ?? $account->getPhotoPath(), 'avatar');
    }

    #[Route(
        '/media/players/{id}/photo',
        name: 'media_player_photo',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::VIEW, subject: 'profile')]
    public function playerPhoto(
        #[MapEntity(id: 'id')] PlayerProfile $profile,
        ImageUploader $uploader,
    ): Response {
        return $this->serve($uploader, $profile->getPhotoPath(), 'photo');
    }

    #[Route(
        '/media/players/{id}/photo/thumbnail',
        name: 'media_player_photo_thumbnail',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::VIEW, subject: 'profile')]
    public function playerPhotoThumbnail(
        #[MapEntity(id: 'id')] PlayerProfile $profile,
        ImageUploader $uploader,
    ): Response {
        return $this->serve($uploader, $profile->getPhotoThumbnailPath() ?? $profile->getPhotoPath(), 'photo');
    }

    #[Route(
        '/media/organizations/{id}/logo',
        name: 'media_organization_logo',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(BrandingVoter::VIEW, subject: 'organization')]
    public function organizationLogo(
        #[MapEntity(id: 'id')] Organization $organization,
        OrganizationBrandingRepository $brandings,
        ImageUploader $uploader,
    ): Response {
        $branding = $brandings->findOneForOrganization((int) $organization->getId());

        return $this->serve($uploader, $branding?->getLogoPath(), 'logo');
    }

    /**
     * @param string $downloadName the stem of the filename a save dialog would offer, so no name
     *                             an uploader chose ever reaches a viewer's disk
     */
    private function serve(ImageUploader $uploader, ?string $relativePath, string $downloadName): Response
    {
        if (null === $relativePath) {
            throw $this->createNotFoundException('No image.');
        }

        // Proves containment at the moment of reading rather than trusting the moment of writing:
        // a path that escapes the upload root comes back null, whatever put it in the column.
        $absolute = $uploader->absolutePathFor($relativePath);

        if (null === $absolute || !is_file($absolute)) {
            throw $this->createNotFoundException('That image is no longer stored.');
        }

        $extension = mb_strtolower(pathinfo($absolute, \PATHINFO_EXTENSION));
        $contentType = self::CONTENT_TYPES[$extension] ?? null;

        if (null === $contentType) {
            // On disk but not a type this controller serves. Refused rather than sent with a
            // guessed type, which is the only safe answer for a file we cannot describe.
            throw $this->createNotFoundException('That image cannot be served.');
        }

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $downloadName . '.' . $extension,
        );
        // Per-viewer authorized bytes: cacheable in the browser that was allowed to see them,
        // never in anything shared.
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }
}
