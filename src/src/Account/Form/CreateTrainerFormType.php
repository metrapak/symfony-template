<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Dto\CreateTrainerInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-021 — "Create User" → Trainer.
 *
 * There is no role selector: Trainer is the only role a Super Admin creates directly
 * (BR-020; coaches and players arrive through ShareLinks in TASK-003). A dropdown with one
 * option would suggest otherwise.
 */
final class CreateTrainerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('businessName', TextType::class, [
                'label' => 'Business name',
                'help' => 'The name of the trainer\'s organization. Used as their tenant name.',
                'attr' => ['autocomplete' => 'organization'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Trainer name',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'help' => 'The invitation and their sign-in credential go here.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'attr' => ['autocomplete' => 'tel'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateTrainerInput::class,
        ]);
    }
}
