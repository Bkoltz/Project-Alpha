<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';

if (!rate_limit_check($pdo, 'payment_receipt', 20, 60)) {
    http_response_code(429);
    echo 'Rate limited';
    exit;
}

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT r.*,p.payment_date,p.payment_method,p.reference_number,
            i.doc_number,c.name AS client_name,c.email AS client_email
     FROM payment_receipts r
     JOIN payments p ON p.id=r.payment_id
     JOIN clients c ON c.id=p.client_id
     LEFT JOIN invoices i ON i.id=r.invoice_id
     WHERE r.public_token=?'
);
$stmt->execute([$token]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$receipt) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

$brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($receipt['receipt_number']); ?> - <?php echo htmlspecialchars($brand); ?></title>
  <style>
    body{font-family:Arial,sans-serif;background:#f3f4f6;color:#111827;margin:0;padding:24px}.receipt{max-width:720px;margin:0 auto;background:#fff;border:1px solid #d1d5db;padding:32px}.top{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #111827;padding-bottom:18px}.amount{font-size:32px;font-weight:700}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:24px}.label{font-size:12px;color:#6b7280;text-transform:uppercase}.value{font-weight:600;margin-top:4px}.actions{max-width:720px;margin:16px auto;text-align:right}button{padding:10px 16px;border:0;background:#111827;color:#fff;cursor:pointer}@media(max-width:600px){.grid{grid-template-columns:1fr}.top{display:block}.amount{margin-top:16px}}@media print{body{background:#fff;padding:0}.actions{display:none}.receipt{border:0;max-width:none}}
  </style>
</head>
<body>
  <div class="actions"><button type="button" onclick="window.print()">Print or Save PDF</button></div>
  <main class="receipt">
    <div class="top"><div><div class="label">Receipt</div><h1><?php echo htmlspecialchars($receipt['receipt_number']); ?></h1><div><?php echo htmlspecialchars($brand); ?></div></div><div><div class="label">Amount received</div><div class="amount">$<?php echo number_format((float)$receipt['amount'], 2); ?></div></div></div>
    <div class="grid">
      <div><div class="label">Received from</div><div class="value"><?php echo htmlspecialchars($receipt['client_name']); ?></div><div><?php echo htmlspecialchars((string)$receipt['client_email']); ?></div></div>
      <div><div class="label">Payment date</div><div class="value"><?php echo htmlspecialchars(date('F j, Y', strtotime((string)$receipt['payment_date']))); ?></div></div>
      <div><div class="label">Invoice</div><div class="value"><?php echo !empty($receipt['doc_number']) ? 'I-' . htmlspecialchars((string)$receipt['doc_number']) : 'Not linked'; ?></div></div>
      <div><div class="label">Payment method</div><div class="value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$receipt['payment_method']))); ?></div></div>
      <?php if (!empty($receipt['reference_number'])): ?><div><div class="label">Reference</div><div class="value"><?php echo htmlspecialchars($receipt['reference_number']); ?></div></div><?php endif; ?>
    </div>
  </main>
</body>
</html>
