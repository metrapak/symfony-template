<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Dto\EditUserInput;
use App\Account\Enum\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-023 — editing any account, including its role.
 *
 * `EnumType` rather than a hand-written choice list: a role added to `UserRole` appears here
 * automatically, and a value that is not a case cannot be submitted at all, so the service
 * never has to defend against one.
 */
final class EditUserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'help' => 'Changing this signs the user out of their existing sessions.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('role', EnumType::class, [
                'class' => UserRole::class,
                'label' => 'Role',
                'help' => 'Changing this signs the user out and moves them to a different dashboard.',
                'choice_label' => static fn (UserRole $role): string => match ($role) {
                    UserRole::SuperAdmin => 'Super Admin',
                    UserRole::Trainer => 'Trainer',
                    UserRole::Coach => 'Coach',
                    UserRole::Player => 'Player / Parent',
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditUserInput::class,
        ]);
    }
}
