<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Dto\ForgotPasswordInput;
use App\Account\Dto\ResetPasswordInput;
use App\Account\Exception\AccountException;
use App\Account\Form\ForgotPasswordFormType;
use App\Account\Form\ResetPasswordFormType;
use App\Account\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordResetController extends AbstractController
{
    /**
     * The token is parked in the session and the browser is redirected to a tokenless URL,
     * so the secret never sits in browser history or leaks through a Referer header from
     * the form page. This is the reset-password bundle's documented pattern.
     */
    private const TOKEN_SESSION_KEY = 'account_reset_password_token';

    private const CONFIRMATION_MESSAGE = 'If an account exists for that address, a password reset link is on its way. The link is valid for one hour.';

    #[Route('/password/forgot', name: 'account_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request, PasswordResetService $passwordResetService): Response
    {
        $form = $this->createForm(ForgotPasswordFormType::class, new ForgotPasswordInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ForgotPasswordInput $input */
            $input = $form->getData();

            $passwordResetService->requestReset((string) $input->email);

            // The same message for every outcome — known address, unknown address,
            // deactivated account, throttled repeat (FR-004, no user enumeration).
            $this->addFlash('success', self::CONFIRMATION_MESSAGE);

            return $this->redirectToRoute('account_password_forgot');
        }

        return $this->render('account/forgot_password.html.twig', ['form' => $form]);
    }

    #[Route('/password/reset/{token}', name: 'account_password_reset', methods: ['GET'])]
    public function consumeToken(Request $request, string $token): Response
    {
        $request->getSession()->set(self::TOKEN_SESSION_KEY, $token);

        return $this->redirectToRoute('account_password_reset_form');
    }

    #[Route('/password/reset', name: 'account_password_reset_form', methods: ['GET', 'POST'])]
    public function reset(Request $request, PasswordResetService $passwordResetService): Response
    {
        $token = $request->getSession()->get(self::TOKEN_SESSION_KEY);

        if (!\is_string($token) || '' === $token) {
            $this->addFlash('error', 'No password reset token found. Please request a new link.');

            return $this->redirectToRoute('account_password_forgot');
        }

        try {
            $passwordResetService->validateToken($token);
        } catch (AccountException $e) {
            $request->getSession()->remove(self::TOKEN_SESSION_KEY);
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('account_password_forgot');
        }

        $form = $this->createForm(ResetPasswordFormType::class, new ResetPasswordInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ResetPasswordInput $input */
            $input = $form->getData();

            try {
                $passwordResetService->resetPassword($token, (string) $input->plainPassword);
            } catch (AccountException $e) {
                $request->getSession()->remove(self::TOKEN_SESSION_KEY);
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('account_password_forgot');
            }

            $request->getSession()->remove(self::TOKEN_SESSION_KEY);
            $this->addFlash('success', 'Your password has been reset. You can sign in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('account/reset_password.html.twig', ['form' => $form]);
    }
}
