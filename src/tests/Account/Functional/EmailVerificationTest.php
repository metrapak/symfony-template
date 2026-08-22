<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Enum\UserRole;
use App\Account\Service\EmailVerificationService;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelper;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerificationTest extends AccountWebTestCase
{
    private const CONFIRMATION = 'a new link is on its way';

    public function testValidLinkMarksTheAccountVerified(): void
    {
        $user = $this->createUser('player@example.com', UserRole::Player, verified: false);

        $this->client->request('GET', $this->issueLinkFor('player@example.com'));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->reloadUser('player@example.com')->isEmailVerified());
        self::assertNotNull($user->getId());
    }

    /**
     * A signed URL stays valid until it expires even after it has been used, so a second
     * click — a prefetching mail client, a user pressing back — must be a no-op, not an error.
     */
    public function testClickingTheSameLinkTwiceSucceedsBothTimes(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);
        $link = $this->issueLinkFor('player@example.com');

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();

        self::assertTrue($this->reloadUser('player@example.com')->isEmailVerified());
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);
        $link = $this->issueLinkFor('player@example.com');

        $this->client->request('GET', $link . 'tampered');

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->reloadUser('player@example.com')->isEmailVerified());
    }

    public function testLinkWithoutASignatureIsRejected(): void
    {
        $user = $this->createUser('player@example.com', UserRole::Player, verified: false);

        $this->client->request('GET', '/verify/email?id=' . $user->getId());

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->reloadUser('player@example.com')->isEmailVerified());
    }

    public function testExpiredLinkIsRejected(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);

        $link = $this->issueLinkFor('player@example.com', $this->helperWithLifetime(-1));

        $this->client->request('GET', $link);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->reloadUser('player@example.com')->isEmailVerified());
    }

    public function testTheConfiguredLinkLifetimeIsTwentyFourHours(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);
        $user = $this->reloadUser('player@example.com');

        $components = $this->helper()->generateSignature(
            EmailVerificationService::VERIFY_ROUTE,
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()],
        );

        // BR-003. Asserted against the configuration rather than by advancing a clock: the
        // bundle reads the wall clock via time(), which symfony/clock cannot mock.
        self::assertSame(86400, $components->getExpiresAt()->getTimestamp() - time());
    }

    /**
     * The signature binds the email address, so a link issued before an address change
     * must stop working.
     */
    public function testChangingTheEmailInvalidatesAnAlreadyIssuedLink(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);
        $link = $this->issueLinkFor('player@example.com');

        // Reload first: issueLinkFor() clears the entity manager, so the instance from
        // createUser() is detached by now and flushing it would be a silent no-op.
        $entityManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $moved = $this->reloadUser('player@example.com');
        $moved->setEmail('moved@example.com');
        $entityManager->flush();

        $this->client->request('GET', $link);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->reloadUser('moved@example.com')->isEmailVerified());
    }

    public function testUnknownUserIdIsRejected(): void
    {
        $this->client->request('GET', '/verify/email?id=999999');

        self::assertResponseStatusCodeSame(400);
    }

    public function testVerifyingUnblocksLoginForAPlayer(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);

        $this->submitLogin('player@example.com');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', $this->issueLinkFor('player@example.com'));
        self::assertResponseIsSuccessful();

        $this->submitLogin('player@example.com');
        self::assertResponseRedirects('/dashboard');
    }

    // FR-005 — resend, reachable while anonymous because that is who needs it.

    public function testResendSendsANewLinkForAnUnverifiedAccount(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);

        $this->submitResend('player@example.com');
        self::assertEmailCount(1);

        $this->client->request('GET', $this->extractVerifyPath($this->getMailerMessage()));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->reloadUser('player@example.com')->isEmailVerified());
    }

    public function testResendForAnUnknownAddressIsIndistinguishableAndSendsNoMail(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);

        $this->submitResend('player@example.com');
        self::assertEmailCount(1);
        $knownBody = $this->client->followRedirect()->filter('body')->text();

        $this->submitResend('nobody@example.com');
        self::assertEmailCount(0);
        $unknownBody = $this->client->followRedirect()->filter('body')->text();

        self::assertStringContainsString(self::CONFIRMATION, $knownBody);
        self::assertSame($knownBody, $unknownBody);
    }

    public function testResendForAnAlreadyVerifiedAddressSendsNoMail(): void
    {
        $this->createUser('player@example.com', UserRole::Player);

        $this->submitResend('player@example.com');

        self::assertEmailCount(0);
    }

    /**
     * The endpoint is anonymous and mails whatever address it is handed, so it must stop
     * before it becomes a way to flood someone's inbox.
     */
    public function testResendIsRateLimited(): void
    {
        $this->createUser('player@example.com', UserRole::Player, verified: false);

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->submitResend('player@example.com');
            self::assertEmailCount(1, null, \sprintf('Attempt %d should still send.', $attempt));
        }

        $this->submitResend('player@example.com');

        self::assertEmailCount(0);
        self::assertStringContainsString('Too many requests', $this->client->followRedirect()->filter('body')->text());
    }

    private function submitResend(string $email): void
    {
        $this->client->request('GET', '/verify/resend');
        $this->client->submitForm('Send confirmation link', ['resend_verification_form[email]' => $email]);
    }

    /**
     * Generates a link the same way the application does, without going through the mailbox.
     */
    private function issueLinkFor(string $email, ?VerifyEmailHelperInterface $helper = null): string
    {
        $user = $this->reloadUser($email);

        $components = ($helper ?? $this->helper())
            ->generateSignature(
                EmailVerificationService::VERIFY_ROUTE,
                (string) $user->getId(),
                $user->getEmail(),
                ['id' => $user->getId()],
            );

        return $components->getSignedUrl();
    }

    private function helper(): VerifyEmailHelperInterface
    {
        return static::getContainer()->get('symfonycasts.verify_email.helper');
    }

    /**
     * Builds a helper that signs links with the given lifetime, so an already-expired but
     * correctly signed URL can be produced. The bundle compares against time() rather than
     * symfony/clock, so advancing a mocked clock has no effect on its expiry check.
     */
    private function helperWithLifetime(int $lifetime): VerifyEmailHelperInterface
    {
        $configured = $this->helper();
        $read = static function (string $property) use ($configured): mixed {
            $reflection = new \ReflectionProperty(VerifyEmailHelper::class, $property);

            return $reflection->getValue($configured);
        };

        return new VerifyEmailHelper(
            $read('router'),
            $read('uriSigner'),
            $read('queryUtility'),
            $read('tokenGenerator'),
            $lifetime,
        );
    }

    private function extractVerifyPath(Email $email): string
    {
        self::assertSame(1, preg_match('#https?://[^"\s<]*/verify/email\?[^"\s<]+#', $email->getTextBody(), $matches));

        return $matches[0];
    }
}
