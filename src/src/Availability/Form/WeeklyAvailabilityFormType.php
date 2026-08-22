<?php

declare(strict_types=1);

namespace App\Availability\Form;

use App\Availability\Dto\WeeklyAvailabilityInput;
use App\Availability\Enum\DayOfWeek;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The weekly grid, for a player or a coach alike (FR-080, FR-082).
 *
 * One form for both, because the control is the same one: US-01.09 and US-01.10 describe the same
 * grid with a different heading, and a second form type would be a second place for the block
 * list, the labels and the accessibility work to drift.
 *
 * Seven named children rather than a `CollectionType`, keyed by day. A collection would give
 * numeric field names — `days[0]` — which reads as an offset in a template and makes a rendered
 * grid impossible to check by eye against the day it belongs to. The names are also part of the
 * URL-encoded payload a test posts, so they are worth being words.
 */
final class WeeklyAvailabilityFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (DayOfWeek::week() as $day) {
            $builder->add($day->key(), DayAvailabilityFormType::class, [
                'day' => $day,
                'label' => $day->label(),
                // The DTO keys its days by the same string, so the child maps straight onto the
                // array entry without a per-day property.
                'property_path' => \sprintf('days[%s]', $day->key()),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WeeklyAvailabilityInput::class]);
    }
}
