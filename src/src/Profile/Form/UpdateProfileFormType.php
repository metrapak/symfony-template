<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Account\Enum\UserRole;
use App\Profile\Dto\UpdateProfileInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-060 and FR-061 — the self-service profile form, built per role.
 *
 * **"Each role sees only its own fields" is enforced by which fields exist**, not by hiding
 * them. A coach's form has no `businessName` child at all, so a POST carrying one is discarded
 * by the form component before any service sees it. Hiding fields in Twig would leave them
 * bindable, and the difference only shows up when somebody crafts a request.
 *
 * The read-only fields FR-060 lists — email, role, skill level, creation date — are not here
 * either, for the same reason and one more: they are absent from `UpdateProfileInput` too, so
 * there is nothing for a rogue field to write into. The template renders them as text.
 */
final class UpdateProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var UserRole $role */
        $role = $options['role'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('photo', FileType::class, [
                'label' => 'Profile photo',
                'required' => false,
                'mapped' => true,
                'help' => 'PNG or JPEG, up to 2 MB.',
                // Advisory only: it filters the file picker and is not a check. The real
                // validation reads the file's content (NFR-066) — see ImageUploader.
                'attr' => ['accept' => 'image/png,image/jpeg'],
            ])
            ->add('removePhoto', CheckboxType::class, [
                'label' => 'Remove my current photo',
                'required' => false,
            ]);

        match ($role) {
            UserRole::Player => $this->addPlayerFields($builder),
            UserRole::Coach => $this->addCoachFields($builder),
            UserRole::Trainer => $this->addTrainerFields($builder),
            UserRole::SuperAdmin => $this->addAdminFields($builder),
        };
    }

    private function addPlayerFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('school', TextType::class, [
                'label' => 'School',
                'required' => false,
            ])
            ->add('jerseyNumber', TextType::class, [
                'label' => 'Jersey number',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 3],
            ]);
    }

    private function addCoachFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('bio', TextareaType::class, [
                'label' => 'Bio',
                'required' => false,
                'attr' => ['rows' => 5],
            ])
            ->add('credentials', TextareaType::class, [
                'label' => 'Credentials',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('certifications', TextareaType::class, [
                'label' => 'Certifications',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('publicProfile', CheckboxType::class, [
                'label' => 'Show my profile to players and parents in this organization',
                'required' => false,
                'help' => 'Off by default. When on, your bio, credentials and photo are visible to this organization\'s members.',
            ]);
    }

    private function addTrainerFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('businessName', TextType::class, [
                'label' => 'Business name',
                'required' => false,
                'help' => 'What players and parents see. Leave empty to use your organization\'s name.',
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Address',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('website', UrlType::class, [
                'label' => 'Website',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'About your programme',
                'required' => false,
                'attr' => ['rows' => 5],
            ]);
    }

    private function addAdminFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('notifyOnTrainerCreated', CheckboxType::class, [
                'label' => 'Email me when a trainer account is created',
                'required' => false,
            ])
            ->add('notifyOnAccountErasure', CheckboxType::class, [
                'label' => 'Email me when an account is erased',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UpdateProfileInput::class,
            'role' => UserRole::Player,
            // Resolved from the role so the rules that apply and the fields that exist are
            // decided from one value (see UpdateProfileInput::groupsFor).
            'validation_groups' => static fn (FormInterface $form): array => UpdateProfileInput::groupsFor($form->getConfig()->getOption('role')),
        ]);

        $resolver->setAllowedTypes('role', UserRole::class);
    }
}
