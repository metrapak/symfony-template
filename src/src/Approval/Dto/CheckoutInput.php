<?php

declare(strict_types=1);

namespace App\Approval\Dto;

use App\Approval\Enum\PaymentType;
use App\Approval\ValueObject\Money;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A purchase somebody is trying to make (FR-090, FR-091, FR-092).
 *
 * **The amount is typed in, and that is a stand-in, not a design.** Epic-02 owns events and their
 * prices; until it exists there is nothing with a price to select, so the screen that exercises
 * this workflow asks for one. Nothing here moves real money — `FakePaymentProcessor` is what
 * ships (FR-097) — and when the event catalogue lands the price comes from the event and this
 * field disappears. Until then, a checkout that could not name an amount could not exercise
 * FR-091 at all.
 *
 * The amount is a *string*, deliberately: `4.10` typed into a float field is not 4.10, and the
 * whole point of `Money` is that a parent approves the number they read. It is parsed into
 * integer minor units once, here, and never becomes a float on the way.
 */
final class CheckoutInput
{
    #[Assert\NotBlank(message: 'Say what this purchase is for.')]
    #[Assert\Length(max: 255)]
    public ?string $purchaseDescription = null;

    #[Assert\NotNull(message: 'Choose how this is being paid for.')]
    public ?PaymentType $paymentType = null;

    #[Assert\NotBlank(message: 'Enter an amount.')]
    #[Assert\Regex(
        pattern: '/^\d{1,7}(\.\d{1,2})?$/',
        message: 'Enter an amount as a number, for example 45 or 45.00.',
    )]
    public ?string $amount = null;

    /**
     * Tokens are whole and dollars are not, so the same field means two things and only the
     * payment type says which. Checked here rather than in the form because it is a rule about
     * the pair, and a field-level constraint cannot see its neighbour.
     */
    #[Assert\Callback]
    public function validateAmountAgainstPaymentType(ExecutionContextInterface $context): void
    {
        if (null === $this->paymentType || null === $this->amount || '' === $this->amount) {
            return;
        }

        if (PaymentType::Token === $this->paymentType && str_contains($this->amount, '.')) {
            $context->buildViolation('Tokens are whole numbers — enter 12, not 12.50.')
                ->atPath('amount')
                ->addViolation();

            return;
        }

        if (1 !== preg_match('/^\d{1,7}(\.\d{1,2})?$/', $this->amount)) {
            return;
        }

        if ($this->toMoney()->isZero()) {
            $context->buildViolation('A purchase has to cost something.')
                ->atPath('amount')
                ->addViolation();
        }
    }

    /**
     * The amount as integer minor units in the currency its payment type implies.
     *
     * @throws \LogicException when called before validation has established both fields
     */
    public function toMoney(): Money
    {
        $type = $this->paymentType ?? throw new \LogicException('A validated checkout always has a payment type.');
        $amount = $this->amount ?? throw new \LogicException('A validated checkout always has an amount.');

        $currency = Money::currencyFor($type);
        $scale = Money::scaleOf($currency);

        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        // String padding rather than multiplication: `(int) (45.10 * 100)` is 4509 on a binary
        // float, and this is the one place in the workflow where that would become a real amount.
        $minor = $units . str_pad($fraction, $scale, '0');

        return Money::of((int) $minor, $currency);
    }

    public function requireDescription(): string
    {
        return trim($this->purchaseDescription ?? throw new \LogicException('A validated checkout always has a description.'));
    }

    public function requirePaymentType(): PaymentType
    {
        return $this->paymentType ?? throw new \LogicException('A validated checkout always has a payment type.');
    }
}
