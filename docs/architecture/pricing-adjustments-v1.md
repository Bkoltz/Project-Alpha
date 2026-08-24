# Pricing adjustments v1

Status: accepted foundation; application remains default-off.

## Decision

Existing one-off document `discount_type` and `discount_value` fields remain
unchanged. Reusable inherited pricing is a separate, generic pricing-adjustment
domain. Version 1 supports explicitly assigned percentage discounts only; it
is not a cadence, commitment, promotion, or settlement rules engine.

Resolution order is:

1. explicit document override (`adjustment` or `none`);
2. contract assignment;
3. project assignment;
4. no inherited adjustment.

Inactive, out-of-date, missing, and cross-organization definitions fail closed.
Definitions and assignments do nothing unless an installation explicitly
enables `pricing_adjustments_enabled`. Migration 0072 creates no assignment and
performs no historical backfill.

Migration 0073 corrects the reusable-definition scope to match Project Alpha's
data model: `organizations` are customer records, not application tenants.
Definitions are therefore either installation-wide Settings templates or
explicitly customer-scoped templates. Installation templates may be assigned
to any customer's project or contract; customer templates may only be assigned
inside that customer. A deterministic scope key enforces name uniqueness per
scope, and existing 0072 definitions are backfilled as customer scoped without
changing assignments or document prices.

## Basis and exact calculation

The inherited percentage is calculated against the document subtotal before
the existing manual document discount and tax. The calculator accepts integer
minor units, represents a percentage to four decimal places, and rounds the
discount to the nearest minor unit using half-up rounding. It never uses PHP
floating-point arithmetic.

The insert-only snapshot records basis, inherited adjustment, adjusted amount,
currency, source, definition label/rate, override reason, and calculation
version. It is unique per document revision. Editing a definition or assignment
therefore affects only future resolutions and cannot rewrite history.

Snapshot creation is available only through the authoritative repository path.
That path locks the document and applicable override, assignment, and definition
rows; derives the organization, project, contract, current revision, and exact
minor-unit subtotal from the document; rejects a stale requested revision; then
resolves and inserts in the same transaction. Callers cannot supply a pricing
basis or pre-resolved adjustment array.

## Eligibility and boundaries

- Definition, assignment target, override target, and actor permission are
  organization-scoped.
- Every definition/assignment/override mutation is feature-gated, permission
  checked, transactional, and written to the existing audit trail. Definitions
  are deactivated rather than deleted; assignments and document overrides have
  explicit audited removal operations.
- Project and contract assignments are explicit. Version 1 never infers an
  adjustment from duration, cadence, client name, or service type.
- A document override requires a reason and affects only that document.
- Definition, assignment, and override mutations commit with their strict audit
  row. An audit insertion failure rolls back the pricing mutation.
- Existing manual discounts remain independent. A later integration phase may
  apply them after inherited pricing, but must show both components.
- Project aggregate invoices are explicitly excluded from v1 adjustment
  application because their child balances are already priced. Reapplying a
  project rule would double-discount the receivable.
- One-off and non-project workflows remain unchanged while the feature is off.

## Management, integration, and presentation

Administrators with `financial.manage` can create, update, and deactivate
installation-wide or customer-scoped definitions in Settings. Definitions are
never hard-deleted. Project and Contract edit pages expose explicit assignment
controls, while quote, Contract, and invoice edits allow an audited document
override or opt-out with a required reason and a warning before pricing changes.
The controls initialize through the application's page lifecycle so direct
loads, soft navigation, and cached revisits behave consistently without
duplicating event handlers.

Document creation, edits, quote acceptance, derived Contract/invoice creation,
and recurring generation use the authoritative revision path. Each finalized
revision stores or carries forward its immutable pricing snapshot. The feature
and its management UI fail closed when disabled or when any required migration
column is unavailable.

Regular and long-term quote and Contract views, invoice views, public links,
downloaded PDFs, and email PDF attachments render the same generic `Pricing
adjustment` row and immutable amount. Definition names, assignment IDs, source
details, and override reasons are never included in client output. Staff-only
provenance appears only in the authenticated application and is suppressed in
public and PDF/email rendering.

Invoice charge and credit records affect the displayed total only when they are
active, explicitly marked `affects_total`, and not superseded. Client documents
show generic aggregate `Invoice charge` and `Invoice credit` rows after tax and
before Total. Informational adjustments and private metadata remain hidden, and
all monetary summary rows use the same validated three-letter document currency.

## Settlement and close-out v1 decision

Migration 0077 reserves a default-off settlement boundary without changing any
existing lifecycle at runtime. `contract_settlement_enabled` defaults to `0`,
no existing Contract receives settlement terms, and no Contract or Project is
transitioned or backfilled by the migration.

Settlement is an explicit Contract policy, not an inferred commitment or cadence
rules engine. Terms are frozen against a specific accepted or signed Contract
revision. The first automatic policy is `reprice_to_percentage`; it stores the
target definition as provenance and copies the target label, kind, and rate so a
later definition edit cannot change settlement. Missing terms never create a
penalty. `none` means no reconciliation, while unsupported or incomplete history
requires manual review.

An automatic calculation may use only finalized, non-void source invoices that
belong to the same customer organization, Project, and Contract and have an exact
pricing snapshot plus document revision for the billed revision. The calculation
replays the frozen inherited adjustment, manual discount, and tax with integer
minor-unit arithmetic. It never rewrites a source invoice. Pre-feature history,
mixed currencies, corrupt or missing snapshots, unsupported adjustment kinds,
and negative reconciliation remain manual-review conditions in v1.

A non-zero automatic reconciliation is stored as a review with immutable source
lines before it may create a single draft invoice. Approval may create that draft,
but it does not finalize, send, or collect it. Zero adjustment and an explicitly
reasoned waiver may resolve close-out without an invoice. Request keys, canonical
basis hashes, and the existing invoice generation key provide database-backed
idempotency. Financial decisions and their strict audit records must commit in the
same transaction.

The Contract lifecycle gains `closing` as the state that prevents additional
generation while a close-out review is unresolved. Existing terminal states stay
unchanged. Project completion will be guarded by Contract lifecycle only: every
attached Contract must be terminal, and no action may blindly close Contracts.
Open or unpaid invoices never block Project completion and remain collectible
afterward. Project financial state is derived from receivables rather than stored
on the Project.

The schema stores settlement terms, reviews, and per-source-invoice calculation
lines. It intentionally does not activate close-out controllers or alter existing
completion actions in this foundation slice; those integrations require their own
transaction, concurrency, and workflow tests before the feature can be enabled.

## Deferred

- Runtime settlement calculation, review, draft-invoice materialization, and
  Project close guards remain disabled until the bounded workflow phase lands.
- Duration/cadence inference, automatic tier selection, stacked settlement rules,
  fixed termination amounts, markups, and promotion engines are out of v1.
- Negative reconciliation, automatic credit notes or refunds, multi-currency
  settlement, and tax-jurisdiction re-evaluation require separate accounting
  decisions.
- Missing historical pricing snapshots are not backfilled or guessed.
- Project aggregate invoices are not repriced, and settlement never auto-issues,
  emails, collects payment, or closes a Contract in bulk.
