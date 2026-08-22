<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Profile\Dto\ChildLoginInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-067 — a parent creating their child's login (G-23).
 *
 * No email field: the address is derived, and why is on `ChildLoginManager`. `autocomplete` is
 * set to the "new password" and "username" hints so a parent's password manager offers to store
 * the credential they are creating rather than autofilling their own.
 */
final class ChildLoginFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Username for your child',
                'help' => 'Letters, digits and . _ - only. This is what they will sign in with.',
                'attr' => ['autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'First password',
                'help' => 'Your child will be asked to change this when they first sign in.',
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChildLoginInput::class]);
    }
}
