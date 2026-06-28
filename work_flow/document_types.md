# Document Types

Project Alpha stores quotes, contracts, and invoices in three primary tables. Each table uses a type column to select one of three workflow families.

| Family | Quote | Contract | Invoice | Use case |
|---|---|---|---|---|
| Regular | `regular` | `regular` | `regular` | One-time work |
| Long-term | `long_term` | `long_term` | `long_term` | Recurring services |
| On-demand | `on_demand` | `on_demand` | `on_demand` | Work invoiced only when requested |

Do not use legacy separate long-term/on-demand tables or boolean type columns.

The authoritative lifecycle and public-link guide is [Document Workflow](../docs/DOCUMENT_WORKFLOW.md).
