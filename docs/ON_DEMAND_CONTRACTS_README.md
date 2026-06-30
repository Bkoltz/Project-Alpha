# On-Demand Contracts

On-demand contracts support ongoing or flexible work that should be invoiced only when an operator chooses to bill it.

## Document Types

- Quote prefix: `ODQ-`
- Contract prefix: `ODC-`
- Invoice prefix: `ODI-`

The current schema uses unified tables:

- `quotes.quote_type='on_demand'`
- `contracts.contract_type='on_demand'`
- `invoices.invoice_type='on_demand'`

There is no active `on_demand_contracts` table.

## Workflow

```text
On-demand quote
  -> approved pending contract
  -> signed document
  -> explicit activation
  -> one or more manually generated invoices
```

An on-demand contract may also be created directly.

## Activation

The contract must be pending and have a signed document before activation. Uploading from the authenticated application does not activate on-demand contracts automatically; the current public signing route activates the contract when it accepts the client's signed upload.

## Generating an Invoice

From an active on-demand contract, select the invoice-generation action and provide the billable amount or content required by its configuration. Generated invoices retain the client, project, organization, and contract relationship.

When invoice-on-generation email is enabled, Project Alpha creates a public invoice link, sends the message through configured SMTP, and records the notification to prevent duplicates.

## Lifecycle Controls

On-demand contracts can be paused, resumed, completed, terminated, voided, and re-enabled through the available actions. Voiding a contract also voids related invoices and revokes their links.

## Verification

Test the full path with non-production data:

1. Create an on-demand quote or contract.
2. Approve the quote if used.
3. Upload a signed document.
4. Activate the contract.
5. Generate two separate invoices.
6. Record a partial and a full payment.
7. Confirm document prefixes, relationships, public links, and notifications.

See [Document Workflow](DOCUMENT_WORKFLOW.md) for shared behavior.
