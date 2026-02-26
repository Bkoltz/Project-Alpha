Project Alpha
===============

Project Alpha is a lightweight PHP application for managing clients, quotes, contracts, and invoices.

Core features
-------------
- Create and manage clients, organizations and projects
 - Create and manage clients, organizations, Jobs (auto-generated project_codes), and Projects (manual parent groups)
- Draft, approve and archive quotes
- Generate contracts and invoices automatically from quotes
- Generate downloadable PDFs for quotes, contracts, and invoices
- Upload signed contracts (signed PDF files) and serve them securely
- Long-term and on-demand document support (project-level configurations)

Docker / Development notes
--------------------------
- This project is containerized using Docker. The runtime uses PHP 8.3 and Apache.
- The composer/vendor stage uses the same PHP version to avoid package platform mismatches.
- If you run into build problems related to PHP version/platform mismatch during `composer install`, ensure you build using the provided `Dockerfile` which uses `php:8.3` for the vendor stage.

Running locally with Docker
--------------------------
Build and run (using Compose):

```cmd
docker compose build --no-cache
docker compose up -d
```

Open the app at http://localhost/ (the container runtime listens on port 80 by default)

Documentation (workflow)
------------------------
Detailed workflow documentation, including lifecycles for Quotes, Contracts, Invoices, and Project guidance is available under the `work_flow/` directory in the repository. See:
- `work_flow/document_types.md`
- `work_flow/projects.md`
- `work_flow/regular_docs.md`
- `work_flow/long-term_docs.md`
- `work_flow/settings.md`
  
Note: The UI previously had a 'Projects' listing for auto-generated Job codes. That listing is now labelled as **Jobs**; a new 'Projects' area has been added for manual, parent-level Projects you can create and manage. See `work_flow/projects.md` for details about Jobs vs Projects and how to associate documents.

Contributing and development
----------------------------
If you'd like to contribute or extend the project, please read through the `work_flow` docs first. The public-facing routes are routed through `public/index.php` where `page` query parameters map to controllers and views under `src/controllers` and `src/views/pages`.

If you need help with setting up the dev environment, or want to run tests, run the test script (if installed) or run PHPUnit in a dev environment:

```cmd
composer install
vendor/bin/phpunit --colors=always
```

Issues and PRs
-------------
Open issues/PRs in GitHub or raise a support request internally. Include a small reproduction with logs if your change is related to build/runtime issues.

Contact
-------
Project Alpha developer team
