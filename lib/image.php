<?php
/**
 * Image processing (GD): resize, compress, convert, thumbnails, AI samples.
 */

function img_supported_mimes()
{
    return array('image/jpeg', 'image/png', 'image/webp', 'image/gif');
}

function img_info($path)
{
    $i = @getimagesize($path);
    if (!$i || empty($i[0]) || empty($i[1])) {
        return null;
    }
    $mime = isset($i['mime']) ? $i['mime'] : '';
    if (!in_array($mime, img_supported_mimes(), true)) {
        return null;
    }
    return array('w' => (int)$i[0], 'h' => (int)$i[1], 'mime' => $mime);
}

function img_ext_for_mime($m)
{
    $map = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    return isset($map[$m]) ? $map[$m] : 'jpg';
}

function mem_limit_bytes()
{
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') {
        return PHP_INT_MAX;
    }
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') {
        $n *= 1073741824;
    } elseif ($u === 'm') {
        $n *= 1048576;
    } elseif ($u === 'k') {
        $n *= 1024;
    }
    return $n;
}

/** GD needs ~4 bytes/pixel per copy (source + one rotate/resize copy at peak). Fail politely instead of a fatal memory error. */
function img_mem_check($w, $h, $limit = null)
{
    $limit = $limit ?: mem_limit_bytes();
    $need = $w * $h * 4 * 2 + memory_get_usage() + 8 * 1048576;
    if ($need > $limit) {
        throw new RuntimeException('ছবি (' . $w . '×' . $h . ') এই সার্ভারের মেমরি লিমিটে (' . ini_get('memory_limit') . ') প্রসেস করা যাচ্ছে না — ছবিটি ছোট (যেমন ৪০০০px প্রস্থ) করে আপলোড করুন, অথবা হোস্টিংয়ে memory_limit বাড়ান');
    }
}

function img_load($path, $mime)
{
    $dim = @getimagesize($path);
    if ($dim) {
        img_mem_check($dim[0], $dim[1]);
    }
    $im = false;
    switch ($mime) {
        case 'image/jpeg':
            $im = @imagecreatefromjpeg($path);
            break;
        case 'image/png':
            $im = @imagecreatefrompng($path);
            break;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) {
                throw new RuntimeException('This server GD has no WebP support');
            }
            $im = @imagecreatefromwebp($path);
            break;
        case 'image/gif':
            $im = @imagecreatefromgif($path);
            break;
    }
    if (!$im) {
        throw new RuntimeException('Cannot read image (corrupt or unsupported file)');
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    return $im;
}

function img_autorotate($im, $path)
{
    if (!function_exists('exif_read_data')) {
        return $im;
    }
    $e = @exif_read_data($path);
    $o = isset($e['Orientation']) ? (int)$e['Orientation'] : 1;
    switch ($o) {
        case 2:
            imageflip($im, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $im = imagerotate($im, 180, 0);
            break;
        case 4:
            imageflip($im, IMG_FLIP_VERTICAL);
            break;
        case 5:
            imageflip($im, IMG_FLIP_HORIZONTAL);
            $im = imagerotate($im, 90, 0);
            break;
        case 6:
            $im = imagerotate($im, -90, 0);
            break;
        case 7:
            imageflip($im, IMG_FLIP_HORIZONTAL);
            $im = imagerotate($im, -90, 0);
            break;
        case 8:
            $im = imagerotate($im, 90, 0);
            break;
    }
    return $im;
}

function img_has_alpha($im)
{
    $w = imagesx($im);
    $h = imagesy($im);
    $step = max(1, (int)floor(max($w, $h) / 48));
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) {
                return true;
            }
        }
    }
    return false;
}

function img_resize($im, $nw, $nh, $alpha)
{
    $d = imagecreatetruecolor($nw, $nh);
    if ($alpha) {
        imagealphablending($d, false);
        imagesavealpha($d, true);
        imagefill($d, 0, 0, imagecolorallocatealpha($d, 0, 0, 0, 127));
    } else {
        imagefill($d, 0, 0, imagecolorallocate($d, 255, 255, 255));
    }
    imagecopyresampled($d, $im, 0, 0, 0, 0, $nw, $nh, imagesx($im), imagesy($im));
    return $d;
}

function img_encode($im, $fmt, $q, $alpha)
{
    ob_start();
    if ($fmt === 'jpeg') {
        $src = $im;
        if ($alpha) {
            $src = imagecreatetruecolor(imagesx($im), imagesy($im));
            imagefill($src, 0, 0, imagecolorallocate($src, 255, 255, 255));
            imagealphablending($src, true);
            imagecopy($src, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
        }
        imageinterlace($src, true);
        imagejpeg($src, null, $q);
    } elseif ($fmt === 'png') {
        imagesavealpha($im, true);
        imagepng($im, null, 9);
    } else {
        imagesavealpha($im, true);
        imagewebp($im, null, $q);
    }
    return ob_get_clean();
}

/**
 * Process an image. $o: format(keep|jpeg|webp|png), quality, max_width, max_height, target_kb
 * Writes $dstBase.<ext>, returns array(path, ext, mime, w, h, size)
 */
function img_process($src, $srcMime, $dstBase, array $o)
{
    $im = img_load($src, $srcMime);
    if ($srcMime === 'image/jpeg') {
        $im = img_autorotate($im, $src);
    }
    $alpha = ($srcMime !== 'image/jpeg') && img_has_alpha($im);

    $fmt = isset($o['format']) ? $o['format'] : 'webp';
    if ($fmt === 'keep') {
        $fmt = $srcMime === 'image/jpeg' ? 'jpeg' : ($srcMime === 'image/png' ? 'png' : 'webp');
    }
    if ($fmt === 'webp' && !function_exists('imagewebp')) {
        $fmt = $alpha ? 'png' : 'jpeg';
    }
    $q = max(30, min(100, (int)(isset($o['quality']) ? $o['quality'] : 80)));

    $w = imagesx($im);
    $h = imagesy($im);
    $mw = (int)(isset($o['max_width']) ? $o['max_width'] : 0);
    $mh = (int)(isset($o['max_height']) ? $o['max_height'] : 0);
    $scale = 1.0;
    if ($mw > 0 && $w > $mw) {
        $scale = min($scale, $mw / $w);
    }
    if ($mh > 0 && $h > $mh) {
        $scale = min($scale, $mh / $h);
    }
    if ($scale < 1.0) {
        $im2 = img_resize($im, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)), $alpha);
        imagedestroy($im);
        $im = $im2;
    }

    $bin = img_encode($im, $fmt, $q, $alpha);

    $tk = (int)(isset($o['target_kb']) ? $o['target_kb'] : 0);
    if ($tk > 0 && $fmt !== 'png' && strlen($bin) > $tk * 1024) {
        // leave room for the EXIF/XMP/IPTC block added afterwards (~3 KB)
        $target = max(1024, $tk * 1024 - ($tk > 8 ? 3072 : 1024));
        $best = null;
        $lo = 30;
        $hi = $q;
        for ($i = 0; $i < 7 && $lo <= $hi; $i++) {
            $mid = (int)(($lo + $hi) / 2);
            $b = img_encode($im, $fmt, $mid, $alpha);
            if (strlen($b) <= $target) {
                $best = $b;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($best !== null) {
            $bin = $best;
        } else {
            $bin = img_encode($im, $fmt, 40, $alpha);
            $tries = 0;
            while (strlen($bin) > $target && $tries < 14 && imagesx($im) > 320) {
                $nw = max(1, (int)round(imagesx($im) * 0.85));
                $nh = max(1, (int)round(imagesy($im) * 0.85));
                $im2 = img_resize($im, $nw, $nh, $alpha);
                imagedestroy($im);
                $im = $im2;
                $bin = img_encode($im, $fmt, 40, $alpha);
                $tries++;
            }
        }
    }

    $ext = $fmt === 'jpeg' ? 'jpg' : $fmt;
    $mime = $fmt === 'jpeg' ? 'image/jpeg' : 'image/' . $fmt;
    $path = $dstBase . '.' . $ext;
    if (file_put_contents($path, $bin, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write output file (check storage/ permissions)');
    }
    $res = array('path' => $path, 'ext' => $ext, 'mime' => $mime, 'w' => imagesx($im), 'h' => imagesy($im), 'size' => strlen($bin));
    imagedestroy($im);
    return $res;
}

/** JPEG binary sample (for thumbnails and AI vision). */
function img_sample($src, $srcMime, $maxDim = 1024, $quality = 80)
{
    $im = img_load($src, $srcMime);
    if ($srcMime === 'image/jpeg') {
        $im = img_autorotate($im, $src);
    }
    $alpha = ($srcMime !== 'image/jpeg') && img_has_alpha($im);
    $w = imagesx($im);
    $h = imagesy($im);
    $s = min(1.0, $maxDim / max($w, $h));
    if ($s < 1.0) {
        $im2 = img_resize($im, max(1, (int)round($w * $s)), max(1, (int)round($h * $s)), $alpha);
        imagedestroy($im);
        $im = $im2;
    }
    $bin = img_encode($im, 'jpeg', $quality, $alpha);
    imagedestroy($im);
    return $bin;
}
