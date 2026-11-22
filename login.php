<?php
require __DIR__ . '/lib/core.php';
if (!is_installed()) {
    header('Location: install.php');
    exit;
}
ensure_storage();
start_session();

if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    header('Location: login.php');
    exit;
}
if (current_user_id()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $now = time();
    $lockUntil = isset($_SESSION['lock_until']) ? (int)$_SESSION['lock_until'] : 0;
    if ($lockUntil > $now) {
        $error = 'অনেকবার ভুল হয়েছে — ' . ($lockUntil - $now) . ' সেকেন্ড পর আবার চেষ্টা করুন';
    } elseif (!csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $error = 'সেশন মেয়াদ শেষ — পেজ রিলোড করে আবার চেষ্টা করুন';
    } else {
        $u = q('SELECT * FROM users WHERE username = ?', array(trim(isset($_POST['username']) ? $_POST['username'] : '')))->fetch();
        if ($u && password_verify(isset($_POST['password']) ? $_POST['password'] : '', $u['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['fails'] = 0;
            header('Location: index.php');
            exit;
        }
        $_SESSION['fails'] = (isset($_SESSION['fails']) ? $_SESSION['fails'] : 0) + 1;
        if ($_SESSION['fails'] >= 5) {
            $_SESSION['lock_until'] = $now + 60;
            $_SESSION['fails'] = 0;
        }
        sleep(1);
        $error = 'Username বা password ভুল';
    }
}
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>লগইন — SEO Studio</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth">
<div class="auth-card">
  <h1>SEO Studio</h1>
  <p class="muted">ছবি, টাইটেল, ডেসক্রিপশন ও মেটা — সব SEO এক জায়গায়</p>
  <?php if ($error): ?><div class="alert bad"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>Username <input name="username" autofocus required></label>
    <label>Password <input type="password" name="password" required></label>
    <p><button class="btn primary block" type="submit">লগইন</button></p>
  </form>
</div>
</body>
</html>
