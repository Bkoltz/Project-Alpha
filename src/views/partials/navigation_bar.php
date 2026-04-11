<a class="brand" href="/">
    <?php $brand = $appConfig['brand_name'] ?? 'Project Alpha';
    $logo = $appConfig['logo_path'] ?? null; ?>
    
    <?php if ($logo): ?>
        <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" class="brand-logo" loading="eager" fetchpriority="high" />
    <?php else: ?>
        <svg class="brand-logo" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <defs>
                <linearGradient id="g" x1="0" x2="1">
                    <stop offset="0%" stop-color="var(--nav-accent)" />
                    <stop offset="100%" stop-color="#38bdf8" />
                </linearGradient>
            </defs>
            <rect x="4" y="4" width="40" height="40" rx="8" fill="url(#g)" />
            <path d="M10 26c7-2 12-9 17-9 4 0 7 3 11 3" stroke="#fff" stroke-width="2" fill="none" />
            <circle cx="36" cy="20" r="2" fill="#fff" />
        </svg>
    <?php endif; ?>
    
    <span class="brand-text"><?php echo htmlspecialchars($brand); ?></span>
</a>

<nav class="primary-nav">
    <ul>
        <li>
            <a href="/" data-page="home" class="active">Dashboard</a>
        </li>

        <li class="nav-section">
            <div class="section-label">Clients</div>
            <ul>
                <li><a href="/?page=client/clients-list" data-page="client/clients-list">List Clients</a></li>
                <li><a href="/?page=organization/organizations-list" data-page="organization/organizations-list">Organizations</a></li>
                <li><a href="/?page=client/clients-create" data-page="client/clients-create">Create Client</a></li>
            </ul>
        </li>

        <li class="nav-section">
            <div class="section-label">Quotes</div>
            <ul>
                <li><a href="/?page=quote/regular-quote-list" data-page="quote/regular-quote-list">Quotes</a></li>
                <li><a href="/?page=quote/long-term-quote-list" data-page="quote/long-term-quote-list">Long-term Quotes</a></li>
                <li><a href="/?page=quote/on-demand-quote-list" data-page="quote/on-demand-quote-list">On-Demand Quotes</a></li>
                <li><a href="/?page=quote/quote-create" data-page="quote/quote-create">Create Quote</a></li>
            </ul>
        </li>
        <li class="nav-section">
            <div class="section-label">Contracts</div>
            <ul>
                <li><a href="/?page=contract/contract-list" data-page="contract/contracts-list">Contracts</a></li>
                <li><a href="/?page=contract/long-term-contract-list" data-page="contract/long-term-contracts-list">Long-term Contracts</a></li>
                <li><a href="/?page=contract/on-demand-contract-list" data-page="contract/on-demand-contracts-list">On-Demand Contracts</a></li>
                <li><a href="/?page=contract/contract-create" data-page="contract/contracts-create">Create Contract</a></li>
            </ul>
        </li>
        <li class="nav-section">
            <div class="section-label">Invoices</div>
            <ul>
                <li><a href="/?page=invoice/invoice-list" data-page="invoice/invoices-list">Invoices</a></li>
                <li><a href="/?page=invoice/long-term-invoice-list" data-page="invoice/long-term-invoice-list">Recurring Invoices</a></li>
                <li><a href="/?page=invoice/on-demand-invoice-list" data-page="invoice/on-demand-invoice-list">On-Demand Invoices</a></li>
                <li><a href="/?page=invoice/invoice-create" data-page="invoice/invoice-create">Create Invoice</a></li>
            </ul>
        </li>
        <li class="nav-section">
            <div class="section-label">Payments</div>
            <ul>
                <li><a href="/?page=payments/payments-list" data-page="payments/payments-list">List Payments</a></li>
                <li><a href="/?page=payments/payments-create" data-page="payments/payments-create">Record Payment</a></li>
                <!-- <li><a href="/?page=settings&tab=terms" data-page="settings">Terms & Conditions</a></li> -->
            </ul>
        </li>
        <li class="nav-section">
            <div class="section-label">Jobs</div>
            <ul>
                <li><a href="/?page=jobs/jobs-list" data-page="/jobs/jobs-list">Jobs</a></li>
                <li><a href="/?page=project/projects-list" data-page="project/projects-list">Projects</a></li>
            </ul>
        </li>
        <li class="nav-section">
            <div class="section-label">Financial</div>
            <ul>
                <li><a href="/?page=financial/financial-dashboard" data-page="financial/financial-dashboard">Financial Dashboard</a></li>
                <li><a href="/?page=financial/forms-list" data-page="financial/forms-list">Forms & Docs</a></li>
                <li><a href="/?page=financial/receipts-list" data-page="financial/receipts-list">Receipts</a></li>
                <li><a href="/?page=financial/audit" data-page="financial/audit">Audit</a></li>
            </ul>
        </li>
    </ul>
</nav>

<div class="nav-footer">
    <!-- <?php $fromPhone = $appConfig['from_phone'] ?? null; ?>
          <?php if ($fromPhone): ?>
            <a class="phone" href="tel:<?php echo htmlspecialchars($fromPhone); ?>"><?php echo htmlspecialchars(format_phone($fromPhone)); ?></a>
          <?php endif; ?> -->
    <a class="settings" href="/?page=settings" data-page="settings">Settings</a>
    <a class="settings" href="/?page=accounts" data-page="accounts" style="margin-top:8px;display:block">Accounts</a>
    <a class="settings" href="/?page=logout" style="margin-top:8px;display:block">Logout</a>
</div>
</div>