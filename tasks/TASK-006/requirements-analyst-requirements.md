# TASK-006: Child Purchase Approval Workflow Requirements

## Overview

Parental control over child spending: a request/approve/deny state machine with a 48-hour expiry,
notifications to the parent, and a per-child token-spending setting.

Per scoping decision **D-04**, the workflow is built now and payment execution sits behind a port
interface with a fake implementation until Epic-05 (Payments) exists. Nothing in this task processes
real money.

## Source

- `specs/Epic-01_User_Management_Authentication_SPEC.md` — US-01.05; §8 (Child Purchase Approvals), §9 (Child Purchase Approval Workflow), §10 Flow 3
- Depends on **TASK-004** (child profiles, child logins, family relationships)
- Deferred integration: **Epic-05** (payment execution), **Epic-02** (events being purchased)

## Functional Requirements

### FR-090: Child Checkout Creates an Approval Request
- **Acceptance**: When a child account reaches checkout for an event, the system detects the child account and creates a request with status "Pending Parent Approval" instead of completing the purchase. The child sees that status on the reservation.
- **Priority**: High

### FR-091: USD Payments Always Require Approval
- **Acceptance**: A USD purchase by a child always requires parent approval, with no setting able to bypass it.
- **Priority**: High

### FR-092: Token Spending — Per-Child Setting
- **Acceptance**: Each child has a parent-controlled setting "Allow token spending without approval", **default OFF**. With it OFF, token spending follows the same approval workflow as USD. With it ON, the token payment is processed immediately, the child is registered instantly, and the parent receives an **informational** notification rather than an approval request. The setting is per child and changeable at any time from the child's profile settings.
- **Priority**: High

### FR-093: Parent Notification
- **Acceptance**: When approval is needed the parent receives both an email and an in-app notification. The notification identifies the child, the event, the amount, and the payment type.
- **Priority**: High

### FR-094: Parent Reviews Pending Requests
- **Acceptance**: The parent sees pending requests in a Payments or Reservations section, showing event details, cost, and date/time. Actions available: **Approve**, **Deny**, **Request more info**. The parent may attach notes to any action.
- **Priority**: High
- **Gap**: "Request more info" has no defined recipient, message channel, or resulting state — see G-31.

### FR-095: Approval Outcome
- **Acceptance**: On approval the payment is processed and the child is registered for the event; the child sees the status change from Pending to Confirmed. On denial the child is notified and no payment occurs.
- **Priority**: High

### FR-096: 48-Hour Expiry
- **Acceptance**: A request with no parent action expires 48 hours after creation, auto-denies, and notifies. Expiry runs reliably without an operator present.
- **Priority**: High

### FR-097: Payment Execution Behind a Port
- **Acceptance**: Approval calls a `PaymentProcessor` interface. A fake implementation records the intent and succeeds, so the workflow is fully testable before Epic-05. Swapping in the real processor requires no change to the workflow code.
- **Priority**: High
- **Source**: Decision D-04, not the spec.

### FR-098: Approval Audit Trail
- **Acceptance**: Each request records the child profile, the approving parent, the event/purchase reference, the amount, the payment type, the status, the request timestamp, the response timestamp, the expiry timestamp, and the parent's notes.
- **Priority**: High

### FR-099: Child Cannot Self-Approve
- **Acceptance**: A child account cannot approve, deny, or expire any request, including their own, and cannot alter the token-spending setting. Direct attempts return 403.
- **Priority**: High
- **Note**: Implied by US-01.06's prohibitions but never stated as an acceptance criterion. Added because it is the single most obvious way to defeat this feature.

## Non-Functional Requirements

| ID | Requirement | Metric |
|:---|:------------|:-------|
| NFR-090 | Approval action to child-visible status change | < 1 second |
| NFR-091 | Expiry timeliness | Expires within a bounded window of the 48-hour mark, not "next time someone looks" |
| NFR-092 | Idempotency | Double-submitting an approval must not process a payment twice |
| NFR-093 | Notification reliability | Approval requests must not be silently lost if mail delivery fails |
| NFR-094 | Accessibility | WCAG 2.1 AA — approve/deny are distinct, clearly labelled actions, never colour-only; amounts read correctly by screen readers |

## Business Rules

- **BR-090** USD payments by a child always require parent approval.
- **BR-091** Token spending requires approval by default; a parent may waive it per child.
- **BR-092** Requests expire after 48 hours with an automatic denial.
- **BR-093** A parent may add notes when approving or denying.
- **BR-094** Only the child's own parent may act on a request.
- **BR-095** A child can never approve any request.
- **BR-096** The token-spending setting is per child, not per family.

## Task Breakdown

### Entities

| Entity | Properties | Relations |
|:-------|:-----------|:----------|
| `PurchaseApprovalRequest` | `childProfileId`, `parentUserId`, `purchaseReference`, `amount`, `currency`, `paymentType` (usd/token), `status`, `requestedAt`, `respondedAt`, `expiresAt`, `parentNotes` | belongs to PlayerProfile + parent User |
| `ChildSpendingSetting` | `childProfileId`, `allowTokenSpendingWithoutApproval` (default false), `updatedAt`, `updatedByUserId` | belongs to PlayerProfile |

`purchaseReference` is deliberately a loose reference rather than an FK: neither events (Epic-02) nor
payments (Epic-05) exist yet. Add real foreign keys when those tables land.

Status values: `pending`, `approved`, `denied`, `expired`, and — pending G-31 — possibly `info_requested`.

### Services

| Service | Purpose | Methods |
|:--------|:--------|:--------|
| `ApprovalRequestFactory` | Decide whether a child purchase needs approval | `createIfRequired` |
| `ApprovalWorkflow` | State machine with legal transitions only | `approve`, `deny`, `expire` |
| `ApprovalExpiryHandler` | Sweep or scheduled expiry | `expireDue` |
| `SpendingSettingService` | Read/write the per-child setting | `get`, `update` |
| `PaymentProcessor` (**interface**) | Payment execution port | `process` |
| `FakePaymentProcessor` | Stand-in until Epic-05 | `process` |
| `ApprovalNotifier` | Parent and child notifications | `notifyParentApprovalNeeded`, `notifyParentInformational`, `notifyChildOutcome` |

### Controllers

| Controller | Endpoints | Purpose |
|:-----------|:----------|:--------|
| `Family\ApprovalController` | `GET /family/approvals`, `POST /family/approvals/{id}/approve`, `/deny` | Parent review and action |
| `Family\SpendingSettingController` | `GET/POST /family/children/{id}/spending` | Per-child token setting |
| `Child\ReservationStatusController` | `GET /reservations` | Child-visible request statuses |

### Backend Tasks
- [ ] Migration: CREATE `purchase_approval_request` (index on `(parent_user_id, status)` and on `(status, expires_at)` for the expiry sweep), `child_spending_setting` (unique on `child_profile_id`)
- [ ] Entities above; `ApprovalStatus` and `PaymentType` backed enums
- [ ] Value object: `Money` (amount + currency, integer minor units — never float)
- [ ] State machine: enumerate legal transitions and reject the rest (`pending → approved|denied|expired` only); consider Symfony Workflow
- [ ] Request DTO + validator: `ApprovalDecisionRequest` (notes optional, length-capped), `SpendingSettingRequest`
- [ ] Voter: `ApprovalVoter` — only the child's own parent may act (BR-094); children denied outright (FR-099)
- [ ] `PaymentProcessor` interface + `FakePaymentProcessor` with a recorded call log for assertions
- [ ] Idempotency guard on approval — optimistic lock or a status-conditional UPDATE so a double POST processes once (NFR-092)
- [ ] Messenger: `ExpireApprovalRequest` message; scheduled dispatch (Symfony Scheduler or cron) for NFR-091
- [ ] Mailer: approval-needed, informational-token-spend, approved, denied, expired templates
- [ ] In-app notification storage and an unread indicator (no notification system exists yet — this may be net-new infrastructure; see G-33)
- [ ] Services above + DI wiring
- [ ] Repository: `PurchaseApprovalRequestRepository::pendingForParent`, `::dueForExpiry`
- [ ] Fixtures: pending, approved, denied, and near-expiry requests; one child with token approval waived

### Frontend Tasks (server-rendered)
- [ ] Templates: parent pending-approvals list, request detail with approve/deny/notes form, per-child spending setting form, child-facing reservation status list, in-app notification indicator partial
- [ ] Progressive enhancement: notes field revealed on action selection; countdown showing time remaining before expiry; confirmation on deny
- [ ] Accessibility: approve and deny as separate submit buttons with descriptive accessible names including child and event; status conveyed textually; amounts marked up so currency is announced; countdown not the only expiry indicator
- [ ] Responsive: approval cards stack on narrow screens with the amount always visible

### Testing Tasks
- [ ] Integration: child checkout in USD creates a pending request and does not process payment
- [ ] Integration: token spend with the setting OFF creates a pending request
- [ ] Integration: token spend with the setting ON processes immediately and sends an informational notification only
- [ ] Integration: parent approves → fake processor called once → child status becomes Confirmed
- [ ] Integration: parent denies → no processor call → child notified
- [ ] Integration: double-submitted approval processes payment exactly once (NFR-092)
- [ ] Integration: a different parent cannot act on the request (403)
- [ ] Integration: the child cannot approve, deny, or change the setting (403) (FR-099)
- [ ] Integration: expiry at the 48-hour boundary auto-denies and notifies; a request at 47:59 is untouched
- [ ] Integration: illegal transitions rejected (approving an already-denied or expired request)
- [ ] Integration: default setting for a newly created child is OFF (BR-091)
- [ ] Unit: `Money` arithmetic and currency mismatch; state machine transition table; `ApprovalRequestFactory` decision matrix across payment type × setting × account kind
- [ ] Messenger: expiry message handled idempotently; re-delivery does not double-notify
- [ ] Browser/E2E: child requests, parent approves, child sees Confirmed

## Validation Completeness

- [x] All functional requirements mapped to tasks
- [x] Happy path covered
- [x] Error cases identified (wrong parent, child self-approval, illegal transition, double submit)
- [x] Edge cases considered (expiry boundary, setting changed while a request is pending, child with no parent link, deactivated parent, zero amount)
- [x] Security requirements addressed (ownership voter, child prohibition, no float money)
- [x] Performance requirements noted (NFR-090, NFR-091)
- [x] Testing strategy defined

## Gap Analysis

- [ ] **G-31 (new)** — "Request more info" (US-01.05 acceptance criteria) has no defined behaviour: no recipient, no message channel, no resulting status, no effect on the 48-hour clock. Either specify it or cut it from MVP. Currently it cannot be implemented or tested.
- [ ] **G-09** — US-01.06 says child RSVP and RSVP **cancellation** require parent approval, but this workflow is defined only for payments. Free-event RSVPs and cancellations are unspecified: same workflow, same expiry, or auto-approved? A cancellation that expires after 48 hours is nonsensical if the event has already happened.
- [ ] **G-32 (new)** — What happens to a pending request when the parent flips the token setting to ON, or when the underlying event is cancelled, fills up, or changes price before approval? No requirement covers a stale request.
- [ ] **G-33 (new)** — "In-app notification" is required (FR-093) but no notification system exists anywhere in Epic-01 or the codebase. This is unscoped net-new infrastructure. Either specify it as its own deliverable or reduce MVP to email-only.
- [ ] **G-34 (new)** — Expiry auto-denies after 48 hours, but the spec never says whether the child can resubmit the same purchase afterwards, or whether repeated requests are rate-limited.
- [ ] **G-35 (new)** — Token balances belong to Epic-05, so "child views tokens (view-only)" and any balance check at request time have no data source yet. The workflow must tolerate an unknown balance until then.
- [ ] **Q-01.04 (P1)** — The five email templates listed above are inferred, not specified.
- [ ] Multi-parent families are not modelled anywhere in the epic: `parentUserId` is singular, so a second guardian can neither see nor act on approvals. Likely a real-world requirement and worth raising with the client.
- [ ] Currency is assumed USD throughout ("USD Payments"). `Money` carries a currency anyway; confirm no multi-currency requirement is coming.

## Next Steps (Suggested)

Do not auto-execute — see the epic index for the full ordering.
