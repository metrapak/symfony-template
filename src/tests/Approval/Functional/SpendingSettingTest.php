<?php

declare(strict_types=1);

namespace App\Tests\Approval\Functional;

use App\Account\Entity\AuditLogEntry;
use App\Account\Enum\AuditAction;
use App\Approval\Entity\ChildSpendingSetting;
use App\Approval\Enum\ApprovalStatus;
use App\Profile\Entity\PlayerProfile;

/**
 * The per-child token-spending setting (FR-092, BR-091, BR-096, G-32).
 */
final class SpendingSettingTest extends ApprovalWebTestCase
{
    /**
     * BR-091's default is the *absence* of a decision, not a row saying "off". Asserting on the
     * table is the point: a row created at profile creation would claim a parent chose this.
     */
    public function testANewChildHasNoSettingRowAndNeedsApproval(): void
    {
        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/spending');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Needs your approval');
        self::assertSelectorTextContains('main', '(not set)');
        self::assertSame([], $this->settings());
    }

    public function testAParentTurnsTokenSpendingOn(): void
    {
        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitSetting($this->child, true);

        self::assertResponseRedirects('/family/spending');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'can now spend tokens without asking you first');

        $settings = $this->settings();
        self::assertCount(1, $settings);
        self::assertTrue($settings[0]->allowsTokenSpendingWithoutApproval());
        self::assertSame($this->parentUser->getId(), $settings[0]->getUpdatedBy()?->getId());
    }

    /**
     * FR-092: changeable at any time, in both directions, without a second row appearing.
     */
    public function testAParentTurnsItBackOffAgain(): void
    {
        $this->seedSpendingSetting($this->child, true);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitSetting($this->child, false);

        $settings = $this->settings();
        self::assertCount(1, $settings, 'the unique index holds: one row per child');
        self::assertFalse($settings[0]->allowsTokenSpendingWithoutApproval());
    }

    /**
     * BR-096: per child, not per family. A second child is untouched by the first one's waiver.
     */
    public function testTheSettingAppliesToOneChildOnly(): void
    {
        $sibling = $this->createChildProfile($this->parentUser, 'Mateo Parent');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitSetting($this->child, true);

        $this->client->request('GET', '/family/spending');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Allowed');
        self::assertSelectorTextContains('main', 'Needs your approval');

        // And the sibling's own checkout still asks.
        $siblingAccount = $this->createUser('mateo@children.invalid', name: 'Mateo Parent');
        $this->attachLogin($sibling, $siblingAccount);

        $this->submitLogin('mateo@children.invalid');
        $this->submitCheckout($sibling, [
            'purchaseDescription' => 'Skills session',
            'paymentType' => 'token',
            'amount' => '3',
        ]);

        self::assertSame(ApprovalStatus::Pending, $this->purchases()[0]->getStatus());
    }

    /**
     * G-32: no requirement says what should happen to a request already waiting when the rule
     * changes under it, and silently approving one would mean a settings change spending money.
     */
    public function testTurningTheSettingOnDoesNotDecideAPendingRequest(): void
    {
        $purchase = $this->seedPendingRequest(paymentType: \App\Approval\Enum\PaymentType::Token);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitSetting($this->child, true);

        self::assertSame(ApprovalStatus::Pending, $this->reloadPurchase((int) $purchase->getId())->getStatus());
        self::assertSame([], $this->paymentInstructions());
    }

    /**
     * NFR-X02: this is the control that decides whether a child can spend unasked, so who
     * relaxed it and when is exactly what an audit log is for.
     */
    public function testAChangeIsAudited(): void
    {
        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitSetting($this->child, true);

        $entries = $this->freshEntityManager()->getRepository(AuditLogEntry::class)->findBy(
            ['action' => AuditAction::ChildTokenSpendingSettingChanged],
        );

        self::assertCount(1, $entries);
        self::assertSame($this->parentUser->getId(), $entries[0]->getActor()?->getId());
        self::assertSame('PlayerProfile', $entries[0]->getSubjectType());
        self::assertSame($this->child->getId(), $entries[0]->getSubjectId());
        self::assertTrue($entries[0]->getPayload()['allow_token_spending_without_approval'] ?? null);
    }

    /**
     * BR-094 again, on the other screen: holding the capability says nothing about whose child
     * this is.
     */
    public function testAParentCannotChangeAnotherFamilysSetting(): void
    {
        $otherParent = $this->createParent(self::OTHER_PARENT_EMAIL, 'Sam Other');
        $theirChild = $this->createChildProfile($otherParent, 'Their Child');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/spending', $theirChild->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The screen tells the parent the rule the checkbox cannot express (BR-090).
     */
    public function testTheScreenSaysDollarsAlwaysNeedApproval(): void
    {
        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/spending', $this->child->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Dollar purchases always need your approval');
    }

    /**
     * @return list<ChildSpendingSetting>
     */
    private function settings(): array
    {
        return $this->freshEntityManager()
            ->getRepository(ChildSpendingSetting::class)
            ->findBy([], ['id' => 'ASC']);
    }

    private function submitSetting(PlayerProfile $child, bool $allow): void
    {
        $this->submitFormPayload(
            \sprintf('/family/children/%d/spending', $child->getId()),
            self::SPENDING_FORM,
            $allow ? ['allowTokenSpendingWithoutApproval' => '1'] : [],
        );
    }

    /**
     * Gives an existing child profile a login.
     *
     * `MembershipWebTestCase::createChildProfile()` can do this at creation time; a sibling
     * created earlier in a test needs it afterwards, and `attachLogin()` is the entity's own
     * guarded way in.
     */
    private function attachLogin(PlayerProfile $child, \App\Account\Entity\User $account): void
    {
        $managed = $this->managed($child, PlayerProfile::class);
        $managed->attachLogin($this->managed($account, \App\Account\Entity\User::class), new \DateTimeImmutable());

        $this->currentEntityManager()->flush();
    }
}
