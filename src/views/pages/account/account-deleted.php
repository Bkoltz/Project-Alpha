<?php
// src/views/pages/account/account-deleted.php
// Public confirmation page shown after GDPR/CCPA account deletion.
?>
<section class="account-deleted-page">
  <div class="card">
    <h1>Account Deleted</h1>
    <p>Your account has been deleted. Thank you for using Project Alpha.</p>
  </div>
</section>

<style>
.account-deleted-page {
  max-width: 720px;
  margin: 0 auto;
  padding: 24px;
}

.account-deleted-page .card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 24px;
  box-shadow: var(--shadow-sm);
  text-align: center;
}

.account-deleted-page h1 {
  margin: 0 0 12px;
  font-size: 24px;
  font-weight: 700;
  color: var(--text);
}

.account-deleted-page p {
  margin: 0;
  color: var(--muted);
  font-size: 15px;
  line-height: 1.6;
}
</style>
