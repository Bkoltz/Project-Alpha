<?php
// src/views/pages/clients-create.php
?>
<section>
  <h2>Create Client</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <form method="post" action="/?page=clients-create" style="display:grid;gap:12px;max-width:520px">
    <label>
      <div>Name</div>
      <input required type="text" name="name" placeholder="Acme Co." style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Email</div>
      <input type="email" name="email" placeholder="email@example.com" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Phone</div>
      <input type="text" name="phone" placeholder="(555) 123-4567" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Organization</div>
      <input type="text" name="organization" placeholder="Org name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Address</legend>
      <label><div>Address line 1</div><input name="address_line1" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <label><div>Address line 2</div><input name="address_line2" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
        <label><div>City</div><input name="city" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <label><div>State</div><input name="state" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <label><div>Postal (zip)</div><input name="postal" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <!-- <label><div>Country</div><input name="country" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label> -->
      </div>
    </fieldset>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" placeholder="Internal notes" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create</button>
  </form>
</section>
