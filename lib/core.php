<?php
/**
 * SEO Studio — core helpers (DB, session, auth, settings, utils)
 * Compatible with PHP 7.4+ / 8.x
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
    define('STORAGE_DIR', APP_ROOT . '/storage');
    define('APP_VERSION', '1.0.0');
}

/** ini_set that never fatals on hosts that list it in disable_functions. */
function safe_ini($k, $v)
{
    return function_exists('ini_set') ? @ini_set($k, $v) : false;
}

error_reporting(E_ALL);
safe_ini('display_errors', '0');
safe_ini('log_errors', '1');
$__ml = trim((string)ini_get('memory_limit'));
$__mb = ($__ml === '-1') ? -1 : (int)$__ml * (stripos($__ml, 'g') !== false ? 1024 : 1);
if ($__mb !== -1 && $__mb < 512) {
    safe_ini('memory_limit', '512M'); // best effort; some hosts disallow it
}
if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

function app_cfg()
{
    static $c = null;
    if ($c === null) {
        $f = APP_ROOT . '/config.php';
        $c = is_file($f) ? (require $f) : array();
        if (!is_array($c)) {
            $c = array();
        }
    }
    return $c;
}

date_default_timezone_set(!empty(app_cfg()['timezone']) ? app_cfg()['timezone'] : 'Asia/Dubai');

function is_installed()
{
    return !empty(app_cfg()['installed']);
}

/* ---------------- Database ---------------- */

function db()
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = app_cfg();
    $d = isset($c['db']) ? $c['db'] : array();
    $driver = isset($d['driver']) ? $d['driver'] : 'mysql';
    if ($driver === 'sqlite') {
        $pdo = new PDO('sqlite:' . STORAGE_DIR . '/app.sqlite');
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $host = isset($d['host']) ? $d['host'] : 'localhost';
        $port = !empty($d['port']) ? ';port=' . (int)$d['port'] : '';
        $pdo = new PDO(
            'mysql:host=' . $host . $port . ';dbname=' . $d['name'] . ';charset=utf8mb4',
            $d['user'],
            $d['pass']
        );
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function q($sql, $params = array())
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function db_driver()
{
    $c = app_cfg();
    return isset($c['db']['driver']) ? $c['db']['driver'] : 'mysql';
}

/* ---------------- Session / CSRF / Auth ---------------- */

function start_session()
{
    if (!empty($GLOBALS['_sess_started'])) {
        return; // already read this request (may have been released with session_write_close)
    }
    if (session_status() === PHP_SESSION_NONE) {
        $GLOBALS['_sess_started'] = true;
        session_name('seostudio');
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        session_start();
    }
}

function csrf_token()
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok($t)
{
    start_session();
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function current_user_id()
{
    start_session();
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail($msg, $code = 400)
{
    json_out(array('ok' => false, 'error' => $msg), $code);
}

/* ---------------- Settings (encrypted secrets) ---------------- */

function default_settings()
{
    return array(
        'site_name' => '',
        'site_url' => '',
        'author' => '',
        'copyright' => '© {year} {site_name}',
        'lang' => 'en',
        'ai_primary' => 'gemini',
        'upload_base_url' => '',
        'def_opt' => json_encode(array(
            'max_width' => 1600, 'max_height' => 0, 'format' => 'webp',
            'quality' => 80, 'target_kb' => 0, 'responsive' => false,
        )),
    );
}

function setting($k, $default = '')
{
    if (!isset($GLOBALS['_settings'])) {
        $GLOBALS['_settings'] = array();
        foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) {
            $GLOBALS['_settings'][$r['k']] = $r['v'];
        }
    }
    if (isset($GLOBALS['_settings'][$k]) && $GLOBALS['_settings'][$k] !== '') {
        return $GLOBALS['_settings'][$k];
    }
    $d = default_settings();
    if ($default !== '') {
        return $default;
    }
    return isset($d[$k]) ? $d[$k] : '';
}

function setting_set($k, $v)
{
    q('REPLACE INTO settings (k, v) VALUES (?, ?)', array($k, (string)$v));
    unset($GLOBALS['_settings']);
}

function secret_key()
{
    $c = app_cfg();
    return hash('sha256', isset($c['secret']) ? $c['secret'] : 'seo-studio', true);
}

function enc($plain)
{
    if ($plain === '' || $plain === null) {
        return '';
    }
    $iv = random_bytes(16);
    $c = openssl_encrypt($plain, 'aes-256-cbc', secret_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $c);
}

function dec($s)
{
    if ($s === '' || $s === null) {
        return '';
    }
    $raw = base64_decode($s, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $p = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $p === false ? '' : $p;
}

/* ---------------- Utils ---------------- */

function json_dec($s, $default = array())
{
    $x = json_decode((string)$s, true);
    return is_array($x) ? $x : $default;
}

function hbytes($b)
{
    $b = (float)$b;
    if ($b < 1024) {
        return (int)$b . ' B';
    }
    if ($b < 1048576) {
        return round($b / 1024, 1) . ' KB';
    }
    return round($b / 1048576, 2) . ' MB';
}

function slugify($s, $max = 70)
{
    $s = (string)$s;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') {
            $s = $t;
        }
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if (strlen($s) > $max) {
        $s = substr($s, 0, $max);
        $p = strrpos($s, '-');
        if ($p !== false && $p > $max * 0.6) {
            $s = substr($s, 0, $p);
        }
        $s = trim($s, '-');
    }
    return $s;
}

function fit_text($s, $max)
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    $cut = mb_substr($s, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.7) {
        $cut = mb_substr($cut, 0, $sp);
    }
    $cut = rtrim($cut, " ,;:-–—|/&");
    // don't leave a dangling connector word at the end ("... Chotpoti and")
    $cut = preg_replace('/\s+(and|or|the|of|in|for|with|to|a|an|at|on|by|from|&|এবং|ও|ও|এর|থেকে|জন্য)$/iu', '', $cut);
    return rtrim($cut, " ,;:-–—|/&");
}

function expand_tpl($s)
{
    return strtr((string)$s, array(
        '{year}' => date('Y'),
        '{site_name}' => setting('site_name'),
        '{author}' => setting('author'),
    ));
}

function read_input()
{
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $x = json_decode(file_get_contents('php://input'), true);
        return is_array($x) ? $x : array();
    }
    return $_POST;
}

function ensure_storage()
{
    foreach (array('', '/uploads', '/output', '/thumbs', '/tmp') as $d) {
        $p = STORAGE_DIR . $d;
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
    }
    $ht = STORAGE_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
}
