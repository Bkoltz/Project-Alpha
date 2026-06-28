# Long-Term Document Workflow

Long-term documents represent recurring services or retainers.

```text
Long-term quote
  -> pending long-term contract
  -> signed document
  -> explicit activation
  -> scheduled long-term invoices
```

An active contract must have a signed document and `next_invoice_date`. Billing uses the interval count/unit and pricing fields stored on the contract. Activation attempts the first invoice immediately when the schedule is already due; the cron service generates later invoices and catches up after downtime.

Long-term contracts can be paused, resumed, completed, or terminated. Paused and terminated contracts do not generate scheduled invoices.

See [Recurring Invoices](../docs/RECURRING_INVOICES_SETUP.md) and [Document Workflow](../docs/DOCUMENT_WORKFLOW.md).
