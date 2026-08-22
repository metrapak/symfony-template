<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Dto\DeleteUserInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-025 / FR-027 — the reason that becomes the compliance record.
 *
 * A form rather than a bare CSRF-protected button, because the reason is required and has to
 * be validated and re-rendered with its error like any other input.
 */
final class DeleteUserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextareaType::class, [
            'label' => 'Reason for deletion',
            'help' => 'Recorded permanently for legal compliance. Name the request or the ticket it came from.',
            'attr' => ['rows' => 3],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeleteUserInput::class,
        ]);
    }
}
