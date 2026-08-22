<?php

declare(strict_types=1);

namespace App\Membership\Form;

use App\Membership\Dto\FamilySelectionInput;
use App\Profile\Entity\PlayerProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-044 — "Who will train with {trainer}?".
 *
 * The choices are passed in by the controller from the profiles the account manages rather
 * than queried here, for the same reason the service re-checks them: the list a form renders
 * and the list a submission is authorized against must come from one place, and that place is
 * the account's own family.
 *
 * Rendered as a real `fieldset` with a `legend` by the template, so the group has an
 * accessible name and every checkbox has a label of its own (NFR-043).
 */
final class FamilySelectionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<PlayerProfile> $profiles */
        $profiles = $options['profiles'];

        $choices = [];
        foreach ($profiles as $profile) {
            $label = $profile->isChild()
                ? $profile->getDisplayName()
                : \sprintf('%s (Me)', $profile->getDisplayName());

            $choices[$label] = (int) $profile->getId();
        }

        $builder->add('profileIds', ChoiceType::class, [
            'label' => false,
            'expanded' => true,
            'multiple' => true,
            'choices' => $choices,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FamilySelectionInput::class,
            'profiles' => [],
        ]);

        $resolver->setAllowedTypes('profiles', 'array');
    }
}
