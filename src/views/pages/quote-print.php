<?php
// src/views/pages/quote-print.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/format.php';
require_once __DIR__ . '/../../utils/csrf.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT q.*, c.name client_name, c.organization client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$quote){ echo '<p>Quote not found</p>'; return; }
$items = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
$fromName = ($appConfig['from_name'] ?? '') ?: ($appConfig['brand_name'] ?? 'Project Alpha');
$fromAddress = trim(($appConfig['from_address_line1'] ?? '')."\n".($appConfig['from_address_line2'] ?? '')."\n".($appConfig['from_city'] ?? '').' '.($appConfig['from_state'] ?? '').' '.($appConfig['from_postal'] ?? '')."\n".($appConfig['from_country'] ?? ''));
$fromPhone = $appConfig['from_phone'] ?? '';
$fromEmail = $appConfig['from_email'] ?? '';
$brand = $appConfig['brand_name'] ?? 'Project Alpha';
$logo = $appConfig['logo_path'] ?? null;
// Resolve terms: project-level terms override global settings
$termsText = '';
if (!empty($quote['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
    $pm->execute([$quote['project_code']]);
    $pt = (string)$pm->fetchColumn();
    if (trim($pt) !== '') { $termsText = trim($pt); }
  } catch (Throwable $e) { /* ignore */ }
}
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
?>
<section>
  <div class="no-print" style="display:flex;gap:8px;margin-bottom:8px">
    <a href="javascript:history.back()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Back</a>
    <button onclick="window.print()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Print</button>
    <form method="post" action="/?page=email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Email</button>
    </form>
  </div>
  <div style=\"display:flex;justify-content:space-between;align-items:center;margin-bottom:8px\">
    <div style="font-weight:700;font-size:18px"><?php echo htmlspecialchars($brand); ?></div>
    <div><?php if ($logo): ?><img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:40px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px"><?php endif; ?></div>
  </div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin:6px 0 12px">
    <div style="font-weight:700">Quote Q-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?></div>
    <div><?php if (!empty($quote['project_code'])): ?>Project <?php echo htmlspecialchars($quote['project_code']); ?><?php endif; ?></div>
  </div>

  <div style="display:flex;gap:24px;margin-bottom:16px">
    <div style="flex:1">
      <div style="font-weight:600">From</div>
      <div><?php echo htmlspecialchars($fromName); ?></div>
      <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars($fromAddress); ?><?php echo $fromPhone?"\n".format_phone($fromPhone):''; ?><?php echo $fromEmail?"\n".$fromEmail:''; ?></pre>
    </div>
    <div style="flex:1">
      <div style=\"font-weight:600\">To</div>
      <div><?php echo htmlspecialchars($quote['client_name']); ?></div>
      <?php if (!empty($quote['client_org'])): ?><div style=\"color:#374151; font-size: 14px; margin-top:2px\"><?php echo htmlspecialchars($quote['client_org']); ?></div><?php endif; ?>
      <pre style=\"white-space:pre-wrap;margin:0\"><?php echo htmlspecialchars(trim(($quote['address_line1'] ?? '') . (empty($quote['address_line2'])?'':'\\n'.$quote['address_line2']) . (empty($quote['city'])&&empty($quote['state'])&&empty($quote['postal'])?'':'\\n'.trim(($quote['city'] ?? '').' '.($quote['state'] ?? '').' '.($quote['postal'] ?? ''))) . (empty($quote['country'])?'':'\\n'.$quote['country']))); ?><?php echo !empty($quote['client_phone'])?"\\n".format_phone($quote['client_phone']):''; ?><?php echo !empty($quote['client_email'])?"\\n".$quote['client_email']:''; ?></pre>
    </div>
  </div>

  <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee">
        <th style="padding:10px">Description</th>
        <th style="padding:10px">Qty</th>
        <th style="padding:10px">Unit</th>
        <th style="padding:10px">Line Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr style="border-top:1px solid #f3f4f6">
        <td style="padding:10px"><?php echo htmlspecialchars($it['description']); ?></td>
        <td style="padding:10px"><?php echo number_format($it['quantity'],2); ?></td>
        <td style="padding:10px">$<?php echo number_format($it['unit_price'],2); ?></td>
        <td style="padding:10px">$<?php echo number_format($it['line_total'],2); ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="4" style="border-top:1px solid #eee"></td></tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Subtotal</td>
        <td style="padding:10px">$<?php echo number_format($quote['subtotal'],2); ?></td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Discount</td>
        <td style="padding:10px">
          <?php if ($quote['discount_type']==='percent'): ?>
            <?php echo number_format($quote['discount_value'],2); ?>%
          <?php elseif ($quote['discount_type']==='fixed'): ?>
            $<?php echo number_format($quote['discount_value'],2); ?>
          <?php else: ?>
            $0.00
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Tax</td>
        <td style="padding:10px"><?php echo number_format($quote['tax_percent'],2); ?>%</td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:700">Total</td>
        <td style="padding:10px;font-weight:700">$<?php echo number_format($quote['total'],2); ?></td>
      </tr>
    </tbody>
</table>

<h3 style="margin-top:16px">Terms and Conditions</h3>
<?php if ($termsText !== ''): ?>
  <pre style="white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #eee;border-radius:8px"><?php echo htmlspecialchars($termsText); ?></pre>
<?php else: ?>
  <p class="lead">By accepting this quote, the client agrees to the scope and payment terms. Additional terms can be customized in Settings.</p>
<?php endif; ?>

</section>
<style>.no-print{display:flex} @media print {.no-print{display:none} .side-nav,.nav-footer{display:none} .main-content{margin-left:0} body{background:#fff}}</style>
