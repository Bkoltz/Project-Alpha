<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$organizationId = get_active_org_id();
$clients = $pdo->query('SELECT id,name,email FROM clients WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$organizations = $pdo->query('SELECT id,name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare(
    'SELECT i.*,c.name AS client_name,o.name AS target_organization_name,
            s.id AS submission_id,s.proposed_data,s.status AS submission_status,s.created_at AS submitted_at
     FROM client_onboarding_invitations i
     LEFT JOIN clients c ON c.id=i.client_id
     LEFT JOIN organizations o ON o.id=i.target_organization_id
     LEFT JOIN client_onboarding_submissions s ON s.invitation_id=i.id
     WHERE i.organization_id=?
     ORDER BY i.created_at DESC LIMIT 100'
);
$stmt->execute([$organizationId]);
$invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$generatedLink = (string)($_SESSION['client_onboarding_link'] ?? '');
unset($_SESSION['client_onboarding_link']);
?>

<section style="max-width:1300px;margin:0 auto;padding:24px">
  <div class="page-head">
    <div><h2>Client Onboarding</h2></div>
    <a class="btn" href="/?page=client/clients-list">Back to Clients</a>
  </div>

  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>
  <?php if (!empty($_GET['email_error'])): ?><div class="alert alert-danger">The link was created, but the invitation email could not be sent.</div><?php endif; ?>
  <?php if ($generatedLink !== ''): ?>
    <div style="padding:14px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:20px">
      <label class="label-muted" for="generatedOnboardingLink">New onboarding link</label>
      <div style="display:flex;gap:8px;margin-top:6px">
        <input id="generatedOnboardingLink" class="input" readonly value="<?php echo htmlspecialchars($generatedLink); ?>">
        <button type="button" class="btn" data-copy-onboarding-link="generatedOnboardingLink">Copy Link</button>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="/?page=client/onboarding-invite" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;align-items:end;padding:18px 0;border-bottom:1px solid var(--border);margin-bottom:24px">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="create">
    <label class="field"><span class="label-muted">Invited Email</span><input type="email" class="input" name="email" required></label>
    <label class="field"><span class="label-muted">Existing Client</span><select class="input" name="client_id" data-onboarding-client-select><option value="0">New client</option><?php foreach ($clients as $client): ?><option value="<?php echo (int)$client['id']; ?>" data-email="<?php echo htmlspecialchars((string)($client['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($client['name'] . (!empty($client['email']) ? ' - ' . $client['email'] : '')); ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="label-muted">Client Organization</span><select class="input" name="target_organization_id"><option value="0">No organization</option><?php foreach ($organizations as $organization): ?><option value="<?php echo (int)$organization['id']; ?>"><?php echo htmlspecialchars($organization['name']); ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="label-muted">Expires In</span><select class="input" name="expires_hours"><option value="24">24 hours</option><option value="48" selected>48 hours</option><option value="72">3 days</option><option value="168">7 days</option></select></label>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn" type="submit" name="delivery" value="link">Generate Link</button>
      <button class="btn btn-primary" type="submit" name="delivery" value="email">Generate and Email</button>
    </div>
  </form>

  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead><tr><th>Email</th><th>Client</th><th>Organization</th><th>Status</th><th>Expires</th><th>Submitted Information</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$invitations): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:28px">No onboarding invitations.</td></tr><?php endif; ?>
      <?php foreach ($invitations as $invitation): ?>
        <?php $proposal = json_decode((string)($invitation['proposed_data'] ?? ''), true); $proposal = is_array($proposal) ? $proposal : []; ?>
        <tr>
          <td><?php echo htmlspecialchars((string)$invitation['invited_email']); ?></td>
          <td><?php echo htmlspecialchars((string)($invitation['client_name'] ?: 'New client')); ?></td>
          <td><?php echo htmlspecialchars((string)($invitation['target_organization_name'] ?: 'None')); ?></td>
          <td><span class="status-pill status-pill--<?php echo htmlspecialchars((string)$invitation['status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$invitation['status'])); ?></span></td>
          <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$invitation['expires_at']))); ?></td>
          <td>
            <?php if ($proposal): ?>
              <details><summary><?php echo htmlspecialchars((string)($proposal['name'] ?? 'Review')); ?></summary><div style="font-size:13px;line-height:1.55;margin-top:8px"><?php foreach ($proposal as $label => $value): ?><?php if ($value !== ''): ?><div><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $label))); ?>:</strong> <?php echo htmlspecialchars((string)$value); ?></div><?php endif; ?><?php endforeach; ?></div></details>
            <?php else: ?><span class="muted">Not submitted</span><?php endif; ?>
          </td>
          <td style="min-width:220px">
            <?php if (($invitation['submission_status'] ?? '') === 'pending'): ?>
              <form method="post" action="/?page=client/onboarding-review" style="display:flex;gap:6px;flex-wrap:wrap">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="submission_id" value="<?php echo (int)$invitation['submission_id']; ?>">
                <button class="btn btn-sm btn-primary" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button>
              </form>
            <?php elseif (in_array((string)$invitation['status'], ['pending','verified'], true)): ?>
              <form method="post" action="/?page=client/onboarding-invite" onsubmit="return confirm('Revoke this invitation?')">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo (int)$invitation['id']; ?>"><button class="btn btn-sm btn-danger">Revoke</button>
              </form>
            <?php else: ?><span class="muted">Complete</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script src="/assets/js/client-onboarding.js" defer></script>
