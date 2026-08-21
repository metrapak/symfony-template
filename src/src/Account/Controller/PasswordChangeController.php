<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Dto\ChangePasswordInput;
use App\Account\Entity\User;
use App\Account\Form\ChangePasswordFormType;
use App\Account\Service\PasswordChanger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PasswordChangeController extends AbstractController
{
    #[Route('/account/password', name: 'account_password_change', methods: ['GET', 'POST'])]
    public function change(
        Request $request,
        #[CurrentUser] User $user,
        PasswordChanger $passwordChanger,
    ): Response {
        $wasForced = $user->mustChangePassword();

        $form = $this->createForm(ChangePasswordFormType::class, new ChangePasswordInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ChangePasswordInput $input */
            $input = $form->getData();

            $passwordChanger->change($user, (string) $input->plainPassword);

            $this->addFlash('success', 'Your password has been updated.');

            return $this->redirectToRoute('account_dashboard');
        }

        return $this->render('account/change_password.html.twig', [
            'form' => $form,
            'forced' => $wasForced,
        ]);
    }
}
