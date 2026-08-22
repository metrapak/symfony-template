<?php

declare(strict_types=1);

namespace App\Membership\Form;

use App\Membership\Dto\PlayerRegistrationInput;
use App\Profile\Enum\PlayerGender;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-042 — the form behind a player ShareLink.
 *
 * The self-versus-child question comes first because everything after it depends on the
 * answer (G-21). It is a pair of radio buttons rather than an inferred value: a parent
 * registering a seven-year-old and a nineteen-year-old registering themselves both type a
 * birth date, and only the person filling the form knows which of them they are.
 *
 * `validation_groups` is resolved from the submitted flag, which is what makes the
 * progressive enhancement safe. JavaScript may hide the child fields, but the server decides
 * which rules apply from the data it received, so a hidden field is still a validated one.
 */
final class PlayerRegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('registeringChild', ChoiceType::class, [
                'label' => 'Who is joining?',
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'I am the player' => false,
                    'I am registering my child' => true,
                ],
                // Booleans stringify to '1' and '' by default, and an empty submitted value is
                // indistinguishable from no choice at all. Naming them explicitly keeps "I am
                // the player" a real, re-selectable answer after a rejected submit.
                'choice_value' => static fn (?bool $choice): string => match ($choice) {
                    true => 'child',
                    false => 'self',
                    null => '',
                },
                'placeholder' => false,
            ])
            ->add('name', TextType::class, [
                'label' => 'Your name',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'help' => 'This is how you sign in, and where your confirmation is sent.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Password',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => 'At least 8 characters, including one capital letter.',
                ],
                'second_options' => [
                    'label' => 'Repeat password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'The two passwords must match.',
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('playerName', TextType::class, [
                'label' => 'Player name',
                'required' => false,
                'help' => 'The name your child is known by at training.',
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Date of birth',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'Used to place the player in the right age group.',
            ])
            ->add('gender', EnumType::class, [
                'label' => 'Gender',
                'class' => PlayerGender::class,
                'choice_label' => static fn (PlayerGender $gender): string => $gender->label(),
                'placeholder' => 'Select…',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlayerRegistrationInput::class,
            'validation_groups' => static function (FormInterface $form): array {
                $data = $form->getData();
                $registeringChild = $data instanceof PlayerRegistrationInput && $data->registeringChild;

                return ['Default', $registeringChild ? 'child' : 'self'];
            },
        ]);
    }
}
