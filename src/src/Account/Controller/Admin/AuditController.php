<?php

declare(strict_types=1);

namespace App\Account\Controller\Admin;

use App\Account\Repository\ImpersonationSessionRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Impersonation History" compliance report (FR-032).
 *
 * Read-only and Super-Admin-only by virtue of living under `/admin`.
 */
final class AuditController extends AbstractController
{
    private const PAGE_SIZE = 50;

    #[Route('/admin/audit/impersonations', name: 'admin_audit_impersonations', methods: ['GET'])]
    public function impersonations(
        Request $request,
        ImpersonationSessionRepository $sessions,
        PaginatorInterface $paginator,
    ): Response {
        return $this->render('admin/audit/impersonations.html.twig', [
            'sessions' => $paginator->paginate(
                $sessions->historyQuery(),
                max(1, $request->query->getInt('page', 1)),
                self::PAGE_SIZE,
            ),
        ]);
    }
}
