<?php

declare(strict_types=1);

namespace App\Membership\Form;

use App\Membership\Dto\CoachInviteInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-041 / US-01.08 — "Enter coach email, optional (name, message)".
 */
final class CoachInviteFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Coach email address',
                'help' => 'The invitation is single-use and expires after 7 days.',
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Coach name (optional)',
                'required' => false,
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Personal message (optional)',
                'required' => false,
                'attr' => ['rows' => 4],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CoachInviteInput::class,
        ]);
    }
}
