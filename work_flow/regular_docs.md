# Regular Document Workflow

Regular documents represent one-time work.

```text
Pending quote
  -> approved quote
  -> pending contract
  -> unsigned/unpaid invoice
  -> signed active contract
  -> completed contract with invoice due date
  -> partial or full payment
```

Key behavior:

- Authenticated quote approval follows the automatic contract and invoice settings.
- Public approval creates a pending contract and an unpaid invoice.
- A regular contract created directly also creates an unpaid invoice.
- Uploading a signed regular contract activates it.
- Completing the contract applies configured net terms when the newest invoice has no due date.
- Voiding the contract voids related invoices and revokes their public links.
- Re-enabling the contract restores it to pending and restores voided invoices to unpaid.
- A fully paid invoice may complete the related contract.

See [Document Workflow](../docs/DOCUMENT_WORKFLOW.md) for statuses, exceptions, and public interactions.
