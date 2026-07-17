---
title: Service Catalog and Work Types
description: How client-facing services, internal work, hourly billing, and worker compensation fit together.
---

# Service Catalog and Work Types

Project Alpha is designed around service businesses. The Item Library describes what a client buys. Work Types describe how the business classifies time and internal work. They can be connected, but they are not the same record and neither one silently changes the other.

```text
Item Library service or package
  +-- client name, description, price, and billing unit
  +-- optional internal work components
          +-- Work Type
          +-- planned quantity or duration
          +-- worker compensation rule

Approved time entry
  +-- Work Type client-billing treatment
  +-- resolved hourly billing rate
          +-- invoice line when billed by the hour
```

## Item Library Settings

Use the Item Library for reusable client offerings on quotes, contracts, and invoices.

| Setting | Meaning |
|---|---|
| Name and description | Client-facing wording copied to a document line. |
| Offering type | **Service**, **Fee**, or **Package**. A package combines services or fees under one client price. |
| Unit price | The normal client price. It can still be adjusted on an individual document. |
| Billing unit | How the price is measured: **Service unit**, **Hour**, **Day**, **Mile**, or **Project**. Service unit means one instance of the service or fee. |
| Tax | Always uses the document default. The selected client or organization and the document's tax settings control the result. |
| Fulfillment notes | Internal instructions that do not appear on client documents. |
| Status | Inactive offerings cannot be added to new documents. Existing document snapshots remain unchanged. |

PA does not use product inventory or SKUs. Inventory-based businesses should use a dedicated inventory system and use PA for their service and financial workflows.

## Packages

A package is a new Item Library offering with its own name, description, and price. Search the Item Library to add its component services or fees. The package is the client-facing document line; its contents explain what the business plans to deliver.

Packages cannot contain another package. This keeps package pricing and internal work predictable.

## Internal Work Components

An Item Library offering may contain one or more internal work components. For example, a **Site survey package** could include a **3D modeling** component and a **2D mapping** component.

Each component chooses a Work Type and may define planned quantity, expected duration, assignment requirements, and a compensation method. Selling the service can create planned Job work, but it does not make compensation payable by itself. The configured completion or approval event must occur first.

## Work Type Settings

A Work Type is the reusable classification selected on time entries and planned work.

| Setting | Meaning |
|---|---|
| Name and description | Internal label and guidance for the kind of work being performed. |
| Client billing default | Whether tracked time is undecided, internal, included in a fixed price, or billed hourly. |
| Default client hourly rate | Fallback client rate for hourly time using this Work Type. It is not the worker's pay rate. |
| Worker compensation default | Default treatment for worker earnings when a more specific assignment or service-component rule does not apply. |
| Capture and approval settings | How workers record the work and what review is required. |

## How Hourly Billing Resolves

When a document is billed by the hour, PA bills approved time using its Work Type and resolved client rate. It does not require a separate Item Library selection for every time entry.

The client hourly rate resolves from the most specific configured value:

1. Project override
2. Client override
3. Work Type default client hourly rate
4. Business fallback billing rate

The resulting invoice line keeps a snapshot of the description, hours, and rate so later setting changes do not rewrite history.

Use an hourly Item Library service when preparing a quote, contract, or manual invoice line with a reusable client-facing description and price. Use a Work Type when recording and approving the actual work. An Item Library work component can connect the two when the sold service should also plan specific internal work.

## Worker Compensation

Client billing and worker compensation are calculated independently. A client can be charged a fixed package price while workers are paid hourly, by a fixed amount, by base plus overage, by percentage, or not through PA.

The most specific valid compensation rule wins: an explicit assignment or service-component rule takes priority, followed by the Work Type default and then the worker/business fallback. Owner and nonpayable policies still apply. Approval creates an immutable earning snapshot; later configuration changes affect future work only.

## Tax-Exempt Clients

When the selected organization has a tax-exemption file, document pages show the tax-exempt banner and skip automatic ZIP tax prefill. Tax inputs remain available so an authorized user can manually apply a rate for an unusual situation. Non-exempt clients can still prefill their ZIP and use imported ZIP or county tax data.
