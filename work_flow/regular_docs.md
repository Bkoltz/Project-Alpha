# Regular Doc Workflow:

## Overview
When a quote is approved, Project-Alpha automatically generates:
- A new **Quote** (status: `pending`)
- A new **Contract** (status: `pending`)
- A new **Invoice** (status: `unpaid`)

## States and Transitions

### 1. Quotes
- Quotes and contracts have the same fields for creation. This way when a quote is approved there is no additional information needed for the auto creation of a contract. 
- Status changes:
  - When a quote is `approved`, a contract and invoice is automatically generated. The contract goes into the `pending` state, and the invoice goes into the `unpaid` state without a due date set. **The document then becomes unedible.**
  - When a quote is `denied` no other documents are generated, and **the document becomes unedible.**

### 2. Contract Lifecycle
- Contracts can be created without the need of a quote. As mentioned before, the quotes and contracts have the same fields. 
- When a contract is generated it goes into the `pending` state, and a invoice is automatically generated which goes into the `unpaid` state.
- Status changes:
  - A contract goes into the `active` state when a signed contract is received and uploaded into the program. **The document becomes unedible.**
  - The user can mark the contract as `complete` once the job is considered done. Once it is marked `complete` the due date is set on the invoice automatically, based on the net terms in the settings. **The document becomes unedible.**
  - The contract can be marked void at at any time, which will also mark the invoice as void. The user can re enable the contract which also re enables the related invoice.

### 3. Invoice Lifecycle
- Invoices can be edited in any state other than when in the `paid` state. If a invoice has changes that don't match what is in the contract, it will have the tag *extra charge* next to it so that it is clear that it is different than the contract.
- Invoices can also be partially paid (`partial` state), and also be marked *paid in advance* if a invoice is paid it will automatically mark the contract as complete if it is not already.