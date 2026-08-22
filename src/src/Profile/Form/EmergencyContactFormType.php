<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Profile\Dto\EmergencyContactInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-061's parent emergency contact. Three fields, all required — see `EmergencyContactInput`
 * for why these three and not others.
 */
final class EmergencyContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Contact name',
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('relationship', TextType::class, [
                'label' => 'Relationship to your child',
                'attr' => ['placeholder' => 'Grandmother, neighbour, …'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'attr' => ['autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EmergencyContactInput::class]);
    }
}
