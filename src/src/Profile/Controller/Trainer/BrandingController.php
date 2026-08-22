<?php

declare(strict_types=1);

namespace App\Profile\Controller\Trainer;

use App\Account\Entity\Organization;
use App\Account\Repository\OrganizationRepository;
use App\Account\Service\TenantContext;
use App\Profile\Dto\BrandingInput;
use App\Profile\Exception\ImageRejected;
use App\Profile\Form\BrandingFormType;
use App\Profile\Security\BrandingVoter;
use App\Profile\Service\BrandingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Trainer portal branding — logo and colour (FR-071, FR-072, BR-069).
 *
 * The organization comes from `TenantContext`, never from the URL, so there is no id to tamper
 * with: a trainer edits the organization they own because that is the only one this controller
 * can name. `BrandingVoter::EDIT` is checked anyway, because "the tenant resolver returned it"
 * and "this account may write it" are two different claims and only the second is authorization.
 * The check is a `denyAccessUnlessGranted()` rather than `#[IsGranted]` for a mechanical reason:
 * the subject is not a route parameter, so there is no argument for the attribute to name.
 *
 * FR-072's "changes visible immediately to everyone in the organization" needs no cache
 * invalidation: branding is resolved per request from the viewer's active context (G-26), so the
 * next page anybody in the tenant loads already carries the new colour.
 *
 * The colour never reaches a template as raw input. It leaves as a validated `HexColor` and the
 * layout binds it to a CSS custom property — `<style>` is the one context where Twig's HTML
 * escaping is the wrong escaping, so the defence is a value that cannot carry a payload rather
 * than an escape applied at the point of use.
 */
final class BrandingController extends AbstractController
{
    #[Route('/trainer/branding', name: 'trainer_branding', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        TenantContext $tenant,
        OrganizationRepository $organizations,
        BrandingService $branding,
    ): Response {
        $organization = $this->requireOwnOrganization($tenant, $organizations);
        $current = $branding->forOrganization($organization);

        $form = $this->createForm(BrandingFormType::class, BrandingInput::fromBranding($current));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BrandingInput $input */
            $input = $form->getData();

            try {
                $branding->update($organization, $input);
            } catch (ImageRejected $e) {
                $form->get('logo')->addError(new FormError($e->getMessage()));

                return $this->renderForm($form, $organization, $branding);
            }

            $this->addFlash('success', 'Your branding has been saved. Everyone in your organization sees it now.');

            return $this->redirectToRoute('trainer_branding');
        }

        return $this->renderForm($form, $organization, $branding);
    }

    /**
     * FR-072's reset-to-default. Clears the colour and keeps the logo — they are two separate
     * controls in the requirement, and a trainer resetting a colour would not expect their logo
     * to disappear with it.
     */
    #[Route('/trainer/branding/reset', name: 'trainer_branding_reset', methods: ['POST'])]
    public function reset(
        Request $request,
        TenantContext $tenant,
        OrganizationRepository $organizations,
        BrandingService $branding,
    ): Response {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $branding->resetColor($this->requireOwnOrganization($tenant, $organizations));

        $this->addFlash('success', 'Your colour is back to the platform default.');

        return $this->redirectToRoute('trainer_branding');
    }

    private function requireOwnOrganization(TenantContext $tenant, OrganizationRepository $organizations): Organization
    {
        $organization = $organizations->find($tenant->requireOrganizationId())
            ?? throw $this->createNotFoundException('No organization in context.');

        $this->denyAccessUnlessGranted(BrandingVoter::EDIT, $organization);

        return $organization;
    }

    private function renderForm(FormInterface $form, Organization $organization, BrandingService $branding): Response
    {
        return $this->render('trainer/branding/edit.html.twig', [
            'form' => $form,
            'organization' => $organization,
            // The resolved values, so the preview shows what a member of this organization will
            // actually see rather than what the form fields happen to hold.
            'branding' => $branding->resolveForOrganization((int) $organization->getId(), $organization->getName()),
            'usesDefaultColor' => $branding->forOrganization($organization)->usesDefaultColor(),
        ]);
    }
}
