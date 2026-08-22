<?php

declare(strict_types=1);

namespace App\Approval\Form;

use App\Approval\Dto\CheckoutInput;
use App\Approval\Enum\PaymentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The stand-in checkout (FR-090, FR-091, FR-092).
 *
 * Epic-02 replaces this screen with one that selects an event and reads its price; see
 * `CheckoutInput` for why the amount is typed in until then.
 *
 * The payment type is expanded radio buttons rather than a select, because it is the field that
 * decides whether a parent is asked at all: two visible options with their consequences spelled
 * out beat a closed list where "Tokens" and "US dollars" look interchangeable.
 */
final class CheckoutFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('purchaseDescription', TextType::class, [
                'label' => 'What are you buying?',
                'help' => 'For example: City Cup entry fee.',
            ])
            ->add('paymentType', EnumType::class, [
                'label' => 'Pay with',
                'class' => PaymentType::class,
                'choice_label' => static fn (PaymentType $type): string => $type->label(),
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('amount', TextType::class, [
                'label' => 'Amount',
                // Not `NumberType`: the value is parsed into integer minor units from its text,
                // and a number field would hand it over as a float first.
                'help' => 'Dollars may have cents (45.00). Tokens are whole numbers (12).',
                'attr' => ['inputmode' => 'decimal'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CheckoutInput::class,
        ]);
    }
}
