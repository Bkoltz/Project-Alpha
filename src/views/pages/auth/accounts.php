<?php
// src/views/pages/auth/accounts.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}

// CSRF token
$csrf = csrf_token();

// Fetch all users
$stmt = $pdo->query('SELECT id, email, username, role, created_at FROM users ORDER BY created_at DESC');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section>
  <h2>Account Management</h2>
  
  <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User created successfully.</div>
  <?php elseif (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User deleted successfully.</div>
  <?php elseif (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User updated successfully.</div>
  <?php elseif (isset($_GET['pwd_reset']) && $_GET['pwd_reset'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Password reset successfully.</div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin:16px 0">
    <p style="color:#6b7280">Manage user accounts, roles, and permissions</p>
    <a href="/?page=accounts&action=create" style="padding:10px 16px;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none;font-weight:600">+ Create User</a>
  </div>

  <?php if (isset($_GET['action']) && $_GET['action'] === 'create'): ?>
    <!-- Create User Form -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin:16px 0;max-width:600px">
      <h3 style="margin-top:0">Create New User</h3>
      <form method="post" action="/?page=accounts-create" style="display:grid;gap:12px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">Email *</div>
          <input required type="email" name="email" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="user@example.com">
        </label>
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">Username</div>
          <input type="text" name="username" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional">
        </label>
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">Role *</div>
          <select required name="role" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="user">User</option>
            <option value="admin">Admin</option>
          </select>
        </label>
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">Password *</div>
          <input required minlength="8" type="password" name="password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Min 8 characters">
        </label>
        
        <label>
          <input type="checkbox" name="force_reset" value="1">
          <span>Force password change on first login</span>
        </label>
        
        <div style="display:flex;gap:8px;margin-top:8px">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create User</button>
          <a href="/?page=accounts" style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151">Cancel</a>
        </div>
      </form>
    </div>
  <?php elseif (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])): ?>
    <!-- Edit User Form -->
    <?php
    $userId = (int)$_GET['id'];
    $stmt = $pdo->prepare('SELECT id, email, username, role, force_password_reset FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user):
    ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin:16px 0;max-width:600px">
      <h3 style="margin-top:0">Edit User</h3>
      <form method="post" action="/?page=accounts-update" style="display:grid;gap:12px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">Email *</div>
          <input required type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">Username</div>
          <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">Role *</div>
          <select required name="role" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
          </select>
        </label>
        
        <label>
          <input type="checkbox" name="force_reset" value="1" <?php echo ($user['force_password_reset'] ?? 0) ? 'checked' : ''; ?>>
          <span>Force password change on next login</span>
        </label>
        
        <div style="display:flex;gap:8px;margin-top:8px">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Changes</button>
          <a href="/?page=accounts" style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151">Cancel</a>
        </div>
      </form>
      
      <!-- Reset Password Section -->
      <hr style="margin:24px 0;border:0;border-top:1px solid #e5e7eb">
      <h4>Reset Password</h4>
      <form method="post" action="/?page=accounts-reset-password" style="display:grid;gap:12px;max-width:400px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
        
        <label>
          <div style="margin-bottom:4px;font-weight:600">New Password</div>
          <input required minlength="8" type="password" name="new_password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Min 8 characters">
        </label>
        
        <label>
          <input type="checkbox" name="force_reset" value="1">
          <span>Force password change on next login</span>
        </label>
        
        <button type="submit" style="padding:10px 16px;border-radius:8px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-weight:600">Reset Password</button>
      </form>
    </div>
    <?php else: ?>
      <div style="padding:20px;background:#fff1f2;color:#881337;border:1px solid #fca5a5;border-radius:8px">User not found.</div>
    <?php endif; ?>
  <?php else: ?>
    <!-- Users List -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb">
            <th style="padding:12px;text-align:left;font-weight:600">Email</th>
            <th style="padding:12px;text-align:left;font-weight:600">Username</th>
            <th style="padding:12px;text-align:left;font-weight:600">Role</th>
            <th style="padding:12px;text-align:left;font-weight:600">Created</th>
            <th style="padding:12px;text-align:right;font-weight:600">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
          <tr style="border-bottom:1px solid #e5e7eb">
            <td style="padding:12px"><?php echo htmlspecialchars($user['email']); ?></td>
            <td style="padding:12px"><?php echo htmlspecialchars($user['username'] ?? '-'); ?></td>
            <td style="padding:12px">
              <span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;
                <?php echo $user['role'] === 'admin' ? 'background:#dbeafe;color:#1e40af' : 'background:#f3f4f6;color:#374151'; ?>">
                <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
              </span>
            </td>
            <td style="padding:12px;color:#6b7280"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
            <td style="padding:12px;text-align:right">
              <div style="display:flex;gap:8px;justify-content:flex-end">
                <a href="/?page=accounts&action=edit&id=<?php echo $user['id']; ?>" style="padding:6px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151;font-size:14px">Edit</a>
                <?php if ($user['id'] != ($_SESSION['user']['id'] ?? 0)): ?>
                <form method="post" action="/?page=accounts-delete" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                  <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                  <button type="submit" style="padding:6px 12px;border-radius:6px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-size:14px;cursor:pointer">Delete</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          
          <?php if (empty($users)): ?>
          <tr>
            <td colspan="5" style="padding:40px;text-align:center;color:#6b7280">No users found.</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Future Features Info -->
    <div style="margin-top:24px;padding:16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px">
      <h4 style="margin:0 0 8px;color:#78350f">🚀 Coming Soon</h4>
      <p style="margin:0;color:#78350f;font-size:14px">Advanced features like Groups, Policies, and Permission management will be available in a future update.</p>
    </div>
  <?php endif; ?>
</section>
