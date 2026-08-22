<?php

declare(strict_types=1);

namespace App\Approval\Form;

use App\Approval\Dto\ApprovalDecisionInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The note a parent may attach to either decision (BR-093, FR-094).
 *
 * **One field and no submit buttons, deliberately.** FR-094 puts Approve and Deny on separate
 * routes, and the two actions share this one note: rendering them as Symfony submit buttons would
 * tie the form to a single action URL, and splitting into two forms would give the parent two
 * note boxes, one of which silently loses whatever they typed in it. The template renders two
 * plain submit buttons instead, the second carrying `formaction` — an HTML attribute, so the
 * choice of action works with JavaScript off — and both routes bind this same form, which means
 * both are CSRF-protected by the same token.
 */
final class ApprovalDecisionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('notes', TextareaType::class, [
            'label' => 'Note for your child (optional)',
            'required' => false,
            'attr' => ['rows' => 3],
            'help' => 'Whatever you write here is shown to your child with your decision.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ApprovalDecisionInput::class,
        ]);
    }
}
