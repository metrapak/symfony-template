<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Service;

use App\Account\Service\TemporaryPasswordGenerator;
use App\Shared\Domain\Validator\Constraint\PasswordRequirements;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * FR-022 — the credential a new trainer is emailed.
 */
final class TemporaryPasswordGeneratorTest extends TestCase
{
    /**
     * The generated password is handed straight to the forced change-password form. One that
     * could fail its own validation would strand a trainer at their first sign-in with no way
     * forward, so this is asserted against the real constraint rather than a copy of its rules.
     *
     * NotCompromisedPassword is excluded: it calls an external API, and a value drawn from
     * random_bytes is not in a breach corpus.
     */
    public function testEveryGeneratedPasswordSatisfiesThePasswordPolicy(): void
    {
        $validator = Validation::createValidator();
        $generator = new TemporaryPasswordGenerator();

        for ($i = 0; $i < 200; ++$i) {
            $password = $generator->generate();

            $violations = $validator->validate($password, [
                new Assert\NotBlank(),
                new Assert\Length(min: 8, max: 255),
                new Assert\Type('string'),
                new Assert\Regex('/[A-Z]+/'),
            ]);

            self::assertCount(0, $violations, \sprintf('"%s" does not satisfy the password policy.', $password));
        }
    }

    public function testThePolicyItValidatesAgainstIsTheOneTheApplicationUses(): void
    {
        // Guards the assertion above: if PasswordRequirements gains a rule, this fails and
        // the generator has to be revisited rather than silently drifting out of compliance.
        $constraints = (new \ReflectionMethod(PasswordRequirements::class, 'getConstraints'))
            ->invoke(new PasswordRequirements(), []);

        self::assertCount(5, $constraints, 'PasswordRequirements changed; revisit TemporaryPasswordGenerator.');
    }

    public function testPasswordsAreLongEnoughToResistGuessingAndDoNotRepeat(): void
    {
        $generator = new TemporaryPasswordGenerator();

        $generated = [];
        for ($i = 0; $i < 200; ++$i) {
            $password = $generator->generate();
            self::assertSame(TemporaryPasswordGenerator::LENGTH, \strlen($password));
            $generated[$password] = true;
        }

        self::assertCount(200, $generated, 'The generator repeated a password within 200 draws.');
    }

    /**
     * The credential is frequently read aloud or copied from a printout, so the alphabet
     * excludes the glyph pairs that get transcribed wrongly.
     */
    public function testAmbiguousCharactersAreExcluded(): void
    {
        $generator = new TemporaryPasswordGenerator();

        for ($i = 0; $i < 200; ++$i) {
            $password = $generator->generate();

            foreach (['0', 'O', '1', 'l', 'I'] as $ambiguous) {
                self::assertStringNotContainsString($ambiguous, $password);
            }
        }
    }

    /**
     * Each required character class must be present in every draw, not merely on average.
     */
    public function testEveryDrawContainsEachRequiredCharacterClass(): void
    {
        $generator = new TemporaryPasswordGenerator();

        for ($i = 0; $i < 200; ++$i) {
            $password = $generator->generate();

            self::assertMatchesRegularExpression('/[A-Z]/', $password);
            self::assertMatchesRegularExpression('/[a-z]/', $password);
            self::assertMatchesRegularExpression('/[0-9]/', $password);
        }
    }

    /**
     * A generator that always placed the uppercase character first would satisfy every rule
     * above while leaking a third of the search space.
     */
    public function testTheRequiredCharactersAreNotAlwaysInTheSamePositions(): void
    {
        $generator = new TemporaryPasswordGenerator();

        $firstCharacterClasses = [];
        for ($i = 0; $i < 200; ++$i) {
            $firstCharacterClasses[ctype_upper($generator->generate()[0]) ? 'upper' : 'other'] = true;
        }

        self::assertCount(2, $firstCharacterClasses);
    }
}
