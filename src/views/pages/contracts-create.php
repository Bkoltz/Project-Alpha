<?php
// src/views/pages/contracts-create.php
?>
<section>
  <h2>Create Contract</h2>
  <form method="post" action="#" onsubmit="alert('Submit handler TBD'); return false;" style="display:grid;gap:12px;max-width:520px">
    <label>
      <div>Client Name</div>
      <input type="text" name="client" placeholder="Acme Co." style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Scope</div>
      <textarea name="scope" rows="4" placeholder="Describe the work" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create</button>
  </form>
</section>
