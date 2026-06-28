# Projects and Job Codes

Project Alpha uses two related concepts:

- **Projects** are manually created parent records stored in `projects`.
- **Job codes** are `project_code` values copied across related quotes, contracts, and invoices.

A project can group work across several document chains. A job code keeps one quote-to-contract-to-invoice chain traceable.

When a quote is approved without a job code, the application generates one and propagates it into derived documents. When creating documents directly, select the intended project and preserve the same job code for related work.

The current schema is defined in `database/init.sql`; do not use removed `000_all.sql` references or legacy type-specific document tables.
