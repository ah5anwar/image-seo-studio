<?php
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/install.php';
ensure_storage();

$done = false;
$error = '';
if (is_installed()) {
    $done = 'already';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = install_run($_POST);
    if ($r === true) {
        $done = 'ok';
    } else {
        $error = $r;
    }
}
function v($k, $d = '')
{
    return htmlspecialchars(isset($_POST[$k]) ? $_POST[$k] : $d, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SEO Studio — ইনস্টল</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth">
<div class="auth-card wide">
  <h1>SEO Studio ইনস্টলার</h1>
  <p class="muted">একবারই করতে হবে। শেষ হলে এই ফাইল (<code>install.php</code>) সার্ভার থেকে মুছে দিন।</p>

<?php if ($done === 'ok'): ?>
  <div class="alert ok">ইনস্টল সম্পন্ন ✔</div>
  <p><a class="btn primary" href="login.php">লগইন পেজে যান →</a></p>
  <p class="muted">নিরাপত্তার জন্য এখন <code>install.php</code> ফাইলটি মুছে দিন।</p>
<?php elseif ($done === 'already'): ?>
  <div class="alert">এটি আগেই ইনস্টল করা আছে। নতুন করে ইনস্টল করতে চাইলে <code>config.php</code> মুছুন।</div>
  <p><a class="btn" href="login.php">লগইন</a></p>
<?php else: ?>
  <h3>সার্ভার চেক</h3>
  <ul class="checks">
  <?php foreach (install_requirements() as $c): ?>
    <li class="<?= $c[1] ? 'ok' : ($c[2] ? 'bad' : 'warn') ?>"><?= $c[1] ? '✔' : ($c[2] ? '✘' : '!') ?> <?= htmlspecialchars($c[0]) ?><?= (!$c[1] && !$c[2]) ? ' — ঐচ্ছিক, কিন্তু থাকলে ভালো' : '' ?></li>
  <?php endforeach; ?>
  </ul>

  <?php if ($error): ?><div class="alert bad"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="post" autocomplete="off">
    <h3>ডাটাবেস</h3>
    <label>ধরন
      <select name="driver" id="drv">
        <option value="mysql" <?= v('driver', 'mysql') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (cPanel — সুপারিশকৃত)</option>
        <option value="sqlite" <?= v('driver') === 'sqlite' ? 'selected' : '' ?>>SQLite (ফাইল-ভিত্তিক, শুধু টেস্টের জন্য)</option>
      </select>
    </label>
    <div id="mysqlBox">
      <div class="row2">
        <label>Host <input name="db_host" value="<?= v('db_host', 'localhost') ?>"></label>
        <label>Port (ফাঁকা = ডিফল্ট) <input name="db_port" value="<?= v('db_port') ?>"></label>
      </div>
      <label>Database name <input name="db_name" value="<?= v('db_name') ?>" placeholder="cpuser_seostudio"></label>
      <div class="row2">
        <label>DB user <input name="db_user" value="<?= v('db_user') ?>" placeholder="cpuser_seo"></label>
        <label>DB password <input type="password" name="db_pass" value=""></label>
      </div>
    </div>

    <h3>অ্যাডমিন অ্যাকাউন্ট</h3>
    <div class="row2">
      <label>Username <input name="admin_user" value="<?= v('admin_user', 'admin') ?>" required></label>
      <label>Password (কমপক্ষে ৮ অক্ষর) <input type="password" name="admin_pass" required minlength="8"></label>
    </div>

    <h3>সাইট তথ্য (ছবির মেটাডেটায় বসবে — পরে Settings-এ বদলানো যাবে)</h3>
    <div class="row2">
      <label>ব্র্যান্ড / সাইটের নাম <input name="site_name" value="<?= v('site_name') ?>" placeholder="Creatives iT"></label>
      <label>ওয়েবসাইট URL <input name="site_url" value="<?= v('site_url') ?>" placeholder="https://creativesit.com"></label>
    </div>
    <div class="row2">
      <label>Author / Creator <input name="author" value="<?= v('author') ?>" placeholder="Anwar Hossain"></label>
      <label>Timezone <input name="timezone" value="<?= v('timezone', 'Asia/Dubai') ?>"></label>
    </div>
    <p><button class="btn primary" type="submit">ইনস্টল করুন</button></p>
  </form>
  <script>
    (function(){var s=document.getElementById('drv'),b=document.getElementById('mysqlBox');
    function f(){b.style.display=s.value==='mysql'?'block':'none';} s.onchange=f; f();})();
  </script>
<?php endif; ?>
</div>
</body>
</html>
