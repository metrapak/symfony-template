<?php

declare(strict_types=1);

namespace App\Profile\Controller;

use App\Account\Entity\User;
use App\Profile\Exception\ContextNotAvailable;
use App\Profile\Service\TrainingContextResolver;
use App\Profile\ValueObject\TrainingContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Selecting a training context (FR-069, FR-070, NFR-063).
 *
 * The whole of this controller is one call to `switchTo()`, and that is the point: **FR-070 is
 * enforced in the resolver, not here.** A forged `context` field naming another family's profile
 * is refused because the resolver only ever matches against the associations the *current user*
 * actually holds — so there is no id in this file that is trusted, and no query that could be
 * scoped wrongly.
 *
 * `ContextNotAvailable` becomes a **403, not a 404**. The distinction matters: the pair either
 * does not exist or belongs to somebody else, and answering differently in the two cases would
 * turn this endpoint into an oracle for "does profile 41 train with organization 7?".
 *
 * POST only, and CSRF-checked. Switching context replaces the whole dataset a page shows, and an
 * `<img src="/context/switch?...">` on a hostile page must not be able to move somebody from
 * their own training to their child's mid-session.
 *
 * The redirect goes back where the user was, but only after the target is checked against this
 * application's own routes — a `Referer` is client-controlled, and following it blindly is an
 * open redirect. Anything unrecognised falls back to the dashboard.
 */
final class ContextController extends AbstractController
{
    #[Route('/context/switch', name: 'context_switch', methods: ['POST'])]
    public function switch(
        Request $request,
        #[CurrentUser] User $user,
        TrainingContextResolver $contexts,
    ): Response {
        if (!$this->isCsrfTokenValid('switch_context', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $submitted = TrainingContext::tryParse($request->request->getString('context'));

        try {
            $option = $contexts->switchTo($user, $submitted);
        } catch (ContextNotAvailable $e) {
            // FR-070's "must return 403 — never data". Malformed and unauthorized are one
            // answer, so the response says nothing about which contexts exist.
            throw $this->createAccessDeniedException($e->getMessage(), $e);
        }

        $this->addFlash('success', \sprintf('You are now viewing %s.', $option->label()));

        return $this->redirect($this->safeReturnUrl($request));
    }

    /**
     * The page to go back to: the referring URL when it is one of ours, the dashboard otherwise.
     *
     * Compared on host and scheme rather than by a prefix match on the string, because
     * `https://example.com.evil.test/` starts with the right characters and is not this site.
     */
    private function safeReturnUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        $fallback = $this->generateUrl('account_dashboard');

        if (null === $referer || '' === $referer) {
            return $fallback;
        }

        $target = parse_url($referer);

        if (false === $target || !isset($target['host'])) {
            return $fallback;
        }

        if ($target['host'] !== $request->getHost() || ($target['scheme'] ?? '') !== $request->getScheme()) {
            return $fallback;
        }

        return $referer;
    }
}
