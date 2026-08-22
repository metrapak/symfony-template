<?php

declare(strict_types=1);

namespace App\Availability\Form;

use App\Availability\Dto\CoachConflictInput;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Service\TimeGrid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Checking a coach against their stated times, and overriding the conflict (FR-085, FR-086).
 *
 * Two submits on one form, which is what makes FR-086's sequence work without a second endpoint
 * or a session-held draft:
 *
 *  - **Check** validates the window and asks the question. No reason required, nothing recorded.
 *  - **Continue anyway** appears once a conflict has been shown, and switches the `override`
 *    validation group on, which is what makes the reason mandatory. A blank reason is a
 *    validation error on the same page, so the override is not recorded — FR-086's "an empty
 *    reason blocks the override", enforced by the group rather than by an `if` somebody can
 *    forget.
 *
 * The window fields stay on the form through both steps, so the reason is attached to the exact
 * window the trainer was warned about rather than to whatever the page happened to remember.
 */
final class CoachConflictFormType extends AbstractType
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
                'placeholder' => 'Select a day…',
            ])
            ->add('startMinute', ChoiceType::class, [
                'label' => 'Session starts',
                'choices' => $this->grid->startChoices(),
                'placeholder' => 'Select…',
            ])
            ->add('endMinute', ChoiceType::class, [
                'label' => 'Session ends',
                'choices' => $this->grid->endChoices(),
                'placeholder' => 'Select…',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Reason for scheduling outside their stated times',
                // Not `required` at the HTML level: the field is on the page during the check
                // step too, where it must not stop the browser submitting. Requiredness comes
                // from the validation group, which is the half that cannot be bypassed.
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'The coach and your records will show this.',
            ])
            ->add('check', SubmitType::class, ['label' => 'Check availability'])
            // A button is never mapped onto the data, so the DTO's `confirm` flag is set by the
            // controller from `isClicked()`. Deliberately no `validation_groups` option here:
            // on a button that option *replaces* the form's groups, and `false` would skip
            // validation altogether — which is exactly how a blank reason would get through.
            ->add('confirm', SubmitType::class, ['label' => 'Continue anyway']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CoachConflictInput::class,
            'validation_groups' => static function (FormInterface $form): array {
                $confirm = $form->has('confirm') ? $form->get('confirm') : null;

                return $confirm instanceof ClickableInterface && $confirm->isClicked()
                    ? ['Default', 'override']
                    : ['Default'];
            },
        ]);
    }
}
