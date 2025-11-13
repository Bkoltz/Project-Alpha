# Workflow: Quotes

## Overview
When a quote is approved, Project-Alpha automatically generates:
- A new **Contract** (status: `pending`)
- A new **Invoice** (status: `draft`)

## States and Transitions

### 1. Quote Approved
- Triggers creation of:
  - Contract (linked to quote)
  - Invoice (linked to contract)

### 2. Contract Lifecycle
- `pending`: Awaiting signed contract upload
- `active`: Signed contract uploaded
- `complete`: Job marked done

### 3. Invoice Lifecycle
- Created when quote is approved
- Due date is set when contract is marked `complete`
- Due date = `completion_date + user_defined_terms_days`

## Settings Reference
- `terms_days`: Configurable in system settings