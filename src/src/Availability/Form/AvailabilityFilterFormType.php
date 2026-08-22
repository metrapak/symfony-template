<?php

declare(strict_types=1);

namespace App\Availability\Form;

use App\Availability\Dto\AvailabilityFilterInput;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Service\TimeGrid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Show players available on {day} at {time}" (FR-084).
 *
 * A GET form with CSRF disabled, deliberately: it reads and changes nothing, and a filter whose
 * result cannot be bookmarked or shared with a colleague is a worse tool. The same reasoning is
 * why the fields are optional — the unfiltered page is the default view of the roster.
 *
 * Times come from `TimeGrid`, so the filter can only ask about windows the grid can express. A
 * trainer searching for 17:37 would find nobody and learn nothing about why.
 */
final class AvailabilityFilterFormType extends AbstractType
{
    public function __construct(
        private readonly TimeGrid $grid,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('day', EnumType::class, [
                'label' => 'Day',
                'class' => DayOfWeek::class,
                'choice_label' => static fn (DayOfWeek $day): string => $day->label(),
                'placeholder' => 'Any day',
                'required' => false,
            ])
            ->add('startMinute', ChoiceType::class, [
                'label' => 'From',
                'choices' => $this->grid->startChoices(),
                'placeholder' => 'Any time',
                'required' => false,
            ])
            ->add('endMinute', ChoiceType::class, [
                'label' => 'Until',
                'choices' => $this->grid->endChoices(),
                'placeholder' => 'Any time',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AvailabilityFilterInput::class,
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }
}
