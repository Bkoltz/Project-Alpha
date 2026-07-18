---
title: Service Library and Work Activities
description: How client services, reusable work activities, time billing, and worker compensation fit together.
---

# Service Library and Work Activities

Project Alpha is designed around service businesses. The Service Library describes the service a client receives. Work Activities describe what a worker did. A service connects to one or more reusable activities so administrators do not need to enter the same work twice, while client billing and worker compensation remain separate rules.

```text
Service Library service or package
  +-- client name, description, price, and billing unit
  +-- linked Work Activities
          +-- reusable activity selected on time entries
          +-- service-specific client billing rule
          +-- planned quantity or duration
          +-- worker compensation rule

Approved time entry
  +-- linked service and Work Activity
  +-- resolved hourly billing rate
          +-- invoice line when billed by the hour
```

## Service Library Settings

Use the Service Library for reusable client services on quotes, contracts, and invoices.

| Setting | Meaning |
|---|---|
| Name and description | Client-facing wording copied to a document line. |
| Service type | **Service**, **Fee**, or **Package**. A package combines services or fees under one client price. |
| Unit price | The normal client price. It can still be adjusted on an individual document. |
| Billing unit | How the price is measured: **Service unit**, **Hour**, **Day**, **Mile**, or **Project**. Service unit means one instance of the service or fee. |
| Tax | Always uses the document default. The selected client or organization and the document's tax settings control the result. |
| Fulfillment notes | Internal instructions that do not appear on client documents. |
| Status | Inactive offerings cannot be added to new documents. Existing document snapshots remain unchanged. |

PA does not use product inventory or SKUs. Inventory-based businesses should use a dedicated inventory system and use PA for their service and financial workflows. Database and API identifiers may continue to use `item_library` and `work_type` for backward compatibility.

## Packages

A package is a new Service Library entry with its own name, description, and price. Search the Service Library to add its component services or fees. The package is the client-facing document line; its contents explain what the business plans to deliver.

Packages cannot contain another package. This keeps package pricing and internal work predictable.

## Connecting Work Activities

A service may contain one or more Work Activities. For example, a **Site survey package** could include **On-site Capture**, **3D Modeling**, and **2D Mapping**. The same activity can be reused by many services.

When a service is created, PA offers to create and link a same-named Work Activity automatically. An administrator can instead choose an existing activity, add several activities, or remove the default link. Each connection may define planned quantity, expected duration, assignment requirements, client billing, and compensation. Adding a service to a document can create planned Job work, but it does not make compensation payable by itself.

| Client billing treatment | Meaning |
|---|---|
| Hourly | Confirmed time is billed at the service-activity hourly rate, then the normal fallback precedence. |
| Included in service price | Time is tracked but does not create a second charge beyond the fixed service price. |
| Service price + hourly overage | The service price includes configured minutes; additional time is prorated by the minute at the overage rate. |
| Internal / not billed | Time remains available for operational reporting but never becomes a client charge. |

## Work Activity Settings

A Work Activity is the reusable classification selected on time entries and planned work. Existing integrations may still call this record a Work Type.

| Setting | Meaning |
|---|---|
| Name and description | Internal label and guidance for the kind of work being performed. |
| Client billing default | Whether tracked time is undecided, internal, included in a fixed price, or billed hourly. |
| Default client hourly rate | Fallback client rate for hourly time using this Work Activity. It is not the worker's pay rate. |
| Worker compensation default | Default treatment for worker earnings when a more specific assignment or service-component rule does not apply. |
| Capture and approval settings | How workers record the work and what review is required. |

## How Hourly Billing Resolves

When a document is billed by the hour, PA bills confirmed time using its linked service, Work Activity, and resolved client rate. Workers select the service and activity; they do not choose client billing or their own compensation.

The client hourly rate resolves from the most specific configured value:

1. Project override
2. Client override
3. Service-activity rate
4. Work Activity default client hourly rate
5. Business fallback billing rate

The resulting invoice line keeps a snapshot of the description, hours, and rate so later setting changes do not rewrite history.

Use an hourly Service Library entry when preparing a quote, contract, or manual invoice line with a reusable client-facing description and price. Use a Work Activity when recording the actual work. The service-activity connection prevents duplicate setup and supplies the correct billing and compensation context.

## Recording Scheduled and Unscheduled Service Work

On **Workforce > Time**, choose the Service first and then the Work Activity performed. The activity supplies the owner-defined client-billing and worker-compensation rules; workers do not choose either financial treatment. Time can be entered as hours plus minutes, exact start/end times, or a running timer.

If the work did not start from a quote, contract, invoice, or planned Job, PA creates a client-optional **unscheduled service Job** for the entry. Completed unscheduled Jobs remain available for deliberate reuse when later time belongs to the same service event. Reusing the Job is important for a base-plus-overage service because its base price and included minutes apply once across the Job, not once per time entry.

Leave both Service and Work Activity blank only for genuinely unclassified time. PA allows the entry but shows a warning so an owner or manager can classify it before billing or compensation is finalized.

## Adding Later Time to a Draft Invoice

Confirmed hourly time can be added to any accessible, unfinalized draft invoice for the same client. Saving a draft invoice does not close it: later confirmed entries remain available from the Time page. PA combines matching entries by Service, Work Activity, hourly rate, currency, and billing unit while retaining each time-entry allocation for audit and reversal.

The combined line description uses `MM-DD-YYYY × Hh Mm`, with up to three entries per line. Client amounts use the exact recorded minutes; displayed hours may be rounded for readability. Finalized, paid, void, and cancelled invoices cannot accept more time.

## Base Price Plus Overage

For a service such as deer recovery, configure the normal Service price, included minutes, and hourly overage rate on the service-activity connection. For example, a $350 service with 60 included minutes and a $50 hourly overage remains $350 at 60 minutes, becomes $362.50 at 75 minutes, and becomes $400 at 120 minutes. Worker compensation can independently use a $100 base, 60 included minutes, and a $35 hourly overage.

Base-plus-overage time is treated as included in the service price during ordinary hourly invoice attachment; the Job-level expected charge calculates the base and exact-minute overage together. This avoids accidentally charging the base price as an hourly rate.

## Cash and Standalone Payments

Manual payments may optionally link to a client and/or Service Job. Selecting a Job shows the expected charge from its immutable service-activity snapshots and confirmed time, plus a non-blocking variance against the amount actually received. PA always saves the actual payment amount. Leave both fields blank for anonymous walk-in or other standalone income. Email receipts are available only when the linked client has a valid email address.

## Worker Compensation

Client billing and worker compensation are calculated independently. A client can be charged a fixed package price while workers are paid hourly, by a fixed amount, by base plus overage, by percentage, or not through PA.

The most specific valid compensation rule wins: an explicit assignment or service-activity rule takes priority, followed by the Work Activity default and then the worker/business fallback. Owner and nonpayable policies still apply. Confirmation creates immutable billing and earning snapshots; later configuration changes affect future work only.

## Tax-Exempt Clients

When the selected organization has a tax-exemption file, document pages show the tax-exempt banner and skip automatic ZIP tax prefill. Tax inputs remain available so an authorized user can manually apply a rate for an unusual situation. Non-exempt clients can still prefill their ZIP and use imported ZIP or county tax data.
