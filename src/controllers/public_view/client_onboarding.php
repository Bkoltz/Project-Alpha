<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';

client_onboarding_revoke_stale($pdo);

if (!rate_limit_check($pdo, 'client_onboarding_view', 30, 300, false)) {
    http_response_code(429);
    echo '<main><div class="auth-wrap"><h1>Please wait</h1><p>Too many requests were received.</p></div></main></body></html>';
    exit;
}

$error = trim((string)($_GET['error'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$submitted = !empty($_GET['submitted']);
$invitationId = (int)($_SESSION['client_onboarding_invitation_id'] ?? 0);
$invite = null;
$existingClient = [];

if ($invitationId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM client_onboarding_invitations WHERE id=? AND status="pending"');
    $stmt->execute([$invitationId]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($token !== '') {
    $invite = client_onboarding_find_invitation($pdo, $token);
    if (!$invite || ($invite['status'] ?? '') !== 'pending') {
        $invite = null;
    } else {
        session_regenerate_id(true);
        $_SESSION['client_onboarding_invitation_id'] = (int)$invite['id'];
    }
}
if ($invite && !empty($invite['client_id'])) {
    $clientStmt = $pdo->prepare('SELECT name,phone,address_line1,address_line2,city,state,postal_code,country,client_type FROM clients WHERE id=?');
    $clientStmt->execute([(int)$invite['client_id']]);
    $existingClient = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
?>
<main>
  <div class="auth-wrap" style="max-width:680px">
    <?php if ($submitted): ?>
      <h1 style="margin-top:0">Information submitted</h1>
      <p>Your information has been sent for review. No existing client record is changed until it is approved.</p>
    <?php elseif (!$invite): ?>
      <h1 style="margin-top:0">Invitation unavailable</h1>
      <p>This invitation is invalid, expired, or already used. Contact the business that sent it for a new link.</p>
    <?php else: ?>
      <h1 style="margin-top:0">Client information</h1>
      <?php if ($error): ?><div style="padding:10px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;margin-bottom:12px"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <form method="post" action="/?page=client-onboarding-submit" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <label style="grid-column:1/-1"><span>Name</span><input class="input" name="name" maxlength="150" required value="<?php echo htmlspecialchars((string)($existingClient['name'] ?? '')); ?>"></label>
        <label><span>Phone</span><input class="input" name="phone" maxlength="50" autocomplete="tel" value="<?php echo htmlspecialchars((string)($existingClient['phone'] ?? '')); ?>"></label>
        <label><span>Client type</span><select class="input" name="client_type"><?php foreach (['unknown' => 'Not specified', 'business' => 'Business', 'consumer' => 'Individual'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo ($existingClient['client_type'] ?? 'unknown') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
        <label style="grid-column:1/-1"><span>Address</span><input class="input" name="address_line1" maxlength="255" autocomplete="address-line1" value="<?php echo htmlspecialchars((string)($existingClient['address_line1'] ?? '')); ?>"></label>
        <label style="grid-column:1/-1"><span>Apartment / Suite</span><input class="input" name="address_line2" maxlength="255" autocomplete="address-line2" value="<?php echo htmlspecialchars((string)($existingClient['address_line2'] ?? '')); ?>"></label>
        <label><span>City</span><input class="input" name="city" maxlength="100" autocomplete="address-level2" value="<?php echo htmlspecialchars((string)($existingClient['city'] ?? '')); ?>"></label>
        <label><span>State</span><input class="input" name="state" maxlength="2" autocomplete="address-level1" value="<?php echo htmlspecialchars((string)($existingClient['state'] ?? '')); ?>"></label>
        <label><span>Postal code</span><input class="input" name="postal_code" maxlength="20" autocomplete="postal-code" value="<?php echo htmlspecialchars((string)($existingClient['postal_code'] ?? '')); ?>"></label>
        <label><span>Country</span><input class="input" name="country" maxlength="100" autocomplete="country-name" value="<?php echo htmlspecialchars((string)($existingClient['country'] ?? 'US')); ?>"></label>
        <button type="submit" class="btn btn-primary" style="grid-column:1/-1">Submit for Review</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body></html>
