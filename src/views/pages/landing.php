<?php
// src/views/pages/landing.php
// Member landing page — shows quick-access cards for permitted modules
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$orgId = get_active_org_id();

$modules = [
    ['perm' => 'quotes.view',      'label' => 'Quotes',      'icon' => '📋', 'url' => '/?page=quote/quotes-list',          'desc' => 'View and manage quotes'],
    ['perm' => 'contracts.view',    'label' => 'Contracts',   'icon' => '📝', 'url' => '/?page=contract/contracts-list',    'desc' => 'View and manage contracts'],
    ['perm' => 'invoices.view',     'label' => 'Invoices',    'icon' => '🧾', 'url' => '/?page=invoice/invoices-list',      'desc' => 'View and manage invoices'],
    ['perm' => 'payments.view',     'label' => 'Payments',    'icon' => '💳', 'url' => '/?page=payments/payments-list',     'desc' => 'View and record payments'],
    ['perm' => 'clients.view',      'label' => 'Clients',     'icon' => '👥', 'url' => '/?page=client/clients-list',        'desc' => 'View and manage clients'],
    ['perm' => 'projects.view',      'label' => 'Projects',    'icon' => '📁', 'url' => '/?page=project/projects-list',      'desc' => 'View and manage projects'],
    ['perm' => 'jobs.view',          'label' => 'Jobs',        'icon' => '🔨', 'url' => '/?page=jobs/jobs-list',             'desc' => 'View jobs and work orders'],
    ['perm' => 'organizations.view', 'label' => 'Organizations','icon' => '🏢', 'url' => '/?page=organization/organizations-list', 'desc' => 'View organizations'],
];

// Filter to only modules the user can access
$visible = array_filter($modules, fn($m) => user_can($pdo, $userId, $m['perm'], $orgId));
?>
<section>
  <h2>Welcome, <?php echo htmlspecialchars($_SESSION['user']['email'] ?? ''); ?></h2>
  <p style="color:#6b7280;margin-bottom:24px;">Select a module below to get started.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
    <?php foreach ($visible as $mod): ?>
      <a href="<?php echo htmlspecialchars($mod['url']); ?>" style="display:block;padding:20px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;transition:border-color 0.2s;">
        <div style="font-size:32px;margin-bottom:8px;"><?php echo $mod['icon']; ?></div>
        <div style="font-weight:600;font-size:16px;margin-bottom:4px;"><?php echo htmlspecialchars($mod['label']); ?></div>
        <div style="font-size:13px;color:#6b7280;"><?php echo htmlspecialchars($mod['desc']); ?></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (empty($visible)): ?>
    <div style="padding:40px;text-align:center;color:#6b7280;">
      <p>You don't have access to any modules yet. Please contact an administrator.</p>
    </div>
  <?php endif; ?>
</section>
