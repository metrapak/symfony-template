<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Dto\ResendVerificationInput;
use App\Account\Exception\AccountException;
use App\Account\Form\ResendVerificationFormType;
use App\Account\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Both routes are reachable while anonymous on purpose: the verification link is clicked
 * from a mail client, and a user who cannot sign in until they verify is precisely the one
 * who needs to request a new link. The signature, not the session, is what authorizes.
 */
final class EmailVerificationController extends AbstractController
{
    private const CONFIRMATION_MESSAGE = 'If that address needs confirming, a new link is on its way. The link is valid for 24 hours.';

    #[Route('/verify/email', name: 'account_verify_email', methods: ['GET'])]
    public function verify(
        Request $request,
        EmailVerificationService $emailVerificationService,
    ): Response {
        try {
            $emailVerificationService->verify($request->getUri(), $request->query->getInt('id'));
        } catch (AccountException $e) {
            return $this->renderFailure($e->getMessage());
        }

        $this->addFlash('success', 'Your email address is confirmed. You can sign in now.');

        return $this->render('account/verify_notice.html.twig', ['verified' => true]);
    }

    #[Route('/verify/resend', name: 'account_verify_resend', methods: ['GET', 'POST'])]
    public function resend(
        Request $request,
        EmailVerificationService $emailVerificationService,
        RateLimiterFactoryInterface $verificationResendLimiter,
    ): Response {
        $form = $this->createForm(ResendVerificationFormType::class, new ResendVerificationInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ResendVerificationInput $input */
            $input = $form->getData();

            // Anonymous and it sends mail to whatever address it is given, so it needs a
            // limit of its own. Keyed by client IP rather than by the submitted address,
            // which the caller controls.
            if (!$verificationResendLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Too many requests. Please try again later.');

                return $this->redirectToRoute('account_verify_resend');
            }

            $emailVerificationService->resendFor((string) $input->email);

            // Same message for every outcome — unknown address, already verified, or sent.
            $this->addFlash('success', self::CONFIRMATION_MESSAGE);

            return $this->redirectToRoute('account_verify_resend');
        }

        return $this->render('account/resend_verification.html.twig', ['form' => $form]);
    }

    private function renderFailure(string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->render(
            'account/verify_notice.html.twig',
            ['verified' => false],
            new Response('', Response::HTTP_BAD_REQUEST),
        );
    }
}
