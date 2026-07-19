---
title: Service Library and Work Activities
description: How client services, reusable work activities, time billing, and worker compensation fit together.
---

# Service Library and Work Activities

Project Alpha is designed around service businesses. The Service Library describes the service a client receives. Work Activities describe what a worker did. They remain independent unless an administrator creates an optional exclusive one-to-one link.

```text
Project (optional long-running container)
  +-- Job (one service engagement and document family)
        +-- quote -> contract -> draft invoice
        +-- client-facing Service pricing
        +-- worker Assignment and Work Activity
        +-- confirmed Time Entries -> actual draft-invoice charges
```

## Service Library Settings

Use the Service Library for reusable client services on quotes, contracts, and invoices.

| Setting | Meaning |
|---|---|
| Name and description | Client-facing wording copied to a document line. |
| Service type | **Service**, **Fee**, or **Package**. A package combines services or fees under one client price. |
| Unit price | The normal client price. It can still be adjusted on an individual document. |
| Pricing model | **Fixed**, **Hourly**, or **Base price + hourly overage**. Client pricing lives on the Service, never on the Work Activity. |
| Billing unit | How the price is measured: **Service unit**, **Hour**, **Day**, **Mile**, or **Project**. Service unit means one instance of the service or fee. |
| Tax | Always uses the document default. The selected client or organization and the document's tax settings control the result. |
| Fulfillment notes | Internal instructions that do not appear on client documents. |
| Status | Inactive offerings cannot be added to new documents. Existing document snapshots remain unchanged. |

PA does not use product inventory or SKUs. Inventory-based businesses should use a dedicated inventory system and use PA for their service and financial workflows. Database and API identifiers may continue to use `item_library` and `work_type` for backward compatibility.

## Packages

A package is a new Service Library entry with its own name, description, and price. Search the Service Library to add its component services or fees. The package is the client-facing document line; its contents explain what the business plans to deliver.

Packages cannot contain another package. This keeps package pricing and internal work predictable.

## Connecting Work Activities

A Service may have no Work Activity link, which is normal for businesses where what the client receives differs from employee labor. A bleacher rental Service, for example, can remain separate from **Driving**, **Setup**, and **Maintenance** activities.

When the same concept is both client-facing and internal—such as **On-site Work**, **Editing**, or **Mapping**—an administrator may create a matching Work Activity or link an existing unlinked activity. The relationship is exclusive: one active Service links to at most one active Work Activity, and vice versa. Packages receive any Activity links through their contained Services.

Linked names and prices do not synchronize. Deactivating a linked Service or Activity deactivates the pair while preserving the link and history. Unlink first when the two records should have independent lifecycles. An unused pair can be permanently deleted; any pair referenced by documents, Jobs, time, or compensation must be deactivated instead.

## Work Activity Settings

A Work Activity is the reusable classification selected on time entries and planned work. Existing integrations may still call this record a Work Type.

| Setting | Meaning |
|---|---|
| Name and description | Internal label and guidance for the kind of work being performed. |
| Worker compensation default | Default treatment for worker earnings when a more specific assignment or service-component rule does not apply. |
| Capture and approval settings | How workers record the work and what review is required. |

For most businesses, keep Work Activities deliberately broad: **On-site work**, **Editing**, **Mapping**, **Administration**, and similar labels. A worker's profile supplies the normal pay policy; service-activity or assignment rules should be used only when a particular kind of work pays differently. This keeps time entry simple without giving workers control over client billing or their own compensation.

**Becomes eligible when** is the point at which calculated worker compensation may advance toward a statement or payment:

- **Work completed and approved** is the normal choice for hourly work.
- **Delivered / fulfilled** waits until the service outcome is delivered.
- **Invoice paid** waits until client money is received.
- **Manager releases it** requires an explicit manager decision.

**Worker must be assigned** means ad-hoc time alone cannot create that service-specific earning. Use it for fixed, base-plus-overage, or commission-like work that must be offered to a particular worker. Leave it off for ordinary hourly activities governed by the worker's pay profile.

Expected duration is a planning estimate, not a billing or pay rule. If a service varies substantially by acreage, complexity, or another scope factor, leave the catalog estimate blank and estimate the individual Job or assignment. The Work Activity can remain **2D Mapping** regardless of whether the Job covers 10 or 100 acres.

## How Hourly Billing Resolves

When a document is billed by the hour, the Service supplies the client rate and the Work Activity supplies the internal classification. Workers do not choose client billing or their own compensation.

The resulting invoice line keeps a snapshot of the description, hours, and rate so later setting changes do not rewrite history.

Use an hourly Service Library entry when preparing a quote, contract, or manual invoice line with a reusable client-facing description and price. Use a Work Activity when recording the actual work. The service-activity connection prevents duplicate setup and supplies the correct billing and compensation context.

## Recording Scheduled and Unscheduled Service Work

On **Workforce > Time**, workers choose what they did with a Work Activity and may select an assigned Job. The Job supplies client and Service context; workers do not choose client billing or their own compensation. Time can be entered as hours plus minutes, exact start/end times, or a running timer.

PA never creates a hidden Job from unclassified time. A worker may record only a Work Activity and description; the entry remains in the billing-context queue. Client-billable time must be assigned to a real Job before confirmation or invoice attachment.

A Project is optional and can contain many Jobs. A Job is the document-family anchor: quote approval and contract/invoice conversion retain the same Job. If exactly one mutable draft invoice exists for that Job, confirmed hourly time attaches automatically. With zero or multiple drafts, the entry waits for an administrator to choose the destination.

## Hourly estimates

Hourly quote and contract lines show estimated hours multiplied by the Service hourly rate and contribute to a clearly labeled estimated total. On conversion, those estimate lines remain available as reference on the draft invoice but do not become collectible invoice items. Confirmed time creates the actual hourly charges. Finalized invoices are immutable; later time belongs on another draft or an explicit charge/credit adjustment.

## Adding Later Time to a Draft Invoice

Confirmed hourly time can be added to any accessible, unfinalized draft invoice for the same client. Saving a draft invoice does not close it: later confirmed entries remain available both on the draft invoice's edit page and from the Time page. A manager may also select an optional draft invoice while recording or editing time; PA attaches that entry automatically after it is confirmed. Choosing only a Job records work context but does not silently change an invoice.

PA combines matching entries by Service, Work Activity, hourly rate, currency, and billing unit while retaining each time-entry allocation for audit and reversal.

The combined line description uses `MM-DD-YYYY × Hh Mm`, with up to three entries per line. Client amounts use the exact recorded minutes; displayed hours may be rounded for readability. Finalized, paid, void, and cancelled invoices cannot accept more time.

## Base Price Plus Overage

For a service such as deer recovery, configure the normal Service price, included minutes, and hourly overage rate on the Service. For example, a $350 service with 60 included minutes and a $50 hourly overage remains $350 at 60 minutes, becomes $362.50 at 75 minutes, and becomes $400 at 120 minutes. Worker compensation can independently use a $100 base, 60 included minutes, and a $35 hourly overage.

Base-plus-overage time is treated as included in the service price during ordinary hourly invoice attachment; the Job-level expected charge calculates the base and exact-minute overage together. This avoids accidentally charging the base price as an hourly rate.

## Cash and Standalone Payments

Manual payments may optionally link to a client and/or Service Job. Selecting a Job shows the expected charge from its immutable service-activity snapshots and confirmed time, plus a non-blocking variance against the amount actually received. PA always saves the actual payment amount. Leave both fields blank for anonymous walk-in or other standalone income. Email receipts are available only when the linked client has a valid email address.

## Worker Compensation

Client billing and worker compensation are calculated independently. A client can be charged a fixed package price while workers are paid hourly, by a fixed amount, by base plus overage, by percentage, or not through PA.

The most specific valid compensation rule wins: Assignment override, worker + Work Activity, Work Activity default, worker default, then organization default. Owner and nonpayable policies still apply. Confirmation creates immutable billing and earning snapshots; later configuration changes affect future work only.

## Tax-Exempt Clients

When the selected organization has a tax-exemption file, document pages show the tax-exempt banner and skip automatic ZIP tax prefill. Tax inputs remain available so an authorized user can manually apply a rate for an unusual situation. Non-exempt clients can still prefill their ZIP and use imported ZIP or county tax data.
