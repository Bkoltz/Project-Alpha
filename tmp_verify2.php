<?php
require 'src/config/db.php';
$email = 'admin@project-alpha.local';
$pass = getenv('ADMIN_PASSWORD');
$st = $pdo->prepare('SELECT id,email,password_hash FROM users WHERE email=?');
$st->execute([$email]);
$u = $st->fetch();
if (!$u) { echo "no user\n"; exit; }
echo 'hash=' . substr($u['password_hash'],0,30) . '...\n';
echo 'verify=' . (password_verify($pass, $u['password_hash']) ? 'yes' : 'no') . '\n';
