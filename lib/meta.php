<?php
/**
 * Pure-PHP image metadata engine.
 * Writes EXIF (APP1), XMP (APP1) and IPTC (APP13) into JPEG; EXIF+XMP chunks into WebP;
 * XMP/iTXt/eXIf chunks into PNG. Reads the same back. No external binaries needed.
 */

/* =============== TIFF / EXIF builder =============== */

function te($tag, $type, $count, $bytes)
{
    return array('tag' => $tag, 'type' => $type, 'count' => $count, 'bytes' => $bytes);
}
function te_ascii($tag, $s)
{
    $b = $s . "\0";
    return te($tag, 2, strlen($b), $b);
}
/**
 * EXIF "ASCII" tags are 7-bit by spec; many readers decode UTF-8 bytes as Latin-1 and show garbage.
 * Map common symbols to ASCII; if anything non-ASCII remains, skip the tag (the Unicode value is
 * still carried by XMP, IPTC and the Windows XP* tags).
 */
function exif_ascii_safe($s)
{
    $s = strtr((string)$s, array(
        '©' => '(c)', '®' => '(R)', '™' => '(TM)', '‘' => "'", '’' => "'", '“' => '"', '”' => '"',
        '–' => '-', '—' => '-', '…' => '...', "\xC2\xA0" => ' ',
    ));
    return preg_match('/[^\x20-\x7E]/', $s) ? '' : trim($s);
}
function te_text($tag, $s)
{
    $a = exif_ascii_safe($s);
    return $a === '' ? null : te_ascii($tag, $a);
}
function te_short($tag, $v)
{
    return te($tag, 3, 1, pack('v', $v));
}
function te_long($tag, $v)
{
    return te($tag, 4, 1, pack('V', $v));
}
function te_byte($tag, $bytes)
{
    return te($tag, 1, strlen($bytes), $bytes);
}
function te_undef($tag, $bytes)
{
    return te($tag, 7, strlen($bytes), $bytes);
}
function te_rat($tag, array $pairs)
{
    $b = '';
    foreach ($pairs as $p) {
        $b .= pack('VV', $p[0], $p[1]);
    }
    return te($tag, 5, count($pairs), $b);
}
function te_utf16($tag, $s)
{
    return te_byte($tag, mb_convert_encoding($s, 'UTF-16LE', 'UTF-8') . "\0\0");
}

function ifd_build(array $entries, $offset)
{
    usort($entries, function ($a, $b) {
        return $a['tag'] - $b['tag'];
    });
    $n = count($entries);
    $dataOff = $offset + 2 + $n * 12 + 4;
    $ifd = pack('v', $n);
    $data = '';
    foreach ($entries as $e) {
        $b = $e['bytes'];
        $ifd .= pack('vvV', $e['tag'], $e['type'], $e['count']);
        if (strlen($b) <= 4) {
            $ifd .= str_pad($b, 4, "\0");
        } else {
            $ifd .= pack('V', $dataOff + strlen($data));
            $data .= $b;
            if (strlen($b) & 1) {
                $data .= "\0";
            }
        }
    }
    return $ifd . pack('V', 0) . $data;
}

function meta_gps_rat($v)
{
    $v = abs($v);
    $d = (int)floor($v);
    $m = (int)floor(($v - $d) * 60);
    $s = (($v - $d) * 60 - $m) * 60;
    return array(array($d, 1), array($m, 1), array((int)round($s * 10000), 10000));
}

function meta_norm(array $f)
{
    $o = array();
    foreach (array('title', 'description', 'author', 'copyright', 'website', 'software', 'make', 'model', 'datetime') as $k) {
        $o[$k] = isset($f[$k]) ? trim((string)$f[$k]) : '';
    }
    $kw = isset($f['keywords']) ? $f['keywords'] : array();
    if (is_string($kw)) {
        $kw = preg_split('/[,\n،;]+/u', $kw);
    }
    $o['keywords'] = array();
    foreach ((array)$kw as $k) {
        $k = trim((string)$k);
        if ($k !== '' && !in_array($k, $o['keywords'], true)) {
            $o['keywords'][] = $k;
        }
    }
    foreach (array('lat' => 90, 'lon' => 180) as $k => $lim) {
        $o[$k] = null;
        if (isset($f[$k]) && $f[$k] !== '' && is_numeric($f[$k]) && abs((float)$f[$k]) <= $lim) {
            $o[$k] = (float)$f[$k];
        }
    }
    return $o;
}

function meta_build_exif(array $f)
{
    $ifd0 = array();
    $exif = array();
    $gps = array();

    if ($f['description'] !== '') {
        if ($e = te_text(0x010E, $f['description'])) {
            $ifd0[] = $e;
        }
        $ifd0[] = te_utf16(0x9C9C, $f['description']);
    }
    if ($f['make'] !== '' && ($e = te_text(0x010F, $f['make']))) {
        $ifd0[] = $e;
    }
    if ($f['model'] !== '' && ($e = te_text(0x0110, $f['model']))) {
        $ifd0[] = $e;
    }
    $ifd0[] = te_short(0x0112, 1); // orientation normal (pixels already rotated)
    if ($f['software'] !== '' && ($e = te_text(0x0131, $f['software']))) {
        $ifd0[] = $e;
    }
    $dt = '';
    if ($f['datetime'] !== '') {
        $ts = strtotime($f['datetime']);
        if ($ts) {
            $dt = date('Y:m:d H:i:s', $ts);
        }
    }
    if ($dt !== '') {
        $ifd0[] = te_ascii(0x0132, $dt);
        $exif[] = te_ascii(0x9003, $dt);
        $exif[] = te_ascii(0x9004, $dt);
    }
    if ($f['author'] !== '') {
        if ($e = te_text(0x013B, $f['author'])) {
            $ifd0[] = $e;
        }
        $ifd0[] = te_utf16(0x9C9D, $f['author']);
    }
    if ($f['copyright'] !== '' && ($e = te_text(0x8298, $f['copyright']))) {
        $ifd0[] = $e;
    }
    if ($f['title'] !== '') {
        $ifd0[] = te_utf16(0x9C9B, $f['title']);
        $ifd0[] = te_utf16(0x9C9F, $f['title']);
    }
    if (!empty($f['keywords'])) {
        $ifd0[] = te_utf16(0x9C9E, implode(';', $f['keywords']));
    }
    if (!empty($exif)) {
        $exif[] = te_undef(0x9000, '0231');
    }
    if ($f['lat'] !== null && $f['lon'] !== null) {
        $gps[] = te_byte(0x0000, chr(2) . chr(3) . chr(0) . chr(0));
        $gps[] = te_ascii(0x0001, $f['lat'] >= 0 ? 'N' : 'S');
        $gps[] = te_rat(0x0002, meta_gps_rat($f['lat']));
        $gps[] = te_ascii(0x0003, $f['lon'] >= 0 ? 'E' : 'W');
        $gps[] = te_rat(0x0004, meta_gps_rat($f['lon']));
    }

    $hasExif = !empty($exif);
    $hasGps = !empty($gps);
    if ($hasExif) {
        $ifd0[] = te_long(0x8769, 0);
    }
    if ($hasGps) {
        $ifd0[] = te_long(0x8825, 0);
    }
    $len0 = strlen(ifd_build($ifd0, 0));
    $exifOff = 8 + $len0;
    $lenE = $hasExif ? strlen(ifd_build($exif, 0)) : 0;
    $gpsOff = $exifOff + $lenE;
    foreach ($ifd0 as &$e) {
        if ($e['tag'] === 0x8769) {
            $e['bytes'] = pack('V', $exifOff);
        }
        if ($e['tag'] === 0x8825) {
            $e['bytes'] = pack('V', $gpsOff);
        }
    }
    unset($e);

    $tiff = 'II' . pack('v', 42) . pack('V', 8) . ifd_build($ifd0, 8);
    if ($hasExif) {
        $tiff .= ifd_build($exif, $exifOff);
    }
    if ($hasGps) {
        $tiff .= ifd_build($gps, $gpsOff);
    }
    return $tiff;
}

/* =============== XMP =============== */

function xe($s)
{
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function meta_build_xmp(array $f)
{
    $d = '';
    if ($f['title'] !== '') {
        $d .= '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . xe($f['title']) . '</rdf:li></rdf:Alt></dc:title>';
        $d .= '<photoshop:Headline>' . xe($f['title']) . '</photoshop:Headline>';
    }
    if ($f['description'] !== '') {
        $d .= '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . xe($f['description']) . '</rdf:li></rdf:Alt></dc:description>';
    }
    if ($f['author'] !== '') {
        $d .= '<dc:creator><rdf:Seq><rdf:li>' . xe($f['author']) . '</rdf:li></rdf:Seq></dc:creator>';
        $d .= '<photoshop:Credit>' . xe($f['author']) . '</photoshop:Credit>';
    }
    if ($f['copyright'] !== '') {
        $d .= '<dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . xe($f['copyright']) . '</rdf:li></rdf:Alt></dc:rights>';
        $d .= '<xmpRights:Marked>True</xmpRights:Marked>';
    }
    if (!empty($f['keywords'])) {
        $d .= '<dc:subject><rdf:Bag>';
        foreach ($f['keywords'] as $k) {
            $d .= '<rdf:li>' . xe($k) . '</rdf:li>';
        }
        $d .= '</rdf:Bag></dc:subject>';
    }
    if ($f['software'] !== '') {
        $d .= '<xmp:CreatorTool>' . xe($f['software']) . '</xmp:CreatorTool>';
    }
    if ($f['website'] !== '') {
        $d .= '<xmpRights:WebStatement>' . xe($f['website']) . '</xmpRights:WebStatement>';
        $d .= '<photoshop:Source>' . xe($f['website']) . '</photoshop:Source>';
    }
    if ($d === '') {
        return '';
    }
    return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"'
        . ' xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/">'
        . $d
        . '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
}

/* =============== IPTC =============== */

function iptc_ds($rec, $ds, $val)
{
    return "\x1C" . chr($rec) . chr($ds) . pack('n', strlen($val)) . $val;
}

function meta_build_iptc(array $f)
{
    $i = iptc_ds(1, 90, "\x1B\x25\x47"); // UTF-8
    $any = false;
    $add = function ($ds, $val, $max) use (&$i, &$any) {
        if ($val !== '') {
            $i .= iptc_ds(2, $ds, mb_strcut($val, 0, $max, 'UTF-8'));
            $any = true;
        }
    };
    $add(5, $f['title'], 64);
    foreach ($f['keywords'] as $k) {
        $add(25, $k, 64);
    }
    $add(80, $f['author'], 32);
    $add(105, $f['title'], 256);
    $add(110, $f['author'], 32);
    $add(115, $f['website'], 32);
    $add(116, $f['copyright'], 128);
    $add(120, $f['description'], 2000);
    if (!$any) {
        return '';
    }
    $block = "8BIM\x04\x04\x00\x00" . pack('N', strlen($i)) . $i;
    if (strlen($i) & 1) {
        $block .= "\0";
    }
    return "Photoshop 3.0\0" . $block;
}

/* =============== JPEG =============== */

function jpeg_parse($d)
{
    if (substr($d, 0, 2) !== "\xFF\xD8") {
        throw new RuntimeException('Not a JPEG file');
    }
    $pos = 2;
    $n = strlen($d);
    $segs = array();
    $rest = '';
    while ($pos < $n) {
        if ($d[$pos] !== "\xFF") {
            $rest = substr($d, $pos);
            break;
        }
        $m = ord($d[$pos + 1]);
        if ($m === 0xFF) {
            $pos++;
            continue;
        }
        if ($m === 0xDA) {
            $rest = substr($d, $pos);
            break;
        }
        if ($m === 0xD9 || ($m >= 0xD0 && $m <= 0xD7) || $m === 0x01) {
            $segs[] = array($m, substr($d, $pos, 2));
            $pos += 2;
            continue;
        }
        $len = unpack('n', substr($d, $pos + 2, 2));
        $len = $len[1];
        $segs[] = array($m, substr($d, $pos, 2 + $len));
        $pos += 2 + $len;
    }
    return array($segs, $rest);
}

function jpeg_seg($marker, $payload)
{
    if (strlen($payload) > 65533) {
        throw new RuntimeException('Metadata too large for JPEG segment');
    }
    return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
}

function jpeg_inject($d, array $f, $strip)
{
    list($segs, $rest) = jpeg_parse($d);
    $keep = array();
    foreach ($segs as $s) {
        $m = $s[0];
        $b = $s[1];
        if ($m === 0xE1) {
            $p = substr($b, 4, 32);
            if (strpos($p, "Exif\0\0") === 0 || strpos($p, 'http://ns.adobe.com/xap/1.0/') === 0 || strpos($p, 'http://ns.adobe.com/xmp/extension/') === 0) {
                continue;
            }
        }
        if ($m === 0xED || $m === 0xFE) {
            continue;
        }
        $keep[] = $b;
    }
    $new = '';
    if (!$strip) {
        $tiff = meta_build_exif($f);
        if ($tiff !== '') {
            $new .= jpeg_seg(0xE1, "Exif\0\0" . $tiff);
        }
        $xmp = meta_build_xmp($f);
        if ($xmp !== '') {
            $new .= jpeg_seg(0xE1, "http://ns.adobe.com/xap/1.0/\0" . $xmp);
        }
        $iptc = meta_build_iptc($f);
        if ($iptc !== '') {
            $new .= jpeg_seg(0xED, $iptc);
        }
    }
    $head = '';
    $i = 0;
    while ($i < count($keep) && substr($keep[$i], 0, 2) === "\xFF\xE0") {
        $head .= $keep[$i];
        $i++;
    }
    return "\xFF\xD8" . $head . $new . implode('', array_slice($keep, $i)) . $rest;
}

/* =============== PNG =============== */

function png_chunk($type, $data)
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function png_itxt($kw, $text)
{
    return png_chunk('iTXt', $kw . "\0\0\0\0\0" . $text);
}

function png_inject($d, array $f, $strip)
{
    if (substr($d, 0, 8) !== "\x89PNG\r\n\x1A\n") {
        throw new RuntimeException('Not a PNG file');
    }
    $pos = 8;
    $n = strlen($d);
    $out = array();
    while ($pos + 8 <= $n) {
        $len = unpack('N', substr($d, $pos, 4));
        $len = $len[1];
        $type = substr($d, $pos + 4, 4);
        $chunk = substr($d, $pos, 12 + $len);
        $pos += 12 + $len;
        if (in_array($type, array('tEXt', 'iTXt', 'zTXt', 'eXIf'), true)) {
            continue;
        }
        $out[] = array($type, $chunk);
    }
    $new = '';
    if (!$strip) {
        $tiff = meta_build_exif($f);
        if ($tiff !== '') {
            $new .= png_chunk('eXIf', $tiff);
        }
        $xmp = meta_build_xmp($f);
        if ($xmp !== '') {
            $new .= png_itxt('XML:com.adobe.xmp', $xmp);
        }
        $map = array('Title' => $f['title'], 'Description' => $f['description'], 'Author' => $f['author'], 'Copyright' => $f['copyright'], 'Software' => $f['software'], 'Source' => $f['website']);
        foreach ($map as $k => $v) {
            if ($v !== '') {
                $new .= png_itxt($k, $v);
            }
        }
        if (!empty($f['keywords'])) {
            $new .= png_itxt('Keywords', implode(', ', $f['keywords']));
        }
    }
    $res = "\x89PNG\r\n\x1A\n";
    $inserted = false;
    foreach ($out as $c) {
        $res .= $c[1];
        if (!$inserted && $c[0] === 'IHDR') {
            $res .= $new;
            $inserted = true;
        }
    }
    return $res;
}

/* =============== WebP =============== */

function webp_chunk($type, $data)
{
    return $type . pack('V', strlen($data)) . $data . ((strlen($data) & 1) ? "\0" : '');
}

function webp_inject($d, array $f, $strip, $w, $h)
{
    if (substr($d, 0, 4) !== 'RIFF' || substr($d, 8, 4) !== 'WEBP') {
        throw new RuntimeException('Not a WebP file');
    }
    $n = strlen($d);
    $pos = 12;
    $vp8x = null;
    $img = array();
    $hasAlpha = false;
    while ($pos + 8 <= $n) {
        $t = substr($d, $pos, 4);
        $sz = unpack('V', substr($d, $pos + 4, 4));
        $sz = $sz[1];
        $data = substr($d, $pos + 8, $sz);
        $pos += 8 + $sz + ($sz & 1);
        if ($t === 'EXIF' || $t === 'XMP ') {
            continue;
        }
        if ($t === 'VP8X') {
            $vp8x = $data;
            continue;
        }
        if ($t === 'ALPH') {
            $hasAlpha = true;
        }
        if ($t === 'VP8L' && strlen($data) >= 5) {
            $b = unpack('V', substr($data, 1, 4));
            if ((($b[1] >> 28) & 1) === 1) {
                $hasAlpha = true;
            }
        }
        $img[] = array($t, $data);
    }
    $exifC = '';
    $xmpC = '';
    if (!$strip) {
        $tiff = meta_build_exif($f);
        if ($tiff !== '') {
            $exifC = webp_chunk('EXIF', $tiff);
        }
        $xmp = meta_build_xmp($f);
        if ($xmp !== '') {
            $xmpC = webp_chunk('XMP ', $xmp);
        }
    }
    if ($exifC === '' && $xmpC === '' && $vp8x === null) {
        return $d;
    }
    $flags = 0;
    if ($vp8x !== null) {
        $flags = ord($vp8x[0]) & ~0x0C;
        $dims = substr($vp8x, 4, 6);
    } else {
        if ($hasAlpha) {
            $flags |= 0x10;
        }
        $dims = substr(pack('V', $w - 1), 0, 3) . substr(pack('V', $h - 1), 0, 3);
    }
    if ($exifC !== '') {
        $flags |= 0x08;
    }
    if ($xmpC !== '') {
        $flags |= 0x04;
    }
    $body = webp_chunk('VP8X', chr($flags) . "\0\0\0" . $dims);
    foreach ($img as $c) {
        $body .= webp_chunk($c[0], $c[1]);
    }
    $body .= $exifC . $xmpC;
    return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
}

/* =============== Public write API =============== */

function meta_apply($path, $mime, array $fields, $strip = false)
{
    $f = meta_norm($fields);
    $d = file_get_contents($path);
    if ($d === false) {
        throw new RuntimeException('Cannot read file for metadata');
    }
    if ($mime === 'image/jpeg') {
        $out = jpeg_inject($d, $f, $strip);
    } elseif ($mime === 'image/png') {
        $out = png_inject($d, $f, $strip);
    } elseif ($mime === 'image/webp') {
        $i = @getimagesize($path);
        $out = webp_inject($d, $f, $strip, $i ? $i[0] : 1, $i ? $i[1] : 1);
    } else {
        return;
    }
    if ($out === '' || $out === null) {
        throw new RuntimeException('Metadata injection failed');
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $out, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write metadata (check storage/ permissions)');
    }
}

/* =============== Reading =============== */

function tiff_parse($t)
{
    $o = array();
    $L = strlen($t);
    if ($L < 8) {
        return $o;
    }
    $bo = substr($t, 0, 2);
    $le = ($bo === 'II');
    if (!$le && $bo !== 'MM') {
        return $o;
    }
    $u16 = function ($p) use ($t, $le, $L) {
        if ($p < 0 || $p + 2 > $L) {
            return 0;
        }
        $x = unpack($le ? 'v' : 'n', substr($t, $p, 2));
        return $x[1];
    };
    $u32 = function ($p) use ($t, $le, $L) {
        if ($p < 0 || $p + 4 > $L) {
            return 0;
        }
        $x = unpack($le ? 'V' : 'N', substr($t, $p, 4));
        return $x[1];
    };
    $names = array(
        0x010E => 'ImageDescription', 0x010F => 'Make', 0x0110 => 'Model', 0x0112 => 'Orientation',
        0x0131 => 'Software', 0x0132 => 'DateTime', 0x013B => 'Artist', 0x8298 => 'Copyright',
        0x9003 => 'DateTimeOriginal', 0x9C9B => 'XPTitle', 0x9C9C => 'XPComment', 0x9C9D => 'XPAuthor',
        0x9C9E => 'XPKeywords', 0x9C9F => 'XPSubject',
    );
    $gpsNames = array(1 => 'GPSLatitudeRef', 2 => 'GPSLatitude', 3 => 'GPSLongitudeRef', 4 => 'GPSLongitude');
    $sizes = array(1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1);

    $readIfd = function ($off, $isGps) use (&$readIfd, &$o, $t, $L, $u16, $u32, $names, $gpsNames, $sizes, $le) {
        if ($off < 8 || $off + 2 > $L) {
            return;
        }
        $n = $u16($off);
        if ($n > 500) {
            return;
        }
        for ($i = 0; $i < $n; $i++) {
            $e = $off + 2 + $i * 12;
            if ($e + 12 > $L) {
                break;
            }
            $tag = $u16($e);
            $type = $u16($e + 2);
            $cnt = $u32($e + 4);
            $sz = (isset($sizes[$type]) ? $sizes[$type] : 1) * $cnt;
            $vo = $sz <= 4 ? $e + 8 : $u32($e + 8);
            if ($vo < 0 || $vo + $sz > $L || $sz > 70000) {
                continue;
            }
            if (!$isGps && $tag === 0x8769) {
                $readIfd($u32($e + 8), false);
                continue;
            }
            if (!$isGps && $tag === 0x8825) {
                $readIfd($u32($e + 8), true);
                continue;
            }
            $name = $isGps ? (isset($gpsNames[$tag]) ? $gpsNames[$tag] : null) : (isset($names[$tag]) ? $names[$tag] : null);
            if ($name === null) {
                continue;
            }
            if ($type === 2) {
                $o[$name] = rtrim(substr($t, $vo, $sz), "\0 ");
            } elseif ($type === 3) {
                $o[$name] = $u16($vo);
            } elseif ($type === 5) {
                $vals = array();
                for ($k = 0; $k < $cnt; $k++) {
                    $nu = $u32($vo + $k * 8);
                    $de = $u32($vo + $k * 8 + 4);
                    $vals[] = $de ? $nu / $de : 0;
                }
                $o[$name] = $vals;
            } elseif ($type === 1 && $tag >= 0x9C9B) {
                $o[$name] = mb_convert_encoding(rtrim(substr($t, $vo, $sz), "\0"), 'UTF-8', 'UTF-16LE');
            }
        }
    };
    $readIfd($u32(4), false);
    return $o;
}

function xmp_values($xmp, $tag)
{
    $out = array();
    if (preg_match('#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#s', $xmp, $m)) {
        if (preg_match_all('#<rdf:li[^>]*>(.*?)</rdf:li>#s', $m[1], $mm)) {
            foreach ($mm[1] as $v) {
                $out[] = html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        } else {
            $out[] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    } elseif (preg_match('#' . $tag . '="([^"]*)"#', $xmp, $m)) {
        $out[] = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    return array_values(array_filter($out, 'strlen'));
}

function meta_to_utf8($s)
{
    return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
}

function meta_gps_to_float($arr, $ref)
{
    if (!is_array($arr) || count($arr) < 3) {
        return null;
    }
    $v = $arr[0] + $arr[1] / 60 + $arr[2] / 3600;
    return ($ref === 'S' || $ref === 'W') ? -$v : $v;
}

/** Read all metadata containers of a file and merge into a normalized view. */
function meta_read($path)
{
    $info = @getimagesize($path, $appinfo);
    $mime = $info ? $info['mime'] : '';
    $d = file_get_contents($path);
    $tiff = '';
    $xmp = '';
    $iptc = array();
    $png = array();

    if ($mime === 'image/jpeg') {
        list($segs) = jpeg_parse($d);
        foreach ($segs as $s) {
            if ($s[0] === 0xE1) {
                if (substr($s[1], 4, 6) === "Exif\0\0") {
                    $tiff = substr($s[1], 10);
                } elseif (substr($s[1], 4, 29) === "http://ns.adobe.com/xap/1.0/\0") {
                    $xmp = substr($s[1], 33);
                }
            }
        }
        if (!empty($appinfo['APP13'])) {
            $p = @iptcparse($appinfo['APP13']);
            if (is_array($p)) {
                $iptc = $p;
            }
        }
    } elseif ($mime === 'image/webp') {
        $pos = 12;
        $n = strlen($d);
        while ($pos + 8 <= $n) {
            $t = substr($d, $pos, 4);
            $sz = unpack('V', substr($d, $pos + 4, 4));
            $sz = $sz[1];
            $data = substr($d, $pos + 8, $sz);
            if ($t === 'EXIF') {
                $tiff = (substr($data, 0, 6) === "Exif\0\0") ? substr($data, 6) : $data;
            }
            if ($t === 'XMP ') {
                $xmp = $data;
            }
            $pos += 8 + $sz + ($sz & 1);
        }
    } elseif ($mime === 'image/png') {
        $pos = 8;
        $n = strlen($d);
        while ($pos + 8 <= $n) {
            $len = unpack('N', substr($d, $pos, 4));
            $len = $len[1];
            $type = substr($d, $pos + 4, 4);
            $data = substr($d, $pos + 8, $len);
            $pos += 12 + $len;
            if ($type === 'eXIf') {
                $tiff = $data;
            } elseif ($type === 'iTXt') {
                $z = strpos($data, "\0");
                $kw = substr($data, 0, $z);
                $txt = substr($data, $z + 5);
                $txt = preg_replace('/^[^\0]*\0[^\0]*\0/s', '', $txt, 1) === null ? $txt : $txt;
                // iTXt layout: kw\0 flag method lang\0 trans\0 text — recompute precisely
                $rest = substr($data, $z + 3);
                $l1 = strpos($rest, "\0");
                $rest = substr($rest, $l1 + 1);
                $l2 = strpos($rest, "\0");
                $txt = substr($rest, $l2 + 1);
                if ($kw === 'XML:com.adobe.xmp') {
                    $xmp = $txt;
                } else {
                    $png[$kw] = $txt;
                }
            } elseif ($type === 'tEXt') {
                $z = strpos($data, "\0");
                $png[substr($data, 0, $z)] = meta_to_utf8(substr($data, $z + 1));
            }
        }
    }

    $ex = $tiff !== '' ? tiff_parse($tiff) : array();
    $xm = array();
    if ($xmp !== '') {
        $xm = array(
            'title' => xmp_values($xmp, 'dc:title'),
            'description' => xmp_values($xmp, 'dc:description'),
            'creator' => xmp_values($xmp, 'dc:creator'),
            'rights' => xmp_values($xmp, 'dc:rights'),
            'subject' => xmp_values($xmp, 'dc:subject'),
            'tool' => xmp_values($xmp, 'xmp:CreatorTool'),
            'web' => xmp_values($xmp, 'xmpRights:WebStatement'),
        );
    }
    $ip = function ($k) use ($iptc) {
        return isset($iptc[$k]) ? array_map('meta_to_utf8', $iptc[$k]) : array();
    };
    $first = function () {
        foreach (func_get_args() as $v) {
            if (is_array($v)) {
                $v = reset($v);
            }
            if ($v !== false && $v !== null && trim((string)$v) !== '') {
                return trim((string)$v);
            }
        }
        return '';
    };
    $kw = array();
    if (!empty($xm['subject'])) {
        $kw = $xm['subject'];
    } elseif ($ip('2#025')) {
        $kw = $ip('2#025');
    } elseif (!empty($ex['XPKeywords'])) {
        $kw = array_values(array_filter(array_map('trim', explode(';', $ex['XPKeywords']))));
    } elseif (!empty($png['Keywords'])) {
        $kw = array_values(array_filter(array_map('trim', explode(',', $png['Keywords']))));
    }
    $lat = isset($ex['GPSLatitude']) ? meta_gps_to_float($ex['GPSLatitude'], isset($ex['GPSLatitudeRef']) ? $ex['GPSLatitudeRef'] : 'N') : null;
    $lon = isset($ex['GPSLongitude']) ? meta_gps_to_float($ex['GPSLongitude'], isset($ex['GPSLongitudeRef']) ? $ex['GPSLongitudeRef'] : 'E') : null;

    $fields = array(
        'title' => $first(isset($xm['title']) ? $xm['title'] : null, $ip('2#005'), $ip('2#105'), isset($ex['XPTitle']) ? $ex['XPTitle'] : null, isset($png['Title']) ? $png['Title'] : null),
        'description' => $first(isset($xm['description']) ? $xm['description'] : null, $ip('2#120'), isset($ex['ImageDescription']) ? $ex['ImageDescription'] : null, isset($ex['XPComment']) ? $ex['XPComment'] : null, isset($png['Description']) ? $png['Description'] : null),
        'keywords' => $kw,
        'author' => $first(isset($xm['creator']) ? $xm['creator'] : null, $ip('2#080'), isset($ex['Artist']) ? $ex['Artist'] : null, isset($ex['XPAuthor']) ? $ex['XPAuthor'] : null, isset($png['Author']) ? $png['Author'] : null),
        'copyright' => $first(isset($xm['rights']) ? $xm['rights'] : null, $ip('2#116'), isset($ex['Copyright']) ? $ex['Copyright'] : null, isset($png['Copyright']) ? $png['Copyright'] : null),
        'website' => $first(isset($xm['web']) ? $xm['web'] : null, $ip('2#115'), isset($png['Source']) ? $png['Source'] : null),
        'software' => $first(isset($xm['tool']) ? $xm['tool'] : null, isset($ex['Software']) ? $ex['Software'] : null, isset($png['Software']) ? $png['Software'] : null),
        'make' => isset($ex['Make']) ? $ex['Make'] : '',
        'model' => isset($ex['Model']) ? $ex['Model'] : '',
        'datetime' => '',
        'lat' => $lat,
        'lon' => $lon,
    );
    $dt = $first(isset($ex['DateTimeOriginal']) ? $ex['DateTimeOriginal'] : null, isset($ex['DateTime']) ? $ex['DateTime'] : null);
    if ($dt !== '') {
        $ts = strtotime(preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $dt));
        $fields['datetime'] = $ts ? date('Y-m-d H:i:s', $ts) : '';
    }

    $extra = array();
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $raw = @exif_read_data($path, 'ANY_TAG');
        if (is_array($raw)) {
            foreach (array('ExposureTime', 'FNumber', 'ISOSpeedRatings', 'FocalLength', 'LensModel', 'Flash', 'WhiteBalance', 'ExposureProgram', 'ColorSpace') as $k) {
                if (isset($raw[$k]) && is_scalar($raw[$k])) {
                    $extra[$k] = (string)$raw[$k];
                }
            }
        }
    }

    return array(
        'format' => $mime,
        'size' => strlen($d),
        'width' => $info ? $info[0] : 0,
        'height' => $info ? $info[1] : 0,
        'containers' => array('exif' => $tiff !== '', 'iptc' => !empty($iptc), 'xmp' => $xmp !== ''),
        'has_gps' => ($lat !== null && $lon !== null),
        'fields' => $fields,
        'extra' => $extra,
    );
}
