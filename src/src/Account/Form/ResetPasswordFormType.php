<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Dto\ResetPasswordInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'New password',
                'attr' => ['autocomplete' => 'new-password', 'autofocus' => true],
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
            'data_class' => ResetPasswordInput::class,
        ]);
    }
}
