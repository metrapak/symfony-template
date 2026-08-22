<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Profile\Dto\EditChildInput;
use App\Profile\Enum\PlayerGender;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editing an existing child profile.
 *
 * A single `date` input rather than three dropdowns: `input_format` keeps the DTO's immutable
 * date, `widget: single_text` gives the browser's own date picker, and a parent on a phone gets a
 * native control instead of three selects (NFR-X05). Skill level is absent — BR-067 makes it
 * trainer-set, and this form is the parent's.
 */
final class EditChildFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Child\'s name'])
            ->add('birthDate', DateType::class, [
                'label' => 'Date of birth',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
            ])
            ->add('gender', EnumType::class, [
                'class' => PlayerGender::class,
                'label' => 'Gender',
                'choice_label' => static fn (PlayerGender $gender): string => $gender->label(),
            ])
            ->add('school', TextType::class, ['label' => 'School', 'required' => false])
            ->add('jerseyNumber', TextType::class, [
                'label' => 'Jersey number',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 3],
            ])
            ->add('photo', FileType::class, [
                'label' => 'Photo',
                'required' => false,
                'help' => 'PNG or JPEG, up to 2 MB.',
                'attr' => ['accept' => 'image/png,image/jpeg'],
            ])
            ->add('removePhoto', CheckboxType::class, [
                'label' => 'Remove the current photo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EditChildInput::class]);
    }
}
