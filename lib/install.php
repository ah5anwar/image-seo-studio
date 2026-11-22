<?php
/**
 * Installer logic (used by install.php). Creates tables, admin user and config.php.
 */

function install_schema($driver)
{
    if ($driver === 'sqlite') {
        return array(
            'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, pass_hash TEXT NOT NULL, created_at TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)',
            'CREATE TABLE IF NOT EXISTS images (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, orig_name TEXT, orig_file TEXT, thumb_file TEXT, mime TEXT, width INTEGER, height INTEGER, orig_size INTEGER, out_ext TEXT DEFAULT \'\', out_mime TEXT DEFAULT \'\', out_w INTEGER DEFAULT 0, out_h INTEGER DEFAULT 0, out_size INTEGER DEFAULT 0, variants_json TEXT, seo_json TEXT, meta_json TEXT, opt_json TEXT, status TEXT DEFAULT \'new\', created_at TEXT, updated_at TEXT)',
            'CREATE INDEX IF NOT EXISTS idx_images_user ON images (user_id)',
        );
    }
    $opt = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return array(
        'CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)' . $opt,
        'CREATE TABLE IF NOT EXISTS settings (k VARCHAR(100) NOT NULL PRIMARY KEY, v MEDIUMTEXT)' . $opt,
        'CREATE TABLE IF NOT EXISTS images (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, orig_name VARCHAR(255), orig_file VARCHAR(100), thumb_file VARCHAR(100), mime VARCHAR(40), width INT, height INT, orig_size INT UNSIGNED, out_ext VARCHAR(8) NOT NULL DEFAULT \'\', out_mime VARCHAR(40) NOT NULL DEFAULT \'\', out_w INT NOT NULL DEFAULT 0, out_h INT NOT NULL DEFAULT 0, out_size INT UNSIGNED NOT NULL DEFAULT 0, variants_json TEXT, seo_json MEDIUMTEXT, meta_json MEDIUMTEXT, opt_json TEXT, status VARCHAR(20) NOT NULL DEFAULT \'new\', created_at DATETIME, updated_at DATETIME, INDEX idx_images_user (user_id))' . $opt,
    );
}

function install_requirements()
{
    $r = array();
    $r[] = array('PHP ' . PHP_VERSION . ' (7.4+ দরকার)', version_compare(PHP_VERSION, '7.4.0', '>='), true);
    $r[] = array('GD (ছবি প্রসেসিং)', extension_loaded('gd'), true);
    $r[] = array('GD → WebP সাপোর্ট', function_exists('imagewebp'), false);
    $r[] = array('mbstring (বাংলা টেক্সট)', extension_loaded('mbstring'), true);
    $r[] = array('cURL (AI API কল)', extension_loaded('curl'), true);
    $r[] = array('OpenSSL (API key এনক্রিপশন)', extension_loaded('openssl'), true);
    $r[] = array('PDO MySQL', extension_loaded('pdo_mysql'), false);
    $r[] = array('EXIF (ছবির অরিয়েন্টেশন ঠিক করতে)', function_exists('exif_read_data'), false);
    $r[] = array('ZipArchive (ZIP ডাউনলোড)', class_exists('ZipArchive'), false);
    $r[] = array('storage/ ফোল্ডার writable', is_writable(STORAGE_DIR), true);
    $r[] = array('ফোল্ডার writable (config.php লিখতে)', is_writable(APP_ROOT), true);
    return $r;
}

function install_run(array $o)
{
    $driver = (isset($o['driver']) && $o['driver'] === 'sqlite') ? 'sqlite' : 'mysql';
    $admin = trim(isset($o['admin_user']) ? $o['admin_user'] : '');
    $pass = isset($o['admin_pass']) ? (string)$o['admin_pass'] : '';
    if (!preg_match('/^[A-Za-z0-9_.@-]{3,60}$/', $admin)) {
        return 'Admin username ৩-৬০ অক্ষরের হতে হবে (ইংরেজি অক্ষর, সংখ্যা, . _ - @)';
    }
    if (strlen($pass) < 8) {
        return 'Admin password কমপক্ষে ৮ অক্ষরের হতে হবে';
    }
    foreach (install_requirements() as $req) {
        if ($req[2] && !$req[1]) {
            return 'প্রয়োজনীয় রিকোয়ারমেন্ট পূরণ হয়নি: ' . $req[0];
        }
    }
    ensure_storage();

    $db = array('driver' => $driver);
    try {
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . STORAGE_DIR . '/app.sqlite');
        } else {
            $db['host'] = trim($o['db_host']) ?: 'localhost';
            $db['port'] = (int)(isset($o['db_port']) ? $o['db_port'] : 0);
            $db['name'] = trim($o['db_name']);
            $db['user'] = trim($o['db_user']);
            $db['pass'] = (string)$o['db_pass'];
            if ($db['name'] === '' || $db['user'] === '') {
                return 'Database name ও user দিন';
            }
            $pdo = new PDO(
                'mysql:host=' . $db['host'] . ($db['port'] ? ';port=' . $db['port'] : '') . ';dbname=' . $db['name'] . ';charset=utf8mb4',
                $db['user'],
                $db['pass']
            );
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (install_schema($driver) as $sql) {
            $pdo->exec($sql);
        }
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users');
        $exists->execute();
        if ((int)$exists->fetchColumn() > 0) {
            return 'এই ডাটাবেসে আগেই ইউজার আছে — অন্য একটি খালি ডাটাবেস ব্যবহার করুন বা পুরনো টেবিল মুছুন';
        }
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO users (username, pass_hash, created_at) VALUES (?,?,?)')->execute(array($admin, password_hash($pass, PASSWORD_DEFAULT), $now));
        $set = $pdo->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
        $set->execute(array('site_name', trim(isset($o['site_name']) ? $o['site_name'] : '')));
        $set->execute(array('site_url', trim(isset($o['site_url']) ? $o['site_url'] : '')));
        $set->execute(array('author', trim(isset($o['author']) ? $o['author'] : '')));
    } catch (Exception $e) {
        return 'ডাটাবেস এরর: ' . $e->getMessage();
    }

    $cfg = array(
        'installed' => true,
        'db' => $db,
        'secret' => bin2hex(random_bytes(32)),
        'timezone' => in_array(isset($o['timezone']) ? $o['timezone'] : '', timezone_identifiers_list(), true) ? $o['timezone'] : 'Asia/Dubai',
        'installed_at' => date('c'),
    );
    $php = "<?php\n// Generated by SEO Studio installer. Keep this file private.\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents(APP_ROOT . '/config.php', $php, LOCK_EX) === false) {
        return 'config.php লেখা যায়নি — ফোল্ডারের write পারমিশন দিন';
    }
    @chmod(APP_ROOT . '/config.php', 0640);
    return true;
}
