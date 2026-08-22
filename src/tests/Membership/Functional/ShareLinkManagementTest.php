<?php

declare(strict_types=1);

namespace App\Tests\Membership\Functional;

use App\Account\Enum\UserRole;
use App\Membership\Entity\ShareLink;
use App\Membership\ValueObject\ShareLinkCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-040 and FR-049 — managing player links, and how the public endpoint answers everything
 * that is not a usable code.
 */
final class ShareLinkManagementTest extends MembershipWebTestCase
{
    public function testATrainerGeneratesViewsAndDeactivatesAPlayerLink(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);

        $this->client->request('GET', '/trainer/share-links');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('td', 'You have no player links yet.');

        $this->client->submitForm('Generate a new link');
        self::assertResponseRedirects('/trainer/share-links');

        $link = $this->onlyLink();
        self::assertTrue($link->isActive());
        self::assertNull($link->getExpiresAt(), 'BR-040: a player link never expires.');
        self::assertNull($link->getMaxUses(), 'BR-040: a player link has unlimited uses.');

        $crawler = $this->client->request('GET', '/trainer/share-links');
        self::assertStringContainsString('/join/' . $link->getCode(), $crawler->filter('code')->text());

        $this->client->submitForm('Deactivate');
        self::assertResponseRedirects('/trainer/share-links');
        self::assertFalse($this->reloadLink((int) $link->getId())->isActive());
    }

    /**
     * G-19: withdrawing a link does not expel the players who joined through it.
     */
    public function testDeactivatingALinkLeavesExistingAssociationsInPlace(): void
    {
        $player = $this->createUser('pat@example.com', name: 'Pat Player');
        $profile = $this->createSelfProfile($player);
        $link = $this->createPlayerLink();
        $this->createAssociation($profile, $link);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/share-links');
        $this->client->submitForm('Deactivate');

        $associations = $this->associations();
        self::assertCount(1, $associations);
        self::assertTrue($associations[0]->isActive());
    }

    /**
     * NFR-X01 / IDOR: `ROLE_TRAINER` is held by every trainer, so the id in the URL has to be
     * authorized against the tenant, not only against the role.
     */
    public function testATrainerCannotDeactivateAnotherTrainersLink(): void
    {
        $otherTrainer = $this->createUser('other-trainer@example.com', UserRole::Trainer, name: 'Otto Trainer');
        $otherOrganization = $this->createOrganizationFor($otherTrainer, 'Southside Sports');
        $foreignLink = $this->createPlayerLink($otherOrganization, $otherTrainer);

        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->client->request('GET', '/trainer/share-links');

        $this->client->request('POST', '/trainer/share-links/' . $foreignLink->getId() . '/deactivate', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertTrue($this->reloadLink((int) $foreignLink->getId())->isActive());
    }

    public function testATrainerOnlySeesTheirOwnLinks(): void
    {
        $otherTrainer = $this->createUser('other-trainer@example.com', UserRole::Trainer, name: 'Otto Trainer');
        $otherOrganization = $this->createOrganizationFor($otherTrainer, 'Southside Sports');
        $foreignLink = $this->createPlayerLink($otherOrganization, $otherTrainer);
        $ownLink = $this->createPlayerLink();

        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->client->request('GET', '/trainer/share-links');

        self::assertStringContainsString($ownLink->getCode(), $crawler->filter('table')->text());
        self::assertStringNotContainsString($foreignLink->getCode(), $crawler->filter('table')->text());
    }

    public function testAPlayerCannotReachTheTrainerTools(): void
    {
        $this->createUser('pat@example.com', name: 'Pat Player');
        $this->submitLogin('pat@example.com');

        $this->client->request('GET', '/trainer/share-links');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * FR-049 — unknown, deactivated and consumed codes must be one answer. The bodies are
     * compared byte for byte: a difference anywhere is a way to tell them apart.
     */
    public function testUnknownDeactivatedAndConsumedCodesAreIndistinguishable(): void
    {
        $unknown = $this->fetchBody('/join/' . ShareLinkCode::generate()->value);

        $deactivated = $this->createPlayerLink();
        $deactivated->deactivate(new \DateTimeImmutable());
        $this->currentEntityManager()->flush();

        $consumed = $this->createCoachLink('spent@example.com');
        (new \ReflectionProperty(ShareLink::class, 'useCount'))->setValue($consumed, 1);
        $this->currentEntityManager()->flush();

        $malformed = $this->fetchBody('/join/not-a-real-code');

        self::assertSame($unknown, $this->fetchBody('/join/' . $deactivated->getCode()));
        self::assertSame($unknown, $this->fetchBody('/join/' . $consumed->getCode()));
        self::assertSame($unknown, $malformed);
    }

    /**
     * FR-049 — the public endpoint is rate limited per client. The limit is a budget for a
     * scanner, not for a person: a visitor opening their link a handful of times never sees it.
     */
    public function testRepeatedRequestsToJoinAreThrottled(): void
    {
        $link = $this->createPlayerLink();

        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $this->client->request('GET', '/join/' . $link->getCode());
            self::assertResponseIsSuccessful();
        }

        $this->client->request('GET', '/join/' . $link->getCode());

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSelectorTextContains('h1', 'Too many requests');
    }

    private function fetchBody(string $path): string
    {
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        return (string) $this->client->getResponse()->getContent();
    }

    private function onlyLink(): ShareLink
    {
        $links = $this->freshEntityManager()->getRepository(ShareLink::class)->findAll();
        self::assertCount(1, $links);

        return $links[0];
    }
}
