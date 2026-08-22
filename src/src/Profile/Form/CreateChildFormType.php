<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Profile\Dto\AssociationRecord;
use App\Profile\Dto\CreateChildInput;
use App\Profile\Enum\PlayerGender;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * FR-063 and FR-064 — "+ Add Child", including the trainer branch.
 *
 * FR-064 specifies two shapes: with exactly one trainer, ask "Will {child} also train with
 * {trainer}?" as a yes/no; with several, show a checklist. Both are the same `organizationIds`
 * field rendered differently, which is what keeps the service — and its authorization check —
 * unaware of which shape produced a submission. A parent with no trainers gets neither, and
 * `trainers` is then empty, which is the third case FR-064 allows: a child with no association.
 *
 * The choices come from the controller, which reads them from the parent's own associations. As
 * with `FamilySelectionFormType`, the list a form renders and the list a submission is authorized
 * against must come from one place — and here the service checks them again, because a form's
 * choice list is not an authorization boundary.
 */
final class CreateChildFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AssociationRecord> $trainers */
        $trainers = $options['trainers'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Child\'s name',
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('age', IntegerType::class, [
                'label' => 'Age',
                // Advisory attributes, not the validation: the range is on the DTO and re-checked
                // in the service (BR-068).
                'attr' => ['min' => 1, 'max' => 18, 'inputmode' => 'numeric'],
                'help' => 'You can set an exact date of birth later.',
            ])
            ->add('gender', EnumType::class, [
                'class' => PlayerGender::class,
                'label' => 'Gender',
                'placeholder' => 'Choose…',
                'choice_label' => static fn (PlayerGender $gender): string => $gender->label(),
            ])
            ->add('school', TextType::class, [
                'label' => 'School',
                'required' => false,
            ])
            ->add('photo', FileType::class, [
                'label' => 'Photo',
                'required' => false,
                'help' => 'PNG or JPEG, up to 2 MB.',
                'attr' => ['accept' => 'image/png,image/jpeg'],
            ])
            // Carried in the form rather than the session so a parent with two tabs open cannot
            // have one tab's acknowledgement apply to the other's child.
            ->add('acknowledgedDuplicate', HiddenType::class, [
                'required' => false,
            ]);

        // A hidden input carries a string and the DTO holds a `bool`, so the conversion has to
        // happen somewhere; without it an unset field arrives as null and PropertyAccessor
        // refuses it. Only the literal "1" acknowledges — anything else, including the empty
        // string a first submit sends, means "not yet acknowledged", so a malformed or absent
        // value can never be mistaken for consent the parent did not give.
        $builder->get('acknowledgedDuplicate')->addModelTransformer(new CallbackTransformer(
            static fn (?bool $acknowledged): string => true === $acknowledged ? '1' : '',
            static fn (?string $submitted): bool => '1' === $submitted,
        ));

        if ([] === $trainers) {
            return;
        }

        $choices = [];
        foreach ($trainers as $trainer) {
            $choices[$trainer->organizationName] = $trainer->organizationId;
        }

        $builder->add('organizationIds', ChoiceType::class, [
            'label' => 1 === \count($trainers)
                ? \sprintf('Will they also train with %s?', $trainers[0]->organizationName)
                : 'Which of your trainers will they train with?',
            'choices' => $choices,
            'expanded' => true,
            'multiple' => true,
            'required' => false,
            'constraints' => [
                // Every submitted value must be one this form offered. Belt to the service's
                // braces: this rejects a tampered id with a form error rather than an exception,
                // which is the better experience for the ordinary case of a stale page.
                new Assert\Choice(choices: array_values($choices), multiple: true),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateChildInput::class,
            'trainers' => [],
        ]);

        $resolver->setAllowedTypes('trainers', 'array');
    }
}
