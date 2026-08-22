<?php

declare(strict_types=1);

namespace App\Approval\Form;

use App\Approval\Dto\SpendingSettingInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-092's switch, with the requirement's own wording as its label.
 *
 * The help text states the two rules the checkbox does *not* cover, because they are the two
 * things a parent is most likely to assume wrongly: USD is never waivable (BR-090), and turning
 * this on does not decide a request that is already waiting (G-32).
 */
final class SpendingSettingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('allowTokenSpendingWithoutApproval', CheckboxType::class, [
            'label' => 'Allow token spending without approval',
            'required' => false,
            'help' => 'Dollar purchases always need your approval, whatever this is set to. Turning this on does not decide requests that are already waiting for you.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SpendingSettingInput::class,
        ]);
    }
}
