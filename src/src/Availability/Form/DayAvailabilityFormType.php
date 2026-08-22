<?php

declare(strict_types=1);

namespace App\Availability\Form;

use App\Availability\Dto\DayAvailabilityInput;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Service\TimeGrid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One day of the availability grid: a column of block checkboxes and the "not available" box
 * (FR-080, FR-082, NFR-081).
 *
 * **Real checkboxes, one per block, with real labels.** NFR-081 is the hardest accessibility
 * requirement in the epic and it is met structurally rather than with ARIA: an expanded,
 * multiple `ChoiceType` renders native `<input type="checkbox">` elements with `<label>`s, so the
 * grid is keyboard-operable, screen-reader navigable and submittable with JavaScript disabled
 * before any enhancement is loaded. `assets/availability-grid.js` adds drag-to-select and the
 * running summary on top of a control that already works without it.
 *
 * Each label carries the whole spoken name — "Monday 5:00 PM to 6:00 PM, available" — because a
 * screen reader reaching a cell mid-table announces the label, not the column header it was
 * rendered under. The visible text is hidden by CSS and the header row carries `scope="col"`, so
 * a sighted user reads a compact grid and a screen-reader user hears an unambiguous name.
 */
final class DayAvailabilityFormType extends AbstractType
{
    public function __construct(
        private readonly TimeGrid $grid,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var DayOfWeek $day */
        $day = $options['day'];

        $builder
            ->add('slots', ChoiceType::class, [
                'label' => false,
                'choices' => self::blockChoices($day, $this->grid),
                // Explicit values so the submitted payload names a time rather than a position:
                // the default for integer choices is the array index, which would mean a
                // different hour the moment the block length is reconfigured. (The rendered
                // *child names* are positional whatever this is — Symfony keys expanded choices
                // by their place in the list — so the grid template looks a cell up by index.)
                'choice_value' => static fn (?int $minute): string => null === $minute ? '' : 'm' . $minute,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                // Nothing is invalid here — the choice list is the whole vocabulary — but a
                // forged minute has to fail as a rejected submit rather than as a 500.
                'invalid_message' => 'That is not a time slot on this grid.',
            ])
            ->add('unavailable', CheckboxType::class, [
                'label' => \sprintf('Not available on %s', $day->label()),
                'required' => false,
            ]);
    }

    /**
     * `[spoken label => start minute]` for one day.
     *
     * @return array<string, int>
     */
    private static function blockChoices(DayOfWeek $day, TimeGrid $grid): array
    {
        $choices = [];

        foreach ($grid->blocks() as $block) {
            $choices[\sprintf('%s %s, available', $day->label(), $block->formatSpoken())] = $block->startMinute;
        }

        return $choices;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => DayAvailabilityInput::class])
            ->setRequired('day')
            ->setAllowedTypes('day', DayOfWeek::class);
    }
}
