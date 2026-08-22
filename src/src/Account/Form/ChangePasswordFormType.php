<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Dto\ChangePasswordInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Voluntary and forced (FR-006) password change.
 *
 * The current password is required in both cases: a forced first-login change follows a
 * successful login with the temporary password, so the user knows it.
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Current password',
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'New password',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => 'At least 8 characters, including one capital letter.',
                ],
                'second_options' => [
                    'label' => 'Repeat new password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'The two passwords must match.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangePasswordInput::class,
        ]);
    }
}
