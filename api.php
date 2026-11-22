<?php
/**
 * SEO Studio — JSON API + file/export endpoints
 */
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/image.php';
require __DIR__ . '/lib/meta.php';
require __DIR__ . '/lib/ai.php';

if (!is_installed()) {
    http_response_code(503);
    exit('Not installed');
}
ensure_storage();
start_session();

$action = isset($_GET['action']) ? preg_replace('/[^a-z_]/', '', $_GET['action']) : '';
$downloads = array('file', 'zip', 'csv', 'sitemap');

if (!current_user_id()) {
    if (in_array($action, $downloads, true)) {
        http_response_code(401);
        exit('Login required');
    }
    fail('সেশন শেষ হয়ে গেছে — আবার লগইন করুন', 401);
}

try {
    if (in_array($action, $downloads, true)) {
        session_write_close();
        $fn = 'dl_' . $action;
        $fn();
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('POST required', 405);
    }
    $hdr = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    // bootstrap only hands the (same-origin readable) token to the page, so it is exempt.
    if ($action !== 'bootstrap' && !csrf_ok($hdr)) {
        fail('Security token expired — পেজ রিলোড করুন', 403);
    }
    $fn = 'act_' . $action;
    if (!function_exists($fn)) {
        fail('Unknown action', 404);
    }
    $in = read_input();
    if ($action !== 'bootstrap') {
        session_write_close(); // don't block other requests (thumbnails etc.) during long AI/image work
    }
    $res = $fn($in);
    json_out(array_merge(array('ok' => true), $res));
} catch (Throwable $e) {
    error_log('[seo-studio] ' . $action . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    fail($e->getMessage(), 500);
}

/* =========================== helpers =========================== */

function def_opt()
{
    $d = json_dec(setting('def_opt'));
    return array_merge(array('max_width' => 1600, 'max_height' => 0, 'format' => 'webp', 'quality' => 80, 'target_kb' => 0, 'responsive' => false), $d);
}

function img_get($id)
{
    $r = q('SELECT * FROM images WHERE id = ? AND user_id = ?', array((int)$id, current_user_id()))->fetch();
    if (!$r) {
        fail('ছবি পাওয়া যায়নি', 404);
    }
    return $r;
}

function seo_defaults($r)
{
    $base = pathinfo($r['orig_name'], PATHINFO_FILENAME);
    return array(
        'focus' => '', 'lang' => setting('lang'), 'title' => '', 'alt' => '', 'caption' => '',
        'description' => '', 'keywords' => array(), 'filename' => slugify($base) ?: 'image-' . $r['id'],
    );
}

function final_name($r, $withExt = true)
{
    $seo = array_merge(seo_defaults($r), json_dec($r['seo_json']));
    $slug = slugify($seo['filename']) ?: (slugify(pathinfo($r['orig_name'], PATHINFO_FILENAME)) ?: 'image-' . $r['id']);
    if (!empty($GLOBALS['force_ext'])) {
        $ext = $GLOBALS['force_ext'];
    } elseif ($r['out_ext']) {
        $ext = $r['out_ext'];
    } else {
        // not processed yet: show the extension the output will get
        $fmt = json_dec($r['opt_json']);
        $fmt = isset($fmt['format']) ? $fmt['format'] : 'webp';
        $map = array('jpeg' => 'jpg', 'webp' => function_exists('imagewebp') ? 'webp' : 'jpg', 'png' => 'png');
        $ext = isset($map[$fmt]) ? $map[$fmt] : img_ext_for_mime($r['mime']);
    }
    return $withExt ? $slug . '.' . $ext : $slug;
}

function img_pub($r)
{
    return array(
        'id' => (int)$r['id'],
        'orig_name' => $r['orig_name'],
        'mime' => $r['mime'],
        'width' => (int)$r['width'],
        'height' => (int)$r['height'],
        'orig_size' => (int)$r['orig_size'],
        'out_ext' => $r['out_ext'],
        'out_mime' => $r['out_mime'],
        'out_w' => (int)$r['out_w'],
        'out_h' => (int)$r['out_h'],
        'out_size' => (int)$r['out_size'],
        'status' => $r['status'],
        'seo' => array_merge(seo_defaults($r), json_dec($r['seo_json'])),
        'meta' => json_dec($r['meta_json']),
        'opt' => array_merge(def_opt(), json_dec($r['opt_json'])),
        'variants' => json_dec($r['variants_json']),
        'final_name' => final_name($r),
        'v' => strtotime($r['updated_at']) ?: 0,
    );
}

function out_path($r)
{
    return $r['out_ext'] ? STORAGE_DIR . '/output/' . $r['id'] . '.' . $r['out_ext'] : '';
}

function pick($a, $b)
{
    return (isset($a) && trim((string)$a) !== '') ? trim((string)$a) : $b;
}

/** Final metadata that will be embedded into the optimized image. */
function meta_fields_for($r)
{
    $seo = array_merge(seo_defaults($r), json_dec($r['seo_json']));
    $m = json_dec($r['meta_json']);
    return array(
        'title' => pick(isset($m['title']) ? $m['title'] : '', $seo['title']),
        'description' => pick(isset($m['description']) ? $m['description'] : '', $seo['description'] !== '' ? $seo['description'] : $seo['alt']),
        'keywords' => $seo['keywords'],
        'author' => pick(isset($m['author']) ? $m['author'] : '', setting('author')),
        'copyright' => pick(isset($m['copyright']) ? $m['copyright'] : '', trim(expand_tpl(setting('copyright')))),
        'website' => pick(isset($m['website']) ? $m['website'] : '', setting('site_url')),
        'software' => pick(isset($m['software']) ? $m['software'] : '', ''),
        'make' => isset($m['make']) ? $m['make'] : '',
        'model' => isset($m['model']) ? $m['model'] : '',
        'datetime' => isset($m['datetime']) ? $m['datetime'] : '',
        'lat' => isset($m['lat']) ? $m['lat'] : '',
        'lon' => isset($m['lon']) ? $m['lon'] : '',
    );
}

function clean_str($v, $max)
{
    return mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string)$v)), 0, $max);
}

function url_for($r)
{
    $base = trim(setting('upload_base_url'));
    if ($base !== '' && substr($base, -1) !== '/') {
        $base .= '/';
    }
    return $base . rawurlencode(final_name($r));
}

function selected_rows($csv)
{
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)$csv))));
    if (!$ids) {
        $rows = q('SELECT * FROM images WHERE user_id = ? ORDER BY id', array(current_user_id()))->fetchAll();
    } else {
        $rows = array();
        foreach ($ids as $id) {
            $r = q('SELECT * FROM images WHERE id = ? AND user_id = ?', array($id, current_user_id()))->fetch();
            if ($r) {
                $rows[] = $r;
            }
        }
    }
    return $rows;
}

/* =========================== actions =========================== */

function act_bootstrap($in)
{
    $providers = array();
    foreach (ai_providers() as $k => $p) {
        $key = ai_key($k);
        $providers[$k] = array('label' => $p['label'], 'has_key' => $key !== '', 'hint' => $key !== '' ? '••••' . substr($key, -4) : '', 'model' => ai_model($k), 'default_model' => $p['model']);
    }
    $rows = q('SELECT * FROM images WHERE user_id = ? ORDER BY id DESC', array(current_user_id()))->fetchAll();
    return array(
        'csrf' => csrf_token(),
        'version' => APP_VERSION,
        'settings' => array(
            'site_name' => setting('site_name'), 'site_url' => setting('site_url'), 'author' => setting('author'),
            'copyright' => setting('copyright'), 'lang' => setting('lang'), 'ai_primary' => setting('ai_primary'),
            'upload_base_url' => setting('upload_base_url'), 'def_opt' => def_opt(), 'providers' => $providers,
        ),
        'caps' => array(
            'webp' => function_exists('imagewebp'), 'exif' => function_exists('exif_read_data'),
            'zip' => class_exists('ZipArchive'), 'upload_max' => ini_get('upload_max_filesize'),
        ),
        'images' => array_map('img_pub', $rows),
    );
}

function act_upload($in)
{
    if (empty($_FILES['file'])) {
        fail('কোনো ফাইল পাওয়া যায়নি (ফাইল সাইজ সার্ভার লিমিটের চেয়ে বড় হতে পারে — upload_max_filesize: ' . ini_get('upload_max_filesize') . ')');
    }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $m = array(UPLOAD_ERR_INI_SIZE => 'ফাইল সার্ভারের upload_max_filesize (' . ini_get('upload_max_filesize') . ') লিমিটের চেয়ে বড়', UPLOAD_ERR_FORM_SIZE => 'ফাইল অনেক বড়', UPLOAD_ERR_PARTIAL => 'আপলোড অসম্পূর্ণ', UPLOAD_ERR_NO_FILE => 'ফাইল নেই');
        fail(isset($m[$f['error']]) ? $m[$f['error']] : 'Upload error ' . $f['error']);
    }
    $info = img_info($f['tmp_name']);
    if (!$info) {
        fail('অসমর্থিত ছবি (শুধু JPG, PNG, WebP, GIF): ' . $f['name']);
    }
    if ($info['w'] * $info['h'] > 50000000) {
        fail('ছবির রেজোলিউশন অনেক বড় (সর্বোচ্চ ৫০ মেগাপিক্সেল): ' . $f['name']);
    }
    $rand = bin2hex(random_bytes(10));
    $stored = $rand . '.' . img_ext_for_mime($info['mime']);
    if (!move_uploaded_file($f['tmp_name'], STORAGE_DIR . '/uploads/' . $stored)) {
        fail('ফাইল সেভ করা যায়নি — storage/ ফোল্ডারের পারমিশন চেক করুন');
    }
    $thumb = $rand . '.jpg';
    try {
        file_put_contents(STORAGE_DIR . '/thumbs/' . $thumb, img_sample(STORAGE_DIR . '/uploads/' . $stored, $info['mime'], 480, 78));
    } catch (Exception $e) {
        @unlink(STORAGE_DIR . '/uploads/' . $stored);
        fail($e->getMessage());
    }
    $now = date('Y-m-d H:i:s');
    q(
        'INSERT INTO images (user_id, orig_name, orig_file, thumb_file, mime, width, height, orig_size, status, seo_json, meta_json, opt_json, variants_json, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array(current_user_id(), clean_str(basename($f['name']), 250), $stored, $thumb, $info['mime'], $info['w'], $info['h'], filesize(STORAGE_DIR . '/uploads/' . $stored), 'new', '{}', '{}', json_encode(def_opt()), '[]', $now, $now)
    );
    return array('image' => img_pub(img_get(db()->lastInsertId())));
}

function act_list($in)
{
    $rows = q('SELECT * FROM images WHERE user_id = ? ORDER BY id DESC', array(current_user_id()))->fetchAll();
    return array('images' => array_map('img_pub', $rows));
}

/** Merge submitted (possibly unsaved) form values over the stored ones. Returns array($seo, $meta, $opt). */
function merge_form($r, $in)
{
    $seo = array_merge(seo_defaults($r), json_dec($r['seo_json']));
    $meta = json_dec($r['meta_json']);
    $opt = array_merge(def_opt(), json_dec($r['opt_json']));

    if (isset($in['seo']) && is_array($in['seo'])) {
        $s = $in['seo'];
        foreach (array('focus' => 120, 'title' => 120, 'alt' => 300, 'caption' => 300, 'description' => 1200) as $k => $max) {
            if (array_key_exists($k, $s)) {
                $seo[$k] = clean_str($s[$k], $max);
            }
        }
        if (isset($s['lang'])) {
            $seo['lang'] = $s['lang'] === 'bn' ? 'bn' : 'en';
        }
        if (array_key_exists('keywords', $s)) {
            $seo['keywords'] = ai_clean_keywords($s['keywords'], 30);
        }
        if (array_key_exists('filename', $s)) {
            $fn = slugify(preg_replace('/\.[a-z0-9]{2,4}$/i', '', (string)$s['filename']));
            $seo['filename'] = $fn !== '' ? $fn : seo_defaults($r)['filename'];
        }
    }
    if (isset($in['meta']) && is_array($in['meta'])) {
        foreach (array('title' => 200, 'description' => 2000, 'author' => 100, 'copyright' => 200, 'website' => 250, 'software' => 100, 'make' => 60, 'model' => 60, 'datetime' => 30) as $k => $max) {
            if (array_key_exists($k, $in['meta'])) {
                $meta[$k] = clean_str($in['meta'][$k], $max);
            }
        }
        foreach (array('lat' => 90, 'lon' => 180) as $k => $lim) {
            if (array_key_exists($k, $in['meta'])) {
                $v = $in['meta'][$k];
                $meta[$k] = ($v !== '' && $v !== null && is_numeric($v) && abs((float)$v) <= $lim) ? (float)$v : '';
            }
        }
    }
    if (isset($in['opt']) && is_array($in['opt'])) {
        $o = $in['opt'];
        if (isset($o['max_width'])) {
            $opt['max_width'] = max(0, min(12000, (int)$o['max_width']));
        }
        if (isset($o['max_height'])) {
            $opt['max_height'] = max(0, min(12000, (int)$o['max_height']));
        }
        if (isset($o['quality'])) {
            $opt['quality'] = max(30, min(100, (int)$o['quality']));
        }
        if (isset($o['target_kb'])) {
            $opt['target_kb'] = max(0, min(10000, (int)$o['target_kb']));
        }
        if (isset($o['format']) && in_array($o['format'], array('keep', 'jpeg', 'webp', 'png'), true)) {
            $opt['format'] = $o['format'];
        }
        if (isset($o['responsive'])) {
            $opt['responsive'] = (bool)$o['responsive'];
        }
    }
    return array($seo, $meta, $opt);
}

function act_save($in)
{
    $r = img_get(isset($in['id']) ? $in['id'] : 0);
    list($seo, $meta, $opt) = merge_form($r, $in);
    q('UPDATE images SET seo_json=?, meta_json=?, opt_json=?, updated_at=? WHERE id=?', array(json_encode($seo, JSON_UNESCAPED_UNICODE), json_encode($meta, JSON_UNESCAPED_UNICODE), json_encode($opt), date('Y-m-d H:i:s'), $r['id']));
    return array('image' => img_pub(img_get($r['id'])));
}

/**
 * Live preview WITHOUT saving: runs the real pipeline on the unsaved form values into a temp file,
 * reads the metadata back out of it, and compares with the original file.
 */
function act_preview($in)
{
    $r = img_get(isset($in['id']) ? $in['id'] : 0);
    list($seo, $meta, $opt) = merge_form($r, $in);
    $v = $r;
    $v['seo_json'] = json_encode($seo, JSON_UNESCAPED_UNICODE);
    $v['meta_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
    $v['opt_json'] = json_encode($opt);
    $v['out_ext'] = '';
    $fields = meta_fields_for($v);

    $base = STORAGE_DIR . '/tmp/prev-' . bin2hex(random_bytes(6));
    $res = img_process(STORAGE_DIR . '/uploads/' . $r['orig_file'], $r['mime'], $base, $opt);
    $files = array($res['path']);
    try {
        meta_apply($res['path'], $res['mime'], $fields);
        clearstatcache();
        $size = filesize($res['path']);
        $after = meta_read($res['path']);
        $uri = null;
        if ($size <= 5 * 1024 * 1024) {
            $uri = 'data:' . $res['mime'] . ';base64,' . base64_encode(file_get_contents($res['path']));
        }
    } finally {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
    $before = meta_read(STORAGE_DIR . '/uploads/' . $r['orig_file']);
    $tk = (int)$opt['target_kb'];
    $ext = $res['ext'];
    return array(
        'preview' => array(
            'data_uri' => $uri, 'w' => $res['w'], 'h' => $res['h'], 'size' => $size, 'ext' => $ext, 'mime' => $res['mime'],
            'orig_size' => (int)$r['orig_size'], 'orig_w' => (int)$r['width'], 'orig_h' => (int)$r['height'], 'orig_mime' => $r['mime'],
            'saving' => $r['orig_size'] > 0 ? (int)round(100 - $size * 100 / $r['orig_size']) : 0,
            'target_met' => ($tk <= 0 || $ext === 'png' || $size <= $tk * 1024),
        ),
        'final_name' => final_name($v),
        'url' => url_for($v),
        'before' => $before,
        'after' => $after,
        'seo' => $seo,
    );
}

function act_ai_image($in)
{
    $r = img_get(isset($in['id']) ? $in['id'] : 0);
    $lang = (isset($in['lang']) && $in['lang'] === 'bn') ? 'bn' : 'en';
    $focus = clean_str(isset($in['focus']) ? $in['focus'] : '', 120);
    $ctx = clean_str(isset($in['context']) ? $in['context'] : '', 600);
    $force = isset($in['provider']) && $in['provider'] !== '' ? $in['provider'] : null;

    $bin = img_sample(STORAGE_DIR . '/uploads/' . $r['orig_file'], $r['mime'], 1024, 80);
    list($sys, $usr) = prompt_image_seo(array('lang' => $lang, 'focus' => $focus, 'context' => $ctx, 'site' => setting('site_name'), 'orig' => $r['orig_name']));
    list($j, $prov) = ai_json($sys, $usr, array('mime' => 'image/jpeg', 'b64' => base64_encode($bin)), $force);

    $res = array(
        'title' => isset($j['title']) ? (string)$j['title'] : '',
        'alt_text' => isset($j['alt_text']) ? (string)$j['alt_text'] : (isset($j['alt']) ? (string)$j['alt'] : ''),
        'caption' => isset($j['caption']) ? (string)$j['caption'] : '',
        'description' => isset($j['description']) ? (string)$j['description'] : '',
    );
    if ($res['title'] === '' && $res['alt_text'] === '') {
        throw new RuntimeException('AI খালি উত্তর দিয়েছে — আবার চেষ্টা করুন');
    }
    $res = ai_enforce($res, image_seo_limits(), $lang, $prov);

    $seo = array_merge(seo_defaults($r), json_dec($r['seo_json']));
    $seo['lang'] = $lang;
    $seo['focus'] = $focus !== '' ? $focus : $seo['focus'];
    $seo['title'] = $res['title'];
    $seo['alt'] = $res['alt_text'];
    $seo['caption'] = $res['caption'];
    $seo['description'] = $res['description'];
    $seo['keywords'] = ai_clean_keywords(isset($j['keywords']) ? $j['keywords'] : array(), 15);
    $fn = slugify(isset($j['filename']) ? $j['filename'] : '');
    if ($fn === '' && $focus !== '') {
        $fn = slugify($focus);
    }
    if ($fn !== '') {
        $seo['filename'] = $fn;
    }
    q('UPDATE images SET seo_json=?, updated_at=? WHERE id=?', array(json_encode($seo, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $r['id']));
    return array('image' => img_pub(img_get($r['id'])), 'provider' => $prov);
}

function act_process($in)
{
    $r = img_get(isset($in['id']) ? $in['id'] : 0);
    $opt = array_merge(def_opt(), json_dec($r['opt_json']));
    $src = STORAGE_DIR . '/uploads/' . $r['orig_file'];
    $base = STORAGE_DIR . '/output/' . $r['id'];
    foreach (array_merge(glob($base . '.*') ?: array(), glob($base . '_*') ?: array()) as $old) {
        @unlink($old);
    }
    $res = img_process($src, $r['mime'], $base, $opt);
    $fields = meta_fields_for($r);
    meta_apply($res['path'], $res['mime'], $fields);
    clearstatcache();
    $size = filesize($res['path']);

    // If converting made an un-resized image BIGGER than the original, try the original format and keep the smaller one.
    $keptFormat = false;
    if ($size >= (int)$r['orig_size'] && $res['w'] === (int)$r['width'] && $opt['format'] !== 'keep') {
        $ko = $opt;
        $ko['format'] = 'keep';
        $alt = img_process($src, $r['mime'], $base . '_keep', $ko);
        meta_apply($alt['path'], $alt['mime'], $fields);
        clearstatcache();
        if (filesize($alt['path']) < $size) {
            @unlink($res['path']);
            $final = $base . '.' . $alt['ext'];
            if (@rename($alt['path'], $final)) {
                $alt['path'] = $final;
                $res = $alt;
                $size = filesize($final);
                $keptFormat = true;
            }
        } else {
            @unlink($alt['path']);
        }
    }

    $variants = array();
    if (!empty($opt['responsive'])) {
        foreach (array(480, 768, 1200, 1600) as $w) {
            if ($w >= $res['w'] - 40) {
                continue;
            }
            $vo = $opt;
            $vo['max_width'] = $w;
            $vo['max_height'] = 0;
            $vo['target_kb'] = 0;
            $vo['format'] = $res['ext'] === 'jpg' ? 'jpeg' : $res['ext'];
            $v = img_process($src, $r['mime'], $base . '_' . $w, $vo);
            meta_apply($v['path'], $v['mime'], $fields);
            clearstatcache();
            $variants[] = array('w' => $v['w'], 'h' => $v['h'], 'size' => filesize($v['path']));
        }
    }
    q(
        'UPDATE images SET out_ext=?, out_mime=?, out_w=?, out_h=?, out_size=?, variants_json=?, status=?, updated_at=? WHERE id=?',
        array($res['ext'], $res['mime'], $res['w'], $res['h'], $size, json_encode($variants), 'done', date('Y-m-d H:i:s'), $r['id'])
    );
    $row = img_get($r['id']);
    $tk = (int)$opt['target_kb'];
    return array(
        'image' => img_pub($row),
        'verify' => meta_read(out_path($row)),
        'target_met' => ($tk <= 0 || $res['ext'] === 'png' || $size <= $tk * 1024),
        'format_kept' => $keptFormat,
        'bigger' => ($size >= (int)$r['orig_size']),
    );
}

function act_read_meta($in)
{
    $r = img_get(isset($in['id']) ? $in['id'] : 0);
    $which = (isset($in['which']) && $in['which'] === 'output') ? 'output' : 'original';
    $path = $which === 'output' ? out_path($r) : STORAGE_DIR . '/uploads/' . $r['orig_file'];
    if (!$path || !is_file($path)) {
        fail($which === 'output' ? 'এখনও Process করা হয়নি' : 'ফাইল পাওয়া যায়নি');
    }
    return array('meta' => meta_read($path), 'which' => $which);
}

function act_delete($in)
{
    $ids = isset($in['ids']) && is_array($in['ids']) ? array_map('intval', $in['ids']) : array();
    $n = 0;
    foreach ($ids as $id) {
        $r = q('SELECT * FROM images WHERE id=? AND user_id=?', array($id, current_user_id()))->fetch();
        if (!$r) {
            continue;
        }
        @unlink(STORAGE_DIR . '/uploads/' . $r['orig_file']);
        @unlink(STORAGE_DIR . '/thumbs/' . $r['thumb_file']);
        foreach (array_merge(glob(STORAGE_DIR . '/output/' . $r['id'] . '.*') ?: array(), glob(STORAGE_DIR . '/output/' . $r['id'] . '_*') ?: array()) as $f) {
            @unlink($f);
        }
        q('DELETE FROM images WHERE id=?', array($id));
        $n++;
    }
    return array('deleted' => $n);
}

function act_ai_meta($in)
{
    $o = array();
    foreach (array('type' => 'page_type', 'topic' => 'topic', 'summary' => 'summary', 'focus' => 'focus', 'secondary' => 'secondary', 'brand' => 'brand', 'url' => 'url', 'tone' => 'tone', 'audience' => 'audience', 'extra' => 'extra') as $k => $_) {
        $o[$k] = clean_str(isset($in[$k]) ? $in[$k] : '', $k === 'summary' ? 4000 : 400);
    }
    if ($o['topic'] === '' && $o['summary'] === '' && $o['focus'] === '') {
        fail('অন্তত পেজের টপিক বা Focus keyword দিন');
    }
    if ($o['type'] === '') {
        $o['type'] = 'WebPage';
    }
    if ($o['brand'] === '') {
        $o['brand'] = setting('site_name');
    }
    $o['lang'] = (isset($in['lang']) && $in['lang'] === 'bn') ? 'bn' : 'en';
    $force = isset($in['provider']) && $in['provider'] !== '' ? $in['provider'] : null;
    list($sys, $usr) = prompt_meta($o);
    list($j, $prov) = ai_json($sys, $usr, null, $force);

    $titles = array();
    foreach ((array)(isset($j['titles']) ? $j['titles'] : array()) as $t) {
        if (is_string($t) && trim($t) !== '') {
            $titles[] = trim($t);
        }
    }
    $descs = array();
    foreach ((array)(isset($j['descriptions']) ? $j['descriptions'] : array()) as $t) {
        if (is_string($t) && trim($t) !== '') {
            $descs[] = trim($t);
        }
    }
    $mt = isset($j['meta_title']) ? (string)$j['meta_title'] : ($titles ? $titles[0] : '');
    $md = isset($j['meta_description']) ? (string)$j['meta_description'] : ($descs ? $descs[0] : '');
    if ($mt === '' && $md === '') {
        throw new RuntimeException('AI খালি উত্তর দিয়েছে — আবার চেষ্টা করুন');
    }
    $flat = array('meta_title' => $mt, 'meta_description' => $md);
    foreach ($titles as $i => $t) {
        $flat['t' . $i] = $t;
    }
    foreach ($descs as $i => $t) {
        $flat['d' . $i] = $t;
    }
    foreach (array('og_title', 'og_description', 'twitter_title', 'twitter_description', 'h1') as $k) {
        $flat[$k] = isset($j[$k]) ? (string)$j[$k] : '';
    }
    $limits = array('meta_title' => 60, 'meta_description' => 160, 'og_title' => 65, 'og_description' => 160, 'twitter_title' => 65, 'twitter_description' => 160, 'h1' => 80);
    foreach ($titles as $i => $_) {
        $limits['t' . $i] = 60;
    }
    foreach ($descs as $i => $_) {
        $limits['d' . $i] = 160;
    }
    $flat = ai_enforce($flat, $limits, $o['lang'], $prov);
    $titlesOut = array();
    foreach ($titles as $i => $_) {
        $titlesOut[] = $flat['t' . $i];
    }
    $descsOut = array();
    foreach ($descs as $i => $_) {
        $descsOut[] = $flat['d' . $i];
    }
    if (!$titlesOut) {
        $titlesOut[] = $flat['meta_title'];
    }
    if (!$descsOut) {
        $descsOut[] = $flat['meta_description'];
    }
    $schemas = array('WebPage', 'Article', 'Product', 'Service', 'LocalBusiness', 'Organization', 'FAQPage', 'CollectionPage', 'ContactPage', 'AboutPage');
    $schema = isset($j['schema_type']) && in_array($j['schema_type'], $schemas, true) ? $j['schema_type'] : 'WebPage';
    return array('provider' => $prov, 'result' => array(
        'meta_title' => $flat['meta_title'], 'meta_description' => $flat['meta_description'],
        'titles' => $titlesOut, 'descriptions' => $descsOut,
        'keywords' => ai_clean_keywords(isset($j['keywords']) ? $j['keywords'] : array(), 15),
        'og_title' => $flat['og_title'] ?: $flat['meta_title'], 'og_description' => $flat['og_description'] ?: $flat['meta_description'],
        'twitter_title' => $flat['twitter_title'] ?: $flat['meta_title'], 'twitter_description' => $flat['twitter_description'] ?: $flat['meta_description'],
        'slug' => slugify(isset($j['slug']) ? $j['slug'] : '') ?: slugify($o['focus'] ?: $o['topic']), 'h1' => $flat['h1'], 'schema_type' => $schema,
    ));
}

function act_ai_optimize($in)
{
    $type = isset($in['type']) ? $in['type'] : 'title';
    if (!in_array($type, array('title', 'meta_description', 'product_title', 'product_description'), true)) {
        $type = 'title';
    }
    $text = clean_str(isset($in['text']) ? $in['text'] : '', 6000);
    if ($text === '') {
        fail('অপটিমাইজ করার জন্য টেক্সট দিন');
    }
    $lang = (isset($in['lang']) && $in['lang'] === 'bn') ? 'bn' : 'en';
    $o = array('type' => $type, 'text' => $text, 'focus' => clean_str(isset($in['focus']) ? $in['focus'] : '', 120), 'brand' => clean_str(isset($in['brand']) ? $in['brand'] : setting('site_name'), 80), 'lang' => $lang);
    $force = isset($in['provider']) && $in['provider'] !== '' ? $in['provider'] : null;
    list($sys, $usr) = prompt_optimize($o);
    list($j, $prov) = ai_json($sys, $usr, null, $force);
    $max = optimize_limits($type);
    $flat = array();
    $whys = array();
    foreach ((array)(isset($j['variants']) ? $j['variants'] : array()) as $i => $v) {
        $t = is_array($v) ? (isset($v['text']) ? (string)$v['text'] : '') : (string)$v;
        if (trim($t) === '') {
            continue;
        }
        $flat['v' . count($flat)] = $t;
        $whys[] = is_array($v) && isset($v['why']) ? (string)$v['why'] : '';
    }
    if (!$flat) {
        throw new RuntimeException('AI কোনো ভার্সন দেয়নি — আবার চেষ্টা করুন');
    }
    $limits = array();
    foreach ($flat as $k => $_) {
        $limits[$k] = $max;
    }
    $flat = ai_enforce($flat, $limits, $lang, $prov);
    $out = array();
    $i = 0;
    foreach ($flat as $t) {
        $out[] = array('text' => $t, 'why' => $whys[$i]);
        $i++;
    }
    return array('provider' => $prov, 'variants' => $out);
}

function act_settings_save($in)
{
    foreach (array('site_name' => 120, 'site_url' => 250, 'author' => 120, 'copyright' => 250, 'upload_base_url' => 300) as $k => $max) {
        if (array_key_exists($k, $in)) {
            setting_set($k, clean_str($in[$k], $max));
        }
    }
    if (isset($in['lang'])) {
        setting_set('lang', $in['lang'] === 'bn' ? 'bn' : 'en');
    }
    if (isset($in['ai_primary']) && isset(ai_providers()[$in['ai_primary']])) {
        setting_set('ai_primary', $in['ai_primary']);
    }
    if (isset($in['def_opt']) && is_array($in['def_opt'])) {
        $o = $in['def_opt'];
        $cur = def_opt();
        $cur['max_width'] = max(0, min(12000, (int)(isset($o['max_width']) ? $o['max_width'] : $cur['max_width'])));
        $cur['max_height'] = max(0, min(12000, (int)(isset($o['max_height']) ? $o['max_height'] : $cur['max_height'])));
        $cur['quality'] = max(30, min(100, (int)(isset($o['quality']) ? $o['quality'] : $cur['quality'])));
        $cur['target_kb'] = max(0, min(10000, (int)(isset($o['target_kb']) ? $o['target_kb'] : $cur['target_kb'])));
        if (isset($o['format']) && in_array($o['format'], array('keep', 'jpeg', 'webp', 'png'), true)) {
            $cur['format'] = $o['format'];
        }
        $cur['responsive'] = !empty($o['responsive']);
        setting_set('def_opt', json_encode($cur));
    }
    if (isset($in['providers']) && is_array($in['providers'])) {
        foreach (ai_providers() as $k => $_) {
            if (!isset($in['providers'][$k]) || !is_array($in['providers'][$k])) {
                continue;
            }
            $p = $in['providers'][$k];
            if (isset($p['model'])) {
                setting_set('model_' . $k, clean_str($p['model'], 120));
            }
            if (isset($p['key'])) {
                $key = trim((string)$p['key']);
                if ($key === '__clear__') {
                    setting_set('key_' . $k, '');
                } elseif ($key !== '' && strpos($key, '••') !== 0) {
                    setting_set('key_' . $k, enc($key));
                }
            }
        }
    }
    return act_bootstrap(array());
}

function act_test_provider($in)
{
    $p = isset($in['provider']) ? $in['provider'] : '';
    if (!isset(ai_providers()[$p])) {
        fail('Unknown provider');
    }
    $t0 = microtime(true);
    $txt = ai_call($p, 'You reply with JSON only.', 'Reply with exactly this JSON: {"ok":true}');
    $j = ai_parse_json($txt);
    return array('ok_provider' => !empty($j['ok']), 'model' => ai_model($p), 'ms' => (int)((microtime(true) - $t0) * 1000));
}

function act_list_models($in)
{
    $p = isset($in['provider']) ? $in['provider'] : '';
    if (!isset(ai_providers()[$p])) {
        fail('Unknown provider');
    }
    $key = ai_key($p);
    if ($key === '') {
        fail('আগে API key সেভ করুন');
    }
    $ids = array();
    if ($p === 'gemini') {
        $j = http_json('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', array('x-goog-api-key: ' . $key), null, 30, 'GET');
        foreach ((array)(isset($j['models']) ? $j['models'] : array()) as $m) {
            if (!empty($m['supportedGenerationMethods']) && in_array('generateContent', $m['supportedGenerationMethods'], true)) {
                $ids[] = preg_replace('#^models/#', '', $m['name']);
            }
        }
    } elseif ($p === 'claude') {
        $j = http_json('https://api.anthropic.com/v1/models?limit=100', array('x-api-key: ' . $key, 'anthropic-version: 2023-06-01'), null, 30, 'GET');
        foreach ((array)(isset($j['data']) ? $j['data'] : array()) as $m) {
            $ids[] = $m['id'];
        }
    } elseif ($p === 'openai') {
        $j = http_json('https://api.openai.com/v1/models', array('Authorization: Bearer ' . $key), null, 30, 'GET');
        foreach ((array)(isset($j['data']) ? $j['data'] : array()) as $m) {
            if (preg_match('/^(gpt|o\d|chatgpt)/', $m['id']) && !preg_match('/(embedding|whisper|tts|audio|realtime|transcribe|image|moderation|search)/', $m['id'])) {
                $ids[] = $m['id'];
            }
        }
    } else {
        $j = http_json('https://openrouter.ai/api/v1/models', array('Authorization: Bearer ' . $key), null, 30, 'GET');
        foreach ((array)(isset($j['data']) ? $j['data'] : array()) as $m) {
            $mods = isset($m['architecture']['input_modalities']) ? $m['architecture']['input_modalities'] : array();
            if (in_array('image', $mods, true)) {
                $ids[] = $m['id'];
            }
        }
    }
    sort($ids);
    return array('models' => array_slice(array_values(array_unique($ids)), 0, 400));
}

function act_password($in)
{
    $u = q('SELECT * FROM users WHERE id=?', array(current_user_id()))->fetch();
    if (!$u || !password_verify(isset($in['current']) ? (string)$in['current'] : '', $u['pass_hash'])) {
        fail('বর্তমান পাসওয়ার্ড ভুল');
    }
    $new = isset($in['new']) ? (string)$in['new'] : '';
    if (strlen($new) < 8) {
        fail('নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে');
    }
    q('UPDATE users SET pass_hash=? WHERE id=?', array(password_hash($new, PASSWORD_DEFAULT), $u['id']));
    return array();
}

/* =========================== downloads / exports =========================== */

/** Build a JPG copy (SEO-resized, metadata embedded) from the original upload. Caller must unlink it. */
function make_jpg($r)
{
    $opt = array_merge(def_opt(), json_dec($r['opt_json']));
    $opt['format'] = 'jpeg';
    $base = STORAGE_DIR . '/tmp/jpg-' . bin2hex(random_bytes(6));
    $res = img_process(STORAGE_DIR . '/uploads/' . $r['orig_file'], $r['mime'], $base, $opt);
    meta_apply($res['path'], $res['mime'], meta_fields_for($r));
    return $res['path'];
}

function dl_file()
{
    $r = img_get(isset($_GET['id']) ? $_GET['id'] : 0);
    $kind = isset($_GET['kind']) ? $_GET['kind'] : 'thumb';
    $mime = 'image/jpeg';
    $tmpFile = '';
    if ($kind === 'jpg') {
        $GLOBALS['force_ext'] = 'jpg';
        $path = $tmpFile = make_jpg($r);
        $name = final_name($r);
    } elseif ($kind === 'original') {
        $path = STORAGE_DIR . '/uploads/' . $r['orig_file'];
        $mime = $r['mime'];
        $name = $r['orig_name'];
    } elseif ($kind === 'output') {
        $path = out_path($r);
        $mime = $r['out_mime'];
        $name = final_name($r);
    } elseif ($kind === 'variant') {
        $w = (int)(isset($_GET['w']) ? $_GET['w'] : 0);
        $path = STORAGE_DIR . '/output/' . $r['id'] . '_' . $w . '.' . $r['out_ext'];
        $mime = $r['out_mime'];
        $name = final_name($r, false) . '-' . $w . 'w.' . $r['out_ext'];
    } else {
        $path = STORAGE_DIR . '/thumbs/' . $r['thumb_file'];
        $name = 'thumb.jpg';
    }
    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    if (!empty($_GET['download'])) {
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    }
    readfile($path);
    if ($tmpFile !== '') {
        @unlink($tmpFile);
    }
}

function csv_rows($rows, $type)
{
    $out = array();
    if ($type === 'wordpress') {
        $out[] = array('filename', 'post_title', 'post_excerpt', 'post_content', '_wp_attachment_image_alt', 'url');
    } else {
        $out[] = array('filename', 'title', 'alt_text', 'caption', 'description', 'keywords', 'focus_keyword', 'width', 'height', 'size_kb', 'format', 'url');
    }
    foreach ($rows as $r) {
        $seo = array_merge(seo_defaults($r), json_dec($r['seo_json']));
        if ($type === 'wordpress') {
            $out[] = array(final_name($r), $seo['title'], $seo['caption'], $seo['description'], $seo['alt'], url_for($r));
        } else {
            $out[] = array(final_name($r), $seo['title'], $seo['alt'], $seo['caption'], $seo['description'], implode(', ', $seo['keywords']), $seo['focus'],
                empty($GLOBALS['force_ext']) ? ($r['out_w'] ?: $r['width']) : '', empty($GLOBALS['force_ext']) ? ($r['out_h'] ?: $r['height']) : '',
                empty($GLOBALS['force_ext']) ? round(($r['out_size'] ?: $r['orig_size']) / 1024, 1) : '',
                empty($GLOBALS['force_ext']) ? ($r['out_mime'] ?: $r['mime']) : 'image/jpeg', url_for($r));
        }
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach ($out as $line) {
        // neutralise spreadsheet formula injection (=, +, -, @ at the start of a text cell)
        foreach ($line as $k => $cell) {
            if (is_string($cell) && $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false && !is_numeric($cell)) {
                $line[$k] = "'" . $cell;
            }
        }
        fputcsv($fh, $line);
    }
    rewind($fh);
    $s = stream_get_contents($fh);
    fclose($fh);
    return $s;
}

function dl_csv()
{
    $rows = selected_rows(isset($_GET['ids']) ? $_GET['ids'] : '');
    $type = (isset($_GET['type']) && $_GET['type'] === 'wordpress') ? 'wordpress' : 'standard';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="image-seo-' . $type . '-' . date('Ymd-His') . '.csv"');
    echo csv_rows($rows, $type);
}

function dl_sitemap()
{
    if (trim(setting('upload_base_url')) === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Settings-এ "ছবির পাবলিক ফোল্ডার URL" দিন (যেমন https://yoursite.com/wp-content/uploads/2026/11/) — নইলে Sitemap-এ ছবির URL সম্পূর্ণ হবে না।');
    }
    $rows = selected_rows(isset($_GET['ids']) ? $_GET['ids'] : '');
    $page = trim(isset($_GET['page']) ? $_GET['page'] : '');
    if ($page === '') {
        $page = setting('site_url') ?: 'https://example.com/';
    }
    $x = function ($s) {
        return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n  <url>\n    <loc>" . $x($page) . "</loc>\n";
    foreach ($rows as $r) {
        $seo = array_merge(seo_defaults($r), json_dec($r['seo_json']));
        $xml .= "    <image:image>\n      <image:loc>" . $x(url_for($r)) . "</image:loc>\n";
        if ($seo['caption'] !== '') {
            $xml .= '      <image:caption>' . $x($seo['caption']) . "</image:caption>\n";
        }
        if ($seo['title'] !== '') {
            $xml .= '      <image:title>' . $x($seo['title']) . "</image:title>\n";
        }
        $xml .= "    </image:image>\n";
    }
    $xml .= "  </url>\n</urlset>\n";
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="image-sitemap.xml"');
    echo $xml;
}

function dl_zip()
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('PHP zip extension is not enabled on this server');
    }
    $rows = selected_rows(isset($_GET['ids']) ? $_GET['ids'] : '');
    $asJpg = isset($_GET['fmt']) && $_GET['fmt'] === 'jpg';
    if ($asJpg) {
        $GLOBALS['force_ext'] = 'jpg';
    }
    $tmp = STORAGE_DIR . '/tmp/seo-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        http_response_code(500);
        exit('Cannot create zip');
    }
    $used = array();
    $added = 0;
    $tmpJpgs = array();
    foreach ($rows as $r) {
        if ($asJpg) {
            $jp = make_jpg($r);
            $tmpJpgs[] = $jp;
            $base = final_name($r, false);
            $n = $base;
            $i = 2;
            while (isset($used[strtolower($n)])) {
                $n = $base . '-' . $i++;
            }
            $used[strtolower($n)] = true;
            $zip->addFile($jp, 'images/' . $n . '.jpg');
            $added++;
            continue;
        }
        $p = out_path($r);
        if (!$p || !is_file($p)) {
            continue;
        }
        $base = final_name($r, false);
        $n = $base;
        $i = 2;
        while (isset($used[strtolower($n)])) {
            $n = $base . '-' . $i++;
        }
        $used[strtolower($n)] = true;
        $zip->addFile($p, 'images/' . $n . '.' . $r['out_ext']);
        foreach (json_dec($r['variants_json']) as $v) {
            $vp = STORAGE_DIR . '/output/' . $r['id'] . '_' . $v['w'] . '.' . $r['out_ext'];
            if (is_file($vp)) {
                $zip->addFile($vp, 'images/' . $n . '-' . $v['w'] . 'w.' . $r['out_ext']);
            }
        }
        $added++;
    }
    if (!$added) {
        $zip->close();
        @unlink($tmp);
        http_response_code(400);
        exit('কোনো Process করা ছবি নেই — আগে Process করুন');
    }
    $zip->addFromString('seo-data.csv', csv_rows(array_filter($rows, function ($r) use ($asJpg) {
        return $asJpg || $r['out_ext'] !== '';
    }), 'standard'));
    $zip->close();
    foreach ($tmpJpgs as $jp) {
        @unlink($jp);
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="seo-images-' . ($asJpg ? 'jpg-' : '') . date('Ymd-His') . '.zip"');
    readfile($tmp);
    @unlink($tmp);
}
