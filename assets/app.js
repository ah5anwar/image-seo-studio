/* SEO Studio — frontend */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var S = { csrf: '', settings: {}, caps: {}, images: [] };
  var sel = {};            // selected image ids
  var cur = null;          // image id open in modal
  var dirty = false;
  var pvStale = true, pvBusy = false;

  /* ---------------- utils ---------------- */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function len(s) { return Array.from(String(s || '')).length; }
  function toast(m, ms) { var t = $('toast'); t.textContent = m; t.hidden = false; clearTimeout(toast._t); toast._t = setTimeout(function () { t.hidden = true; }, ms || 2600); }
  function kb(b) { b = +b || 0; return b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(1) + ' KB' : (b / 1048576).toFixed(2) + ' MB'; }
  function listKw(s) { return String(s || '').split(/[,\n،;]+/).map(function (x) { return x.trim(); }).filter(Boolean); }
  function byId(id) { for (var i = 0; i < S.images.length; i++) if (S.images[i].id === id) return S.images[i]; return null; }
  function setBusy(btn, on, txt) { if (!btn) return; if (on) { btn._t = btn.textContent; btn.disabled = true; if (txt) btn.textContent = txt; } else { btn.disabled = false; if (btn._t) btn.textContent = btn._t; } }
  function copy(text, btn) {
    var done = function () { toast('কপি হয়েছে ✔'); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, fb); } else fb();
    function fb() { var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) { toast('কপি করা যায়নি'); } t.remove(); }
  }
  function api(action, data, opts) {
    opts = opts || {};
    var init = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf } };
    if (data instanceof FormData) init.body = data;
    else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(data || {}); }
    return fetch('api.php?action=' + action, init).then(function (r) {
      return r.text().then(function (t) {
        var j; try { j = JSON.parse(t); } catch (e) { throw new Error(r.ok ? 'সার্ভার থেকে ভুল উত্তর এসেছে' : 'সার্ভার এরর (' + r.status + ')'); }
        if (r.status === 401) { location.href = 'login.php'; throw new Error(j.error); }
        if (!j.ok) throw new Error(j.error || 'Error');
        return j;
      });
    });
  }
  function dlUrl(action, params) { var q = []; for (var k in params) q.push(k + '=' + encodeURIComponent(params[k])); return 'api.php?action=' + action + (q.length ? '&' + q.join('&') : ''); }
  function fileUrl(img, kind) { return dlUrl('file', { id: img.id, kind: kind, v: img.v }); }

  /* pixel width (Google truncates titles ~580px desktop) */
  var _cv = document.createElement('canvas').getContext('2d');
  function pxWidth(text, font) { _cv.font = font || '20px Arial'; return Math.round(_cv.measureText(text).width); }

  /* ---------------- counters ---------------- */
  var RULES = { e_title: [20, 60], e_alt: [50, 125], e_caption: [0, 160], e_desc: [100, 320], o_title: [30, 60], o_desc: [110, 160] };
  function updCounters() {
    var els = document.querySelectorAll('.cnt[data-for]');
    for (var i = 0; i < els.length; i++) {
      var id = els[i].getAttribute('data-for'), f = $(id); if (!f) continue;
      var n = len(f.value), r = RULES[id] || [0, 9999];
      els[i].textContent = n + ' / ' + r[1];
      els[i].className = 'cnt ' + (n === 0 ? '' : n > r[1] ? 'bad' : n < r[0] ? 'warn' : 'good');
    }
    var fn = $('e_filename'), c = $('c_fn');
    if (fn && c) { var w = fn.value.split('-').filter(Boolean).length; c.textContent = w + ' শব্দ'; c.className = 'cnt ' + (w >= 3 && w <= 6 ? 'good' : w ? 'warn' : ''); }
  }
  document.addEventListener('input', function (e) { if (e.target.matches('input,textarea,select')) updCounters(); });

  /* ---------------- tabs ---------------- */
  $('tabs').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-tab]'); if (!b) return;
    var bs = $('tabs').querySelectorAll('button'); for (var i = 0; i < bs.length; i++) bs[i].classList.toggle('on', bs[i] === b);
    var ts = document.querySelectorAll('.tab'); for (i = 0; i < ts.length; i++) ts[i].classList.remove('on');
    $('tab-' + b.getAttribute('data-tab')).classList.add('on');
    try { localStorage.setItem('seo_tab', b.getAttribute('data-tab')); } catch (e2) { }
  });

  /* ---------------- image SEO scoring ---------------- */
  function imgChecks(d) {
    // d: {focus,title,alt,caption,desc,kw[],filename,processed,ext,size,w}
    var c = [], f = (d.focus || '').toLowerCase().trim();
    var has = function (t) { return f && String(t || '').toLowerCase().indexOf(f) >= 0; };
    var fnWords = (d.filename || '').split('-').filter(Boolean);
    var bad = /^(img|dsc|dscn|image|photo|pic|screenshot|whatsapp|untitled|\d+)/i.test(d.filename || '') || fnWords.length < 2;
    c.push([bad ? 'bad' : (fnWords.length >= 3 ? 'ok' : 'warn'), 'SEO ফাইলনাম (' + (d.filename || '—') + ')', bad ? 8 : (fnWords.length >= 3 ? 0 : 4), 14]);
    var tl = len(d.title);
    c.push([tl >= 20 && tl <= 60 ? 'ok' : tl ? 'warn' : 'bad', 'Title ২০–৬০ অক্ষর (' + tl + ')', tl >= 20 && tl <= 60 ? 0 : tl ? 5 : 10, 10]);
    var al = len(d.alt);
    c.push([al >= 50 && al <= 125 ? 'ok' : al >= 20 && al <= 150 ? 'warn' : 'bad', 'Alt text ৫০–১২৫ অক্ষর (' + al + ')', al >= 50 && al <= 125 ? 0 : al >= 20 && al <= 150 ? 8 : 18, 22]);
    var dl = len(d.desc);
    c.push([dl >= 100 ? 'ok' : dl ? 'warn' : 'bad', 'Description ১০০+ অক্ষর (' + dl + ')', dl >= 100 ? 0 : dl ? 4 : 8, 10]);
    c.push([len(d.caption) ? 'ok' : 'warn', 'Caption আছে', len(d.caption) ? 0 : 3, 6]);
    c.push([d.kw.length >= 5 ? 'ok' : d.kw.length ? 'warn' : 'bad', 'Keywords ৫+ (' + d.kw.length + ')', d.kw.length >= 5 ? 0 : d.kw.length ? 3 : 6, 8]);
    if (f) {
      c.push([has(d.alt) ? 'ok' : 'bad', 'Alt text-এ focus keyword', has(d.alt) ? 0 : 8, 8]);
      c.push([has(d.filename.replace(/-/g, ' ')) ? 'ok' : 'warn', 'ফাইলনামে focus keyword', has(d.filename.replace(/-/g, ' ')) ? 0 : 5, 6]);
    } else c.push(['warn', 'Focus keyword দেওয়া হয়নি', 4, 8]);
    if (d.processed) {
      var fmt = d.ext === 'webp' || d.ext === 'avif';
      c.push([fmt ? 'ok' : 'warn', 'আধুনিক ফরম্যাট (' + d.ext.toUpperCase() + ')', fmt ? 0 : 3, 6]);
      c.push([d.size <= 150 * 1024 ? 'ok' : d.size <= 300 * 1024 ? 'warn' : 'bad', 'ফাইল সাইজ (' + kb(d.size) + ')', d.size <= 150 * 1024 ? 0 : d.size <= 300 * 1024 ? 3 : 6, 8]);
      c.push([d.w <= 2000 ? 'ok' : 'warn', 'প্রস্থ ≤ 2000px (' + d.w + ')', d.w <= 2000 ? 0 : 2, 4]);
    } else c.push(['bad', 'এখনও Process করা হয়নি (কম্প্রেশন + মেটাডেটা)', 14, 18]);
    var total = 0, lost = 0; c.forEach(function (x) { total += x[3]; lost += x[2]; });
    return { items: c, score: Math.max(0, Math.round(100 * (total - lost) / total)) };
  }
  function cls(sc) { return sc >= 80 ? 'good' : sc >= 55 ? 'warn' : 'bad'; }
  function checksOf(img) {
    var s = img.seo;
    return imgChecks({ focus: s.focus, title: s.title, alt: s.alt, caption: s.caption, desc: s.description, kw: s.keywords || [], filename: s.filename, processed: !!img.out_ext, ext: img.out_ext, size: img.out_size, w: img.out_w });
  }

  /* ---------------- grid ---------------- */
  function renderGrid() {
    var g = $('grid'), html = '';
    S.images.forEach(function (img) {
      var ck = checksOf(img), s = img.seo;
      var saved = img.out_size && img.orig_size ? Math.max(0, Math.round(100 - img.out_size * 100 / img.orig_size)) : 0;
      html += '<div class="tile' + (sel[img.id] ? ' sel' : '') + '" data-id="' + img.id + '">' +
        '<label class="sc"><input type="checkbox" class="tsel"' + (sel[img.id] ? ' checked' : '') + '></label>' +
        '<span class="bd ' + cls(ck.score) + '">' + ck.score + '</span>' +
        '<div class="th" style="background-image:url(\'' + fileUrl(img, 'thumb') + '\')"></div>' +
        '<div class="in"><div class="fn">' + esc(img.final_name) + '</div>' +
        '<div class="sub">' + img.width + '×' + img.height + ' · ' + kb(img.orig_size) + (img.out_size ? ' → <span class="save">' + kb(img.out_size) + (saved ? ' (−' + saved + '%)' : '') + '</span>' : '') + '</div>' +
        '<div class="sub">' + esc(s.alt ? s.alt.slice(0, 70) + (len(s.alt) > 70 ? '…' : '') : 'Alt text নেই') + '</div>' +
        '<div class="st"><span class="pill' + (s.alt ? ' on' : '') + '">SEO</span><span class="pill' + (img.out_ext ? ' on' : '') + '">Processed</span></div></div></div>';
    });
    g.innerHTML = html;
    $('empty').hidden = S.images.length > 0;
    $('imgToolbar').hidden = S.images.length === 0;
    updSel();
  }
  function selIds() { return Object.keys(sel).filter(function (k) { return sel[k]; }).map(Number); }
  function updSel() {
    var n = selIds().length;
    $('selCount').textContent = n ? n + 'টি নির্বাচিত' : S.images.length + 'টি ছবি';
    $('selAll').checked = n > 0 && n === S.images.length;
  }
  function targets() { var ids = selIds(); return ids.length ? ids : S.images.map(function (i) { return i.id; }); }
  function replaceImg(img) {
    for (var i = 0; i < S.images.length; i++) if (S.images[i].id === img.id) { S.images[i] = img; return; }
    S.images.unshift(img);
  }
  $('grid').addEventListener('click', function (e) {
    var t = e.target.closest('.tile'); if (!t) return;
    var id = +t.getAttribute('data-id');
    if (e.target.classList.contains('tsel')) { sel[id] = e.target.checked; t.classList.toggle('sel', e.target.checked); updSel(); return; }
    if (e.target.closest('.sc')) return;
    openModal(id);
  });
  $('selAll').addEventListener('change', function () { sel = {}; if (this.checked) S.images.forEach(function (i) { sel[i.id] = true; }); renderGrid(); });

  /* ---------------- upload ---------------- */
  function uploadFiles(files) {
    files = Array.prototype.filter.call(files, function (f) { return /^image\/(jpeg|png|webp|gif)$/.test(f.type) || /\.(jpe?g|png|webp|gif)$/i.test(f.name); });
    if (!files.length) { toast('কোনো সমর্থিত ছবি নেই'); return; }
    var bar = $('upbar'), fill = bar.querySelector('i'), lab = bar.querySelector('span'), i = 0, ok = 0, errs = [];
    bar.hidden = false;
    (function next() {
      if (i >= files.length) {
        lab.textContent = ok + ' টি আপলোড হয়েছে' + (errs.length ? ', ' + errs.length + ' টি ব্যর্থ' : '');
        if (errs.length) logLine(errs.join('\n'), 'err', true);
        renderGrid(); setTimeout(function () { bar.hidden = true; }, 2500); return;
      }
      var f = files[i], fd = new FormData(); fd.append('file', f);
      lab.textContent = (i + 1) + '/' + files.length + ' — ' + f.name; fill.style.width = (i * 100 / files.length) + '%';
      api('upload', fd).then(function (r) { replaceImg(r.image); ok++; }, function (e) { errs.push(f.name + ': ' + e.message); }).then(function () { i++; renderGrid(); next(); });
    })();
  }
  var drop = $('drop');
  $('pick').onclick = function () { $('fileInput').click(); };
  $('fileInput').onchange = function () { uploadFiles(this.files); this.value = ''; };
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { uploadFiles(e.dataTransfer.files); });
  document.addEventListener('paste', function (e) {
    if (!$('tab-images').classList.contains('on') || !$('modal').hidden) return;
    var fs = []; var it = (e.clipboardData || {}).items || [];
    for (var i = 0; i < it.length; i++) if (it[i].kind === 'file') { var f = it[i].getAsFile(); if (f) fs.push(f); }
    if (fs.length) uploadFiles(fs);
  });

  /* ---------------- bulk ---------------- */
  var logBox = $('bulkLog');
  function logLine(t, kind, keep) { logBox.hidden = false; if (!keep && !logBox._keep) { } var d = document.createElement('div'); if (kind) d.className = kind; d.textContent = t; logBox.appendChild(d); logBox.scrollTop = 1e9; }
  function runBulk(title, ids, fn, done) {
    if (!ids.length) { toast('কোনো ছবি নেই'); return; }
    logBox.innerHTML = ''; logLine(title + ' — ' + ids.length + ' টি ছবি…');
    var i = 0, ok = 0, fail = 0, stop = false;
    (function next() {
      if (i >= ids.length) { logLine('শেষ: ' + ok + ' সফল' + (fail ? ', ' + fail + ' ব্যর্থ' : ''), fail ? 'err' : 'okk'); renderGrid(); if (done) done(); return; }
      var id = ids[i], img = byId(id), nm = img ? img.orig_name : '#' + id;
      fn(id).then(function (r) { if (r && r.image) replaceImg(r.image); ok++; logLine('✔ ' + nm, 'okk'); }, function (e) { fail++; logLine('✘ ' + nm + ' — ' + e.message, 'err'); }).then(function () { i++; renderGrid(); next(); });
    })();
  }
  $('bulkAi').onclick = function () {
    var focus = window.prompt('সব ছবির জন্য একটি Focus keyword (ঐচ্ছিক — ফাঁকা রাখলে AI নিজেই ঠিক করবে):', '');
    if (focus === null) return;
    var ctx = window.prompt('ছবিগুলো কী নিয়ে / কোন পেজের জন্য? (ঐচ্ছিক):', '');
    if (ctx === null) return;
    runBulk('AI SEO লেখা হচ্ছে', targets(), function (id) { return api('ai_image', { id: id, focus: focus, context: ctx, lang: S.settings.lang }); });
  };
  $('bulkProcess').onclick = function () { runBulk('Process হচ্ছে', targets(), function (id) { return api('process', { id: id }); }); };
  $('bulkApply').onclick = function () {
    if (!confirm('Settings-এর ডিফল্ট অপটিমাইজেশন (ফরম্যাট, সাইজ, কোয়ালিটি) নির্বাচিত/সব ছবিতে বসানো হবে। চালিয়ে যাবেন?')) return;
    runBulk('ডিফল্ট সেটিং বসানো হচ্ছে', targets(), function (id) { return api('save', { id: id, opt: S.settings.def_opt }); });
  };
  $('bulkDel').onclick = function () {
    var ids = selIds(); if (!ids.length) { toast('আগে ছবি সিলেক্ট করুন'); return; }
    if (!confirm(ids.length + ' টি ছবি স্থায়ীভাবে মুছে যাবে। নিশ্চিত?')) return;
    api('delete', { ids: ids }).then(function () { S.images = S.images.filter(function (i) { return ids.indexOf(i.id) < 0; }); sel = {}; renderGrid(); toast('মুছে ফেলা হয়েছে'); }, function (e) { toast(e.message); });
  };
  function exportUrl(action, extra) { var p = { ids: selIds().join(',') }; for (var k in (extra || {})) p[k] = extra[k]; return dlUrl(action, p); }
  $('exZip').onclick = function () { location.href = exportUrl('zip'); };
  $('exZipJpg').onclick = function () { location.href = exportUrl('zip', { fmt: 'jpg' }); };
  $('exCsv').onclick = function () { location.href = exportUrl('csv', { type: 'standard' }); };
  $('exWp').onclick = function () { location.href = exportUrl('csv', { type: 'wordpress' }); };
  $('exMap').onclick = function () { if (!S.settings.upload_base_url) { toast('আগে Settings-এ "ছবির পাবলিক ফোল্ডার URL" দিন', 5000); return; } var p = window.prompt('যে পেজে ছবিগুলো আছে তার URL:', S.settings.site_url || 'https://'); if (p) location.href = exportUrl('sitemap', { page: p }); };

  /* ---------------- provider selects ---------------- */
  function fillProviderSelects() {
    var opts = '<option value="">অটো (প্রাইমারি + ফলব্যাক)</option>';
    for (var k in S.settings.providers) { var p = S.settings.providers[k]; if (p.has_key) opts += '<option value="' + k + '">' + esc(p.label) + '</option>'; }
    var els = document.querySelectorAll('.provSel'); for (var i = 0; i < els.length; i++) { var v = els[i].value; els[i].innerHTML = opts; els[i].value = v; }
  }

  /* ---------------- modal editor ---------------- */
  function modalIndex() { for (var i = 0; i < S.images.length; i++) if (S.images[i].id === cur) return i; return -1; }
  function openModal(id) {
    cur = id; var img = byId(id); if (!img) return;
    dirty = false; pvStale = true; $('pv_out').hidden = true; $('pv_msg').textContent = 'ফর্মের বর্তমান মান দিয়ে (সেভ না করেই) আসল প্রসেস চালিয়ে দেখানো হয়'; $('modal').hidden = false; document.body.style.overflow = 'hidden';
    var s = img.seo, m = img.meta || {}, o = img.opt;
    $('mTitle').textContent = img.orig_name;
    $('e_focus').value = s.focus || ''; $('e_lang').value = s.lang || S.settings.lang; $('e_ctx').value = ''; $('e_msg').textContent = '';
    $('e_filename').value = s.filename; $('e_title').value = s.title; $('e_alt').value = s.alt; $('e_caption').value = s.caption; $('e_desc').value = s.description; $('e_kw').value = (s.keywords || []).join(', ');
    $('o_fmt').value = o.format; $('o_mw').value = o.max_width; $('o_mh').value = o.max_height; $('o_q').value = o.quality; $('o_kb').value = o.target_kb; $('o_resp').checked = !!o.responsive;
    $('x_title').value = m.title || ''; $('x_desc').value = m.description || ''; $('x_author').value = m.author || ''; $('x_copy').value = m.copyright || ''; $('x_web').value = m.website || ''; $('x_soft').value = m.software || '';
    $('x_make').value = m.make || ''; $('x_model').value = m.model || ''; $('x_dt').value = m.datetime || ''; $('x_lat').value = m.lat === undefined ? '' : m.lat; $('x_lon').value = m.lon === undefined ? '' : m.lon;
    $('x_view').innerHTML = ''; $('h_url').value = '';
    $('mSaveState').textContent = '';
    refreshModalView(); updCounters(); buildHtmlCode();
    var i = modalIndex(); $('mPrev').disabled = i <= 0; $('mNext').disabled = i >= S.images.length - 1;
  }
  function refreshModalView() {
    var img = byId(cur); if (!img) return;
    $('mImg').src = img.out_ext ? fileUrl(img, 'output') : fileUrl(img, 'original');
    var rows = [['মূল', img.width + '×' + img.height + ' · ' + kb(img.orig_size) + ' · ' + img.mime.replace('image/', '').toUpperCase()]];
    if (img.out_ext) rows.push(['অপটিমাইজড', img.out_w + '×' + img.out_h + ' · ' + kb(img.out_size) + ' · ' + img.out_ext.toUpperCase()], ['সাশ্রয়', Math.max(0, Math.round(100 - img.out_size * 100 / img.orig_size)) + '%']);
    (img.variants || []).forEach(function (v) { rows.push(['Responsive', v.w + 'w · ' + kb(v.size)]); });
    rows.push(['ফাইলনাম', img.final_name]);
    $('mStats').innerHTML = rows.map(function (r) { return '<b>' + esc(r[0]) + '</b><span>' + esc(r[1]) + '</span>'; }).join('');
    $('mDlOut').hidden = !img.out_ext; $('mDlOut').href = dlUrl('file', { id: img.id, kind: 'output', download: 1 });
    $('mDlOrig').href = dlUrl('file', { id: img.id, kind: 'original', download: 1 });
    $('mDlJpg').href = dlUrl('file', { id: img.id, kind: 'jpg', download: 1 });
    liveCheck();
  }
  function formSeo() { return { focus: $('e_focus').value.trim(), lang: $('e_lang').value, filename: $('e_filename').value.trim(), title: $('e_title').value.trim(), alt: $('e_alt').value.trim(), caption: $('e_caption').value.trim(), description: $('e_desc').value.trim(), keywords: listKw($('e_kw').value) }; }
  function formMeta() { return { title: $('x_title').value, description: $('x_desc').value, author: $('x_author').value, copyright: $('x_copy').value, website: $('x_web').value, software: $('x_soft').value, make: $('x_make').value, model: $('x_model').value, datetime: $('x_dt').value, lat: $('x_lat').value, lon: $('x_lon').value }; }
  function formOpt() { return { format: $('o_fmt').value, max_width: +$('o_mw').value || 0, max_height: +$('o_mh').value || 0, quality: +$('o_q').value || 80, target_kb: +$('o_kb').value || 0, responsive: $('o_resp').checked }; }
  function liveCheck() {
    var img = byId(cur); if (!img) return; var f = formSeo();
    var r = imgChecks({ focus: f.focus, title: f.title, alt: f.alt, caption: f.caption, desc: f.description, kw: f.keywords, filename: f.filename.replace(/\.[a-z0-9]+$/i, ''), processed: !!img.out_ext, ext: img.out_ext, size: img.out_size, w: img.out_w });
    $('mCheck').innerHTML = '<div class="score"><div class="ring ' + cls(r.score) + '">' + r.score + '</div><div><b>ছবির SEO স্কোর</b><br><span class="muted">১০০-র দিকে যান</span></div></div><ul>' +
      r.items.map(function (x) { return '<li class="' + x[0] + '">' + (x[0] === 'ok' ? '✔' : x[0] === 'warn' ? '!' : '✘') + ' ' + esc(x[1]) + '</li>'; }).join('') + '</ul>';
  }
  function onEdit() { pvStale = true; dirty = true; $('mSaveState').textContent = 'অসেভড পরিবর্তন আছে'; liveCheck(); buildHtmlCode(); }
  ['mt-seo', 'mt-opt', 'mt-meta'].forEach(function (id) { $(id).addEventListener('input', onEdit); });
  $('h_url').addEventListener('input', buildHtmlCode);

  function saveCurrent() {
    return api('save', { id: cur, seo: formSeo(), meta: formMeta(), opt: formOpt() }).then(function (r) { replaceImg(r.image); dirty = false; $('mSaveState').textContent = 'সেভ হয়েছে ✔'; $('e_filename').value = r.image.seo.filename; refreshModalView(); renderGrid(); return r.image; });
  }
  $('mSave').onclick = function () { var b = this; setBusy(b, true); saveCurrent().then(function () { toast('সেভ হয়েছে'); }, function (e) { toast(e.message, 4000); }).then(function () { setBusy(b, false); }); };
  $('mProcess').onclick = function () {
    var b = this; setBusy(b, true, '⏳ Process হচ্ছে…');
    saveCurrent().then(function () { return api('process', { id: cur }); }).then(function (r) {
      replaceImg(r.image); refreshModalView(); renderGrid(); buildHtmlCode();
      $('mSaveState').textContent = 'Process সম্পন্ন ✔ — ' + kb(r.image.orig_size) + ' → ' + kb(r.image.out_size) + (r.target_met === false ? ' ⚠ টার্গেট KB ছোঁয়া যায়নি (ছবি খুব জটিল) — প্রস্থ কমান বা টার্গেট বাড়ান' : '') + (r.format_kept ? ' · ছোট সাইজের জন্য মূল ফরম্যাট রাখা হয়েছে' : '') + (r.bigger ? ' · ℹ ছবি আগে থেকেই খুব ছোট — SEO মেটাডেটা (~৩KB) যোগ হওয়ায় সাইজ সামান্য বেশি' : '');
      showMeta(r.verify, 'Process-করা ছবির ভেতরের মেটাডেটা (যাচাইকৃত)'); toast('Process সম্পন্ন ✔');
    }).catch(function (e) { toast(e.message, 5000); $('mSaveState').textContent = e.message; }).then(function () { setBusy(b, false); });
  };
  $('e_ai').onclick = function () {
    var b = this; setBusy(b, true, '⏳ AI লিখছে…'); $('e_msg').textContent = '';
    api('ai_image', { id: cur, focus: $('e_focus').value, context: $('e_ctx').value, lang: $('e_lang').value, provider: $('e_prov').value }).then(function (r) {
      replaceImg(r.image); var s = r.image.seo;
      $('e_focus').value = s.focus || ''; $('e_filename').value = s.filename; $('e_title').value = s.title; $('e_alt').value = s.alt; $('e_caption').value = s.caption; $('e_desc').value = s.description; $('e_kw').value = s.keywords.join(', ');
      $('e_msg').textContent = '✔ তৈরি (' + r.provider + ') — দেখে নিয়ে "সেভ ও Process" চাপুন'; dirty = false; pvStale = true; refreshModalView(); renderGrid(); updCounters(); buildHtmlCode();
    }).catch(function (e) { $('e_msg').textContent = '✘ ' + e.message; }).then(function () { setBusy(b, false); });
  };
  function closeModal() { if (dirty && !confirm('অসেভড পরিবর্তন আছে। না সেভ করেই বন্ধ করবেন?')) return; $('modal').hidden = true; document.body.style.overflow = ''; cur = null; }
  $('mClose').onclick = closeModal;
  $('modal').addEventListener('mousedown', function (e) { if (e.target === this) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('modal').hidden) closeModal(); });
  function nav(d) { var i = modalIndex() + d; if (i < 0 || i >= S.images.length) return; var go = function () { openModal(S.images[i].id); }; if (dirty) { saveCurrent().then(go, function (e) { toast(e.message); }); } else go(); }
  $('mPrev').onclick = function () { nav(-1); }; $('mNext').onclick = function () { nav(1); };
  document.querySelector('.mtabs').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-mt]'); if (!b) return;
    var bs = this.querySelectorAll('button'); for (var i = 0; i < bs.length; i++) bs[i].classList.toggle('on', bs[i] === b);
    var ts = document.querySelectorAll('.mt'); for (i = 0; i < ts.length; i++) ts[i].classList.remove('on');
    $('mt-' + b.getAttribute('data-mt')).classList.add('on'); buildHtmlCode();
    if (b.getAttribute('data-mt') === 'prev' && pvStale) previewNow();
  });
  $('x_clearGps').onclick = function () { $('x_lat').value = ''; $('x_lon').value = ''; onEdit(); };

  function showMeta(m, title) {
    var f = m.fields || {}, rows = [];
    var add = function (k, v) { if (v !== '' && v !== null && v !== undefined && !(Array.isArray(v) && !v.length)) rows.push([k, Array.isArray(v) ? v.join(', ') : v]); };
    add('Title', f.title); add('Description', f.description); add('Keywords', f.keywords); add('Author', f.author); add('Copyright', f.copyright); add('Website', f.website);
    add('Software', f.software); add('Camera', ((f.make || '') + ' ' + (f.model || '')).trim()); add('তারিখ', f.datetime);
    if (m.has_gps) add('GPS', (+f.lat).toFixed(5) + ', ' + (+f.lon).toFixed(5));
    for (var k in (m.extra || {})) add(k, m.extra[k]);
    var c = m.containers || {};
    var h = '<h3>' + esc(title) + '</h3><p class="muted">ফরম্যাট ' + esc((m.format || '').replace('image/', '')) + ' · ' + m.width + '×' + m.height + ' · ' + kb(m.size) + ' · EXIF ' + (c.exif ? '✔' : '✘') + ' · IPTC ' + (c.iptc ? '✔' : '✘') + ' · XMP ' + (c.xmp ? '✔' : '✘') + '</p>';
    h += rows.length ? '<table>' + rows.map(function (r) { return '<tr><td>' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>'; }).join('') + '</table>' : '<p class="muted">কোনো মেটাডেটা নেই (পরিষ্কার ছবি)।</p>';
    $('x_view').innerHTML = h;
    document.querySelector('.mtabs button[data-mt="meta"]').click();
  }
  $('x_read').onclick = function () { api('read_meta', { id: cur, which: 'original' }).then(function (r) { showMeta(r.meta, 'অরিজিনাল ছবির মেটাডেটা'); }, function (e) { toast(e.message); }); };
  $('x_readOut').onclick = function () { api('read_meta', { id: cur, which: 'output' }).then(function (r) { showMeta(r.meta, 'Process-করা ছবির ভেতরের মেটাডেটা (যাচাই)'); }, function (e) { toast(e.message); }); };

  /* ---- Preview (what the image contains + what was newly embedded) ---- */
  function domainOf(u) { try { return new URL(u).host; } catch (e) { return (u || '').replace(/^https?:\/\//, '').split('/')[0]; } }
  function fv(f, k) {
    if (!f) return '';
    if (k === 'keywords') return (f.keywords || []).join(', ');
    if (k === 'camera') return ((f.make || '') + ' ' + (f.model || '')).trim();
    if (k === 'gps') return (f.lat !== null && f.lat !== undefined && f.lon !== null && f.lon !== undefined) ? (+f.lat).toFixed(5) + ', ' + (+f.lon).toFixed(5) : '';
    return String(f[k] == null ? '' : f[k]);
  }
  function diffTable(before, after) {
    var rows = [['title', 'Title'], ['description', 'Description'], ['keywords', 'Keywords'], ['author', 'Author'], ['copyright', 'Copyright'], ['website', 'Website'], ['software', 'Software'], ['camera', 'ক্যামেরা'], ['datetime', 'তারিখ'], ['gps', 'GPS']];
    var h = '<table class="diff"><tr><th></th><th>আগে (অরিজিনাল ফাইল)</th><th>পরে (অপটিমাইজড ফাইলের ভেতরে)</th></tr>';
    rows.forEach(function (r) {
      var b = fv(before.fields, r[0]), a = fv(after.fields, r[0]);
      if (!b && !a) return;
      var cl = b === a ? 'same' : !b ? 'new' : !a ? 'del' : 'chg';
      var tag = cl === 'new' ? '<span class="tag">+ নতুন</span>' : cl === 'del' ? '<span class="tag">− মুছে গেছে</span>' : cl === 'chg' ? '<span class="tag">↻ বদলেছে</span>' : '';
      h += '<tr class="' + cl + '"><td>' + esc(r[1]) + '</td><td>' + (b ? esc(b) : '<span class="muted">—</span>') + '</td><td>' + tag + (a ? esc(a) : '<span class="muted">—</span>') + '</td></tr>';
    });
    var c = after.containers || {};
    h += '</table><p class="muted" style="font-size:12px">পরের ফাইলে: EXIF ' + (c.exif ? '✔' : '✘') + ' · IPTC ' + (c.iptc ? '✔' : '— (WebP/PNG-তে IPTC নেই; XMP-তে একই তথ্য আছে)') + ' · XMP ' + (c.xmp ? '✔' : '✘') + '</p>';
    if (before.has_gps && !after.has_gps) h += '<div class="alert ok" style="font-size:13px">🔒 অরিজিনাল ছবির ব্যক্তিগত GPS লোকেশন মুছে ফেলা হয়েছে।</div>';
    return h;
  }
  function gimgCard(img, title, text, url) {
    return '<div class="gimg"><img src="' + esc(img) + '" alt=""><div style="min-width:0"><div class="gsrc">' + esc(domainOf(url) || 'yoursite.com') + '</div><div class="gt">' + esc(title || '(Title নেই)') + '</div><div class="gs">' + esc(text || '(Alt/Description নেই)') + '</div></div></div>';
  }
  function ogCard(img, domain, title, text) {
    return '<div class="og"><div class="ogimg">' + (img ? '<img src="' + esc(img) + '" alt="">' : 'ছবির URL দিলে এখানে দেখাবে') + '</div><div class="ogb"><div class="ogd">' + esc(domain || 'yoursite.com') + '</div><div class="ogt">' + esc(title || '') + '</div><div class="ogs">' + esc(text || '') + '</div></div></div>';
  }
  function previewNow() {
    if (pvBusy || !cur) return; pvBusy = true;
    var btn = $('pv_go'); setBusy(btn, true, '⏳ প্রসেস হচ্ছে…'); $('pv_msg').textContent = '';
    var id = cur;
    api('preview', { id: id, seo: formSeo(), meta: formMeta(), opt: formOpt() }).then(function (r) {
      if (cur !== id) return; var p = r.preview, img = byId(id); pvStale = false;
      $('pv_out').hidden = false;
      $('pv_before').src = fileUrl(img, 'original'); $('pv_after').src = p.data_uri || fileUrl(img, 'thumb');
      $('pv_bcap').innerHTML = '<b>অরিজিনাল</b> · ' + p.orig_w + '×' + p.orig_h + ' · ' + kb(p.orig_size) + ' · ' + p.orig_mime.replace('image/', '').toUpperCase();
      $('pv_acap').innerHTML = '<b>অপটিমাইজড</b> · ' + p.w + '×' + p.h + ' · ' + kb(p.size) + ' · ' + p.ext.toUpperCase() + (p.saving > 0 ? ' · <b style="color:var(--ok)">−' + p.saving + '%</b>' : p.saving < 0 ? ' · <b style="color:var(--warn)">+' + (-p.saving) + '% (ছোট ছবি; মেটাডেটা ~৩KB যোগ হয়েছে)</b>' : '') + (p.target_met ? '' : ' · <b style="color:var(--warn)">⚠ টার্গেট KB ছোঁয়া যায়নি</b>') + (p.data_uri ? '' : ' · (প্রিভিউ ছবি অনেক বড়, থাম্বনেইল দেখানো হচ্ছে)');
      $('pv_meta').innerHTML = diffTable(r.before, r.after);
      var f = r.seo, show = p.data_uri || fileUrl(img, 'thumb'), site = S.settings.upload_base_url || S.settings.site_url || '';
      var gurl = site ? site.replace(/\/?$/, '/') + r.final_name : r.final_name;
      $('pv_gimg').innerHTML = gimgCard(show, f.title, f.alt || f.description, site || S.settings.site_url);
      $('pv_share').innerHTML = ogCard(show, domainOf(S.settings.site_url || site), f.title, f.description || f.alt);
      $('pv_names').innerHTML = '<div class="kv"><b>ফাইলনাম</b><span>' + esc(r.final_name) + '</span><b>URL</b><span>' + esc(S.settings.upload_base_url ? r.url : '(Settings-এ "ছবির পাবলিক ফোল্ডার URL" দিলে পূর্ণ URL দেখাবে)') + '</span><b>Alt text</b><span>' + (f.alt ? esc(f.alt) : '<i class="muted">নেই</i>') + '</span><b>Caption</b><span>' + (f.caption ? esc(f.caption) : '<i class="muted">নেই</i>') + '</span><b>Focus keyword</b><span>' + (f.focus ? esc(f.focus) : '<i class="muted">নেই</i>') + '</span></div>';
      $('pv_msg').textContent = 'আসল প্রসেস চালিয়ে দেখানো হয়েছে (সেভ হয়নি)। ঠিক থাকলে "সেভ ও Process" চাপুন।';
    }).catch(function (e) { $('pv_msg').textContent = '✘ ' + e.message; }).then(function () { pvBusy = false; setBusy(btn, false); });
  }
  $('pv_go').onclick = previewNow;
  // click any zoomable image to enlarge
  document.addEventListener('click', function (e) {
    var im = e.target.closest ? e.target.closest('img.zoomable') : null;
    if (im && im.src) { var lb = $('lightbox'); lb.querySelector('img').src = im.src; lb.hidden = false; return; }
    if (e.target.closest && e.target.closest('#lightbox')) $('lightbox').hidden = true;
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('lightbox').hidden) { $('lightbox').hidden = true; e.stopImmediatePropagation(); } }, true);
  $('mImg').classList.add('zoomable');

  /* ---- HTML snippets for an image ---- */
  function absUrl(u) { return u; }
  function buildHtmlCode() {
    var img = byId(cur); if (!img || !$('mt-html').classList.contains('on')) return;
    var f = formSeo(), url = $('h_url').value.trim(), name = img.final_name, base = url ? url.replace(/[^\/]*$/, '') : '';
    var src = url || name, w = img.out_w || img.width, h = img.out_h || img.height;
    var set = (img.variants || []).map(function (v) { return (base || '') + name.replace(/(\.[a-z0-9]+)$/i, '-' + v.w + 'w$1') + ' ' + v.w + 'w'; });
    if (set.length) set.push(src + ' ' + w + 'w');
    var tag = '<img src="' + esc(src) + '"' + (set.length ? '\n     srcset="' + esc(set.join(',\n             ')) + '"\n     sizes="(max-width: 768px) 100vw, ' + w + 'px"' : '') + '\n     alt="' + esc(f.alt) + '"' + (f.title ? '\n     title="' + esc(f.title) + '"' : '') + '\n     width="' + w + '" height="' + h + '"\n     loading="lazy" decoding="async">';
    var out = '<!-- Image -->\n' + tag + '\n';
    if (f.caption) out = '<figure>\n' + tag.replace(/^/gm, '  ') + '\n  <figcaption>' + esc(f.caption) + '</figcaption>\n</figure>\n';
    var ld = { '@context': 'https://schema.org', '@type': 'ImageObject', contentUrl: url || name, name: f.title || undefined, description: f.description || f.alt || undefined, caption: f.caption || undefined, keywords: f.keywords.length ? f.keywords.join(', ') : undefined, width: w, height: h, author: S.settings.author ? { '@type': 'Person', name: S.settings.author } : undefined, copyrightNotice: (formMeta().copyright || S.settings.copyright || '').replace('{year}', new Date().getFullYear()).replace('{site_name}', S.settings.site_name || '') || undefined, encodingFormat: img.out_mime || img.mime };
    out += '\n<!-- JSON-LD (ImageObject) -->\n<script type="application/ld+json">\n' + JSON.stringify(ld, null, 2).replace(/</g, '\\u003c') + '\n<\/script>';
    $('h_code').value = out;
  }
  $('h_copy').onclick = function () { copy($('h_code').value); };

  /* ---------------- SERP preview ---------------- */
  function serpHtml(title, desc, url, mobile) {
    var tl = title || 'Page title…', tw = pxWidth(tl, '20px Arial'), dtxt = desc || 'Meta description…';
    var maxT = 580, tw2 = tw > maxT;
    var u = (url || 'https://example.com/page').replace(/^https?:\/\//, '').replace(/\//g, ' › ').replace(/ › $/, '');
    return '<div class="serp ' + (mobile ? 'mobile' : 'desk') + '"><div class="u">' + esc(u) + '</div><div class="t">' + esc(tl) + '</div><div class="d">' + esc(dtxt) + '</div></div>' +
      '<div class="muted" style="font-size:12px">টাইটেল ' + len(title) + ' অক্ষর · ' + tw + 'px / 580px ' + (tw2 ? '<b style="color:var(--bad)">(Google কেটে দেবে)</b>' : '<b style="color:var(--ok)">(ঠিক আছে)</b>') + ' · ডেসক্রিপশন ' + len(desc) + ' অক্ষর</div>';
  }

  /* ---------------- Website meta generator ---------------- */
  var metaRes = null, serpDev = 'desktop';
  function metaPrefill() { $('m_brand').value = $('m_brand').value || S.settings.site_name || ''; $('m_lang').value = S.settings.lang || 'en'; $('p_brand').value = $('p_brand').value || S.settings.site_name || ''; $('p_lang').value = S.settings.lang || 'en'; }
  $('m_go').onclick = function () {
    var b = this; $('m_msg').hidden = true; setBusy(b, true, '⏳ AI লিখছে…');
    api('ai_meta', { type: $('m_type').value, topic: $('m_topic').value, summary: $('m_summary').value, focus: $('m_focus').value, secondary: $('m_second').value, brand: $('m_brand').value, url: $('m_url').value, lang: $('m_lang').value, tone: $('m_tone').value, audience: $('m_aud').value, extra: $('m_extra').value, provider: $('m_prov').value }).then(function (r) {
      metaRes = r.result; $('m_out').hidden = false;
      $('o_title').value = metaRes.meta_title; $('o_desc').value = metaRes.meta_description; $('o_kw').value = metaRes.keywords.join(', ');
      $('o_slug').value = metaRes.slug; $('o_h1').value = metaRes.h1; $('o_schema').value = metaRes.schema_type; $('o_url').value = $('m_url').value;
      var mk = function (arr, target, id) { $(id).innerHTML = arr.map(function (t, i) { return '<div class="chip" data-t="' + esc(t) + '">' + esc(t) + '<small>' + len(t) + ' অক্ষর — ক্লিক করে বসান</small></div>'; }).join(''); };
      mk(metaRes.titles, 'o_title', 'o_titles'); mk(metaRes.descriptions, 'o_desc', 'o_descs');
      metaRender(); $('m_out').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).catch(function (e) { $('m_msg').textContent = e.message; $('m_msg').hidden = false; }).then(function () { setBusy(b, false); });
  };
  $('o_titles').addEventListener('click', function (e) { var c = e.target.closest('.chip'); if (c) { $('o_title').value = c.getAttribute('data-t'); metaRender(); } });
  $('o_descs').addEventListener('click', function (e) { var c = e.target.closest('.chip'); if (c) { $('o_desc').value = c.getAttribute('data-t'); metaRender(); } });
  ['o_title', 'o_desc', 'o_kw', 'o_slug', 'o_h1', 'o_schema', 'o_url', 'o_img'].forEach(function (id) { $(id).addEventListener('input', metaRender); });
  document.querySelector('#m_out .serp-tabs').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; serpDev = b.getAttribute('data-dev'); var bs = this.querySelectorAll('button'); for (var i = 0; i < bs.length; i++) bs[i].classList.toggle('on', bs[i] === b); metaRender(); });
  function metaRender() {
    if (!metaRes) return;
    $('m_serp').innerHTML = serpHtml($('o_title').value, $('o_desc').value, $('o_url').value || S.settings.site_url, serpDev === 'mobile');
    var og = (metaRes.og_title && $('o_title').value === metaRes.meta_title) ? metaRes.og_title : $('o_title').value;
    var ogd = (metaRes.og_description && $('o_desc').value === metaRes.meta_description) ? metaRes.og_description : $('o_desc').value;
    $('m_og').innerHTML = ogCard($('o_img').value.trim(), domainOf($('o_url').value || S.settings.site_url), og, ogd);
    $('o_html').value = buildMetaHtml(); updCounters();
  }
  function buildMetaHtml() {
    var t = $('o_title').value.trim(), d = $('o_desc').value.trim(), kw = listKw($('o_kw').value).join(', '), url = $('o_url').value.trim(), img = $('o_img').value.trim(), type = $('o_schema').value, brand = $('m_brand').value.trim(), lang = $('m_lang').value;
    var L = [], a = function (s) { L.push(s); };
    a('<title>' + esc(t) + '</title>');
    a('<meta name="description" content="' + esc(d) + '">');
    if (kw) a('<meta name="keywords" content="' + esc(kw) + '">');
    a('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">');
    if (url) a('<link rel="canonical" href="' + esc(url) + '">');
    a('');
    a('<!-- Open Graph -->');
    a('<meta property="og:type" content="' + (type === 'Article' ? 'article' : type === 'Product' ? 'product' : 'website') + '">');
    a('<meta property="og:title" content="' + esc(metaRes.og_title && metaRes.og_title.length <= 65 && $('o_title').value === metaRes.meta_title ? metaRes.og_title : t) + '">');
    a('<meta property="og:description" content="' + esc(metaRes.og_description && $('o_desc').value === metaRes.meta_description ? metaRes.og_description : d) + '">');
    if (url) a('<meta property="og:url" content="' + esc(url) + '">');
    if (brand) a('<meta property="og:site_name" content="' + esc(brand) + '">');
    a('<meta property="og:locale" content="' + (lang === 'bn' ? 'bn_BD' : 'en_US') + '">');
    if (img) a('<meta property="og:image" content="' + esc(img) + '">');
    a('');
    a('<!-- Twitter Card -->');
    a('<meta name="twitter:card" content="' + (img ? 'summary_large_image' : 'summary') + '">');
    a('<meta name="twitter:title" content="' + esc(metaRes.twitter_title && $('o_title').value === metaRes.meta_title ? metaRes.twitter_title : t) + '">');
    a('<meta name="twitter:description" content="' + esc(metaRes.twitter_description && $('o_desc').value === metaRes.meta_description ? metaRes.twitter_description : d) + '">');
    if (img) a('<meta name="twitter:image" content="' + esc(img) + '">');
    var ld = { '@context': 'https://schema.org', '@type': type };
    if (type === 'Article') { ld.headline = $('o_h1').value || t; ld.description = d; if (url) ld.mainEntityOfPage = url; if (img) ld.image = img; if (S.settings.author) ld.author = { '@type': 'Person', name: S.settings.author }; if (brand) ld.publisher = { '@type': 'Organization', name: brand }; }
    else if (type === 'Product') { ld.name = $('o_h1').value || t; ld.description = d; if (img) ld.image = img; if (brand) ld.brand = { '@type': 'Brand', name: brand }; if (url) ld.url = url; }
    else if (type === 'LocalBusiness' || type === 'Organization') { ld.name = brand || t; ld.description = d; if (url) ld.url = url; if (img) ld.image = img; }
    else if (type === 'Service') { ld.name = $('o_h1').value || t; ld.description = d; if (brand) ld.provider = { '@type': 'Organization', name: brand }; if (url) ld.url = url; }
    else { ld.name = t; ld.description = d; if (url) ld.url = url; if (img) ld.primaryImageOfPage = { '@type': 'ImageObject', url: img }; if (lang) ld.inLanguage = lang; }
    a(''); a('<!-- Schema.org JSON-LD -->'); a('<script type="application/ld+json">'); a(JSON.stringify(ld, null, 2).replace(/</g, '\\u003c')); a('<\/script>');
    return L.join('\n');
  }
  $('copyHtml').onclick = function () { copy($('o_html').value); };

  /* ---------------- Title/Description optimizer ---------------- */
  var OPT = {
    title: { max: 58, min: 30, px: 580, font: '20px Arial', name: 'টাইটেল' },
    meta_description: { max: 155, min: 120, px: 0, name: 'ডেসক্রিপশন' },
    product_title: { max: 80, min: 40, px: 0, name: 'প্রোডাক্ট টাইটেল' },
    product_description: { max: 1500, min: 300, px: 0, name: 'প্রোডাক্ট ডেসক্রিপশন' }
  };
  var POWER = /\b(best|top|free|guide|how to|ultimate|proven|easy|fast|new|2026|2025|official|complete|affordable|trusted|premium|সেরা|ফ্রি|সহজ|নির্ভরযোগ্য|সবচেয়ে|সম্পূর্ণ|অফিসিয়াল|দ্রুত)\b/i;
  var CTA = /(get|buy|order|call|contact|learn|discover|try|start|book|shop|find|see|read|জানুন|কিনুন|অর্ডার|যোগাযোগ|দেখুন|শুরু|বুক|কল)/i;
  function textScore(text, type, focus) {
    var o = OPT[type], n = len(text), f = (focus || '').toLowerCase().trim(), tl = text.toLowerCase(), it = [], add = function (st, msg, w) { it.push({ st: st, msg: msg, w: w }); };
    if (!n) return { score: 0, items: [] };
    if (type === 'title' || type === 'meta_description' || type === 'product_title') {
      add(n >= o.min && n <= o.max ? 'ok' : n <= o.max + 5 && n >= o.min - 10 ? 'warn' : 'bad', 'দৈর্ঘ্য ' + n + ' অক্ষর (আদর্শ ' + o.min + '–' + o.max + ')', 25);
    } else add(n >= o.min && n <= o.max ? 'ok' : n >= 120 ? 'warn' : 'bad', 'দৈর্ঘ্য ' + n + ' অক্ষর (আদর্শ ' + o.min + '–' + o.max + ')', 20);
    if (type === 'title') { var px = pxWidth(text, o.font); add(px <= o.px ? 'ok' : 'bad', 'Google-এ প্রস্থ ' + px + 'px (সীমা ~' + o.px + 'px)', 15); }
    if (f) {
      var pos = tl.indexOf(f);
      add(pos >= 0 ? 'ok' : 'bad', 'Focus keyword আছে', 25);
      if (type !== 'meta_description' && type !== 'product_description') add(pos >= 0 && pos <= 25 ? 'ok' : pos >= 0 ? 'warn' : 'bad', 'Keyword শুরুর দিকে', 10);
      if (type === 'product_description' || type === 'meta_description') { var cnt = tl.split(f).length - 1, words = text.split(/\s+/).length, den = words ? cnt * f.split(/\s+/).length * 100 / words : 0; add(den >= 0.5 && den <= 3 ? 'ok' : den > 3 ? 'warn' : 'bad', 'Keyword density ' + den.toFixed(1) + '% (আদর্শ ০.৫–৩%)', 10); }
    } else add('warn', 'Focus keyword দিলে আরও নির্ভুল স্কোর পাবেন', 10);
    if (type === 'meta_description') add(CTA.test(text) ? 'ok' : 'warn', 'Call-to-action শব্দ', 15);
    if (type === 'title' || type === 'product_title') add(POWER.test(text) || /\d/.test(text) ? 'ok' : 'warn', 'Power word / সংখ্যা', 10);
    var letters = text.replace(/[^A-Za-z]/g, ''), caps = letters.length > 6 && letters === letters.toUpperCase();
    add(!caps ? 'ok' : 'bad', 'সব বড় হাতের অক্ষর নয়', 8);
    add(!/[!?]{2,}|[-|]{3,}/.test(text) ? 'ok' : 'warn', 'অতিরিক্ত চিহ্ন নেই', 5);
    if (type === 'product_description') { add(/[.!।]/.test(text) && text.split(/[.!।]/).length >= 3 ? 'ok' : 'warn', 'একাধিক বাক্য / পরিষ্কার গঠন', 10); }
    var tot = 0, got = 0; it.forEach(function (x) { tot += x.w; got += x.st === 'ok' ? x.w : x.st === 'warn' ? x.w * 0.5 : 0; });
    return { score: Math.round(100 * got / tot), items: it };
  }
  function scoreHtml(r) { return '<div class="score"><div class="ring ' + cls(r.score) + '">' + r.score + '</div><div><b>SEO স্কোর</b><br><span class="muted">১০০ এর কাছাকাছি হলে ভালো</span></div></div><ul class="checks">' + r.items.map(function (x) { return '<li class="' + x.st + '">' + (x.st === 'ok' ? '✔' : x.st === 'warn' ? '!' : '✘') + ' ' + esc(x.msg) + '</li>'; }).join('') + '</ul>'; }
  function optRender() {
    var type = $('p_type').value, t = $('p_text').value.trim();
    if (!t) { $('p_score').innerHTML = '<p class="muted">টেক্সট পেস্ট করলে লাইভ স্কোর দেখাবে</p>'; $('p_serp').innerHTML = ''; $('p_card').innerHTML = ''; return; }
    $('p_score').innerHTML = scoreHtml(textScore(t, type, $('p_focus').value));
    var url = S.settings.site_url;
    $('p_serp').innerHTML = type === 'title' ? serpHtml(t, '', url, false) : type === 'meta_description' ? serpHtml('Page title…', t, url, false) : '';
    $('p_card').innerHTML = type === 'product_title' ? '<div class="pcard"><div class="pimg"></div><div style="min-width:0"><div class="pt">' + esc(t) + '</div><div class="muted" style="font-size:12px;margin-top:4px">লিস্টিংয়ে ২ লাইনের পরে কাটা পড়ে — ' + len(t) + ' অক্ষর</div></div></div>' : type === 'product_description' ? '<div class="pcard"><div class="pbody">' + esc(t) + '</div></div>' : '';
  }
  ['p_type', 'p_text', 'p_focus'].forEach(function (id) { $(id).addEventListener('input', optRender); });
  $('p_go').onclick = function () {
    var b = this; $('p_msg').hidden = true; setBusy(b, true, '⏳ AI লিখছে…');
    api('ai_optimize', { type: $('p_type').value, text: $('p_text').value, focus: $('p_focus').value, brand: $('p_brand').value, lang: $('p_lang').value, provider: $('p_prov').value }).then(function (r) {
      var type = $('p_type').value, focus = $('p_focus').value;
      $('p_variants').className = '';
      $('p_variants').innerHTML = r.variants.map(function (v, i) { var sc = textScore(v.text, type, focus); return '<div class="variant"><p>' + esc(v.text) + '</p><div class="why">' + esc(v.why || '') + '</div><div class="acts"><span class="pill on">স্কোর ' + sc.score + '</span><span class="pill">' + len(v.text) + ' অক্ষর</span><span class="sp"></span><button class="btn sm" data-copy="' + i + '">কপি</button><button class="btn sm" data-use="' + i + '">এটি ব্যবহার করুন</button></div></div>'; }).join('');
      $('p_variants')._v = r.variants;
    }).catch(function (e) { $('p_msg').textContent = e.message; $('p_msg').hidden = false; }).then(function () { setBusy(b, false); });
  };
  $('p_variants').addEventListener('click', function (e) {
    var v = this._v || []; var c = e.target.getAttribute('data-copy'), u = e.target.getAttribute('data-use');
    if (c !== null) copy(v[+c].text); if (u !== null) { $('p_text').value = v[+u].text; optRender(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  });

  /* ---------------- Settings ---------------- */
  function renderSettings() {
    var s = S.settings;
    $('s_site_name').value = s.site_name; $('s_site_url').value = s.site_url; $('s_author').value = s.author; $('s_copyright').value = s.copyright; $('s_base').value = s.upload_base_url; $('s_lang').value = s.lang;
    var d = s.def_opt; $('s_fmt').value = d.format; $('s_mw').value = d.max_width; $('s_mh').value = d.max_height; $('s_q').value = d.quality; $('s_kb').value = d.target_kb; $('s_resp').checked = !!d.responsive;
    var po = ''; for (var k in s.providers) po += '<option value="' + k + '">' + esc(s.providers[k].label) + '</option>'; $('s_primary').innerHTML = po; $('s_primary').value = s.ai_primary;
    var h = ''; for (k in s.providers) { var p = s.providers[k];
      h += '<div class="prov" data-p="' + k + '"><h4><span class="dot' + (p.has_key ? ' on' : '') + '"></span>' + esc(p.label) + (p.has_key ? ' <span class="muted">(' + esc(p.hint) + ')</span>' : '') + '</h4>' +
        '<div class="row2"><label>API key<input type="password" class="pk" placeholder="' + (p.has_key ? 'সেভ করা আছে — বদলাতে নতুন key দিন' : 'API key পেস্ট করুন') + '" autocomplete="off"></label>' +
        '<label>Model<input class="pm" list="dl_' + k + '" value="' + esc(p.model) + '" placeholder="' + esc(p.default_model) + '"><datalist id="dl_' + k + '"></datalist></label></div>' +
        '<div class="acts"><button class="btn sm ptest" ' + (p.has_key ? '' : 'disabled') + '>টেস্ট</button><button class="btn sm pmodels" ' + (p.has_key ? '' : 'disabled') + '>Model তালিকা</button>' + (p.has_key ? '<button class="btn sm danger pclear">key মুছুন</button>' : '') + '<span class="muted pst"></span></div></div>'; }
    $('provBox').innerHTML = h;
    $('caps').innerHTML = 'WebP: ' + (S.caps.webp ? '✔' : '✘') + ' · EXIF: ' + (S.caps.exif ? '✔' : '✘') + ' · ZIP: ' + (S.caps.zip ? '✔' : '✘') + ' · আপলোড লিমিট: ' + esc(S.caps.upload_max) + ' · v' + esc(S.version || '');
    fillProviderSelects(); metaPrefill(); cprev();
  }
  function cprev() { var t = ($('s_copyright').value || '').replace(/\{year\}/g, new Date().getFullYear()).replace(/\{site_name\}/g, $('s_site_name').value).replace(/\{author\}/g, $('s_author').value); $('s_cprev').textContent = 'ছবির Copyright-এ বসবে: ' + (t.trim() || '—') + ' · Author: ' + ($('s_author').value || '—'); }
  ['s_copyright', 's_site_name', 's_author'].forEach(function (id) { $(id).addEventListener('input', cprev); });
  $('provBox').addEventListener('click', function (e) {
    var box = e.target.closest('.prov'); if (!box) return; var p = box.getAttribute('data-p'), st = box.querySelector('.pst'), b = e.target;
    if (b.classList.contains('ptest')) { setBusy(b, true, '…'); st.textContent = ''; api('test_provider', { provider: p }).then(function (r) { st.innerHTML = r.ok_provider ? '<b style="color:var(--ok)">✔ কাজ করছে (' + r.ms + 'ms · ' + esc(r.model) + ')</b>' : '<b style="color:var(--warn)">উত্তর এসেছে কিন্তু ফরম্যাট ভুল</b>'; }, function (er) { st.innerHTML = '<b style="color:var(--bad)">✘ ' + esc(er.message) + '</b>'; }).then(function () { setBusy(b, false); }); }
    if (b.classList.contains('pmodels')) { setBusy(b, true, '…'); api('list_models', { provider: p }).then(function (r) { box.querySelector('datalist').innerHTML = r.models.map(function (m) { return '<option value="' + esc(m) + '">'; }).join(''); st.textContent = r.models.length + ' টি model — Model ঘরে ক্লিক করে বাছুন'; }, function (er) { st.innerHTML = '<b style="color:var(--bad)">✘ ' + esc(er.message) + '</b>'; }).then(function () { setBusy(b, false); }); }
    if (b.classList.contains('pclear')) { if (!confirm('এই key মুছে ফেলবেন?')) return; var pr = {}; pr[p] = { key: '__clear__' }; api('settings_save', { providers: pr }).then(function (r) { applyBoot(r); toast('key মুছে ফেলা হয়েছে'); }); }
  });
  $('s_save').onclick = function () {
    var b = this, prov = {}; var boxes = $('provBox').querySelectorAll('.prov');
    for (var i = 0; i < boxes.length; i++) { var k = boxes[i].getAttribute('data-p'); prov[k] = { key: boxes[i].querySelector('.pk').value, model: boxes[i].querySelector('.pm').value }; }
    setBusy(b, true);
    api('settings_save', { site_name: $('s_site_name').value, site_url: $('s_site_url').value, author: $('s_author').value, copyright: $('s_copyright').value, upload_base_url: $('s_base').value, lang: $('s_lang').value, ai_primary: $('s_primary').value,
      def_opt: { format: $('s_fmt').value, max_width: $('s_mw').value, max_height: $('s_mh').value, quality: $('s_q').value, target_kb: $('s_kb').value, responsive: $('s_resp').checked }, providers: prov }).then(function (r) { applyBoot(r); $('s_msg').textContent = 'সেভ হয়েছে ✔'; toast('Settings সেভ হয়েছে'); }, function (e) { $('s_msg').textContent = '✘ ' + e.message; }).then(function () { setBusy(b, false); });
  };
  $('pw_go').onclick = function () { api('password', { current: $('pw_cur').value, 'new': $('pw_new').value }).then(function () { $('pw_msg').textContent = 'পাসওয়ার্ড বদলেছে ✔'; $('pw_cur').value = $('pw_new').value = ''; }, function (e) { $('pw_msg').textContent = '✘ ' + e.message; }); };

  /* ---------------- boot ---------------- */
  function applyBoot(r) { S.csrf = r.csrf; S.settings = r.settings; S.caps = r.caps; S.version = r.version; if (r.images) S.images = r.images; renderSettings(); renderGrid(); }
  fetch('api.php?action=bootstrap', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.ok) { if (j.error) { location.href = 'login.php'; } return; }
      applyBoot(j); optRender();
      try { var t = localStorage.getItem('seo_tab'); if (t) { var b = document.querySelector('#tabs button[data-tab="' + t + '"]'); if (b) b.click(); } } catch (e) { }
      var any = false; for (var k in S.settings.providers) if (S.settings.providers[k].has_key) any = true;
      if (!any) toast('শুরুতে Settings ট্যাবে গিয়ে অন্তত একটি AI API key দিন', 6000);
    }, function () { toast('সার্ভারের সাথে সংযোগ হচ্ছে না'); });
})();
