<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Profile\Dto\AddTrainerInput;
use App\Profile\Dto\AssociationRecord;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * FR-066's "Add Trainer": paste a link, or pick a trainer you already train with.
 *
 * The `organizationId` choices are the trainers this *player* is not already with — computed by
 * `FamilyAssociationManager::addableTrainersFor()`, so the list never offers an association that
 * already exists. When it is empty the field is omitted entirely and the parent is left with the
 * code field, which is the honest rendering of "you have nobody else to add them to yet".
 */
final class AddTrainerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AssociationRecord> $trainers */
        $trainers = $options['trainers'];

        $builder->add('shareLinkCode', TextType::class, [
            'label' => 'Trainer link or code',
            'required' => false,
            'help' => 'Paste the link or code your trainer sent you.',
            'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'characters'],
        ]);

        if ([] === $trainers) {
            return;
        }

        $choices = [];
        foreach ($trainers as $trainer) {
            $choices[$trainer->organizationName] = $trainer->organizationId;
        }

        $builder->add('organizationId', ChoiceType::class, [
            'label' => 'Or choose one of your trainers',
            'choices' => $choices,
            'required' => false,
            'placeholder' => 'Choose a trainer…',
            'constraints' => [new Assert\Choice(choices: array_values($choices))],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddTrainerInput::class,
            'trainers' => [],
        ]);

        $resolver->setAllowedTypes('trainers', 'array');
    }
}
