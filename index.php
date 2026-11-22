<?php
require __DIR__ . '/lib/core.php';
if (!is_installed()) {
    header('Location: install.php');
    exit;
}
ensure_storage();
start_session();
if (!current_user_id()) {
    header('Location: login.php');
    exit;
}
$v = APP_VERSION;
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SEO Studio</title>
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<header class="top">
  <div class="brand">SEO <b>Studio</b></div>
  <nav id="tabs">
    <button data-tab="images" class="on">🖼 ছবি SEO</button>
    <button data-tab="meta">🌐 ওয়েবসাইট Meta</button>
    <button data-tab="opt">✨ Title/Description Optimizer</button>
    <button data-tab="settings">⚙ Settings</button>
  </nav>
  <a class="logout" href="login.php?logout=1">লগআউট</a>
</header>

<main>
<!-- ===================== IMAGES ===================== -->
<section id="tab-images" class="tab on">
  <div id="drop" class="drop">
    <input id="fileInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
    <div><b>ছবি এখানে ড্র্যাগ করুন</b> অথবা <button class="btn" id="pick" type="button">ফাইল বাছাই করুন</button></div>
    <small>JPG · PNG · WebP · GIF — একসাথে অনেকগুলো (Bulk) দেওয়া যায়। Ctrl+V দিয়ে পেস্টও করা যায়।</small>
    <div id="upbar" class="upbar" hidden><i></i><span></span></div>
  </div>

  <div id="imgToolbar" class="toolbar" hidden>
    <label class="chk"><input type="checkbox" id="selAll"> সব</label>
    <span id="selCount" class="muted"></span>
    <span class="sp"></span>
    <button class="btn" id="bulkAi">🤖 AI দিয়ে SEO লিখুন</button>
    <button class="btn" id="bulkProcess">⚡ Process (সব)</button>
    <button class="btn" id="bulkApply">⇩ Apply settings…</button>
    <span class="sp"></span>
    <button class="btn" id="exZip">ZIP</button>
    <button class="btn" id="exZipJpg">ZIP (JPG)</button>
    <button class="btn" id="exCsv">CSV</button>
    <button class="btn" id="exWp">WP CSV</button>
    <button class="btn" id="exMap">Image Sitemap</button>
    <button class="btn danger" id="bulkDel">মুছুন</button>
  </div>
  <div id="bulkLog" class="log" hidden></div>
  <div id="grid" class="grid"></div>
  <div id="empty" class="empty">এখনও কোনো ছবি নেই। উপরে আপলোড করুন — তারপর AI দিয়ে Title, Alt Text, Description, Tags ও SEO ফাইলনাম তৈরি করে Process চাপুন।</div>
</section>

<!-- ===================== META GENERATOR ===================== -->
<section id="tab-meta" class="tab">
  <div class="cols">
    <div class="card">
      <h2>পেজের তথ্য</h2>
      <label>পেজের ধরন
        <select id="m_type">
          <option>WebPage</option><option>Article</option><option>Product</option><option>Service</option>
          <option>LocalBusiness</option><option>Organization</option><option>CollectionPage</option>
          <option>FAQPage</option><option>AboutPage</option><option>ContactPage</option>
        </select>
      </label>
      <label>পেজের টপিক / টাইটেল <input id="m_topic" placeholder="যেমন: ঢাকায় ওয়েবসাইট ডিজাইন সার্ভিস"></label>
      <label>পেজের কনটেন্ট / সারাংশ (যত বেশি দেবেন, ফল তত ভালো)
        <textarea id="m_summary" rows="5" placeholder="পেজে কী আছে, কী অফার করছেন, কাদের জন্য…"></textarea></label>
      <div class="row2">
        <label>Focus keyword <input id="m_focus" placeholder="web design dhaka"></label>
        <label>Secondary keywords <input id="m_second" placeholder="কমা দিয়ে আলাদা করুন"></label>
      </div>
      <div class="row2">
        <label>ব্র্যান্ড <input id="m_brand"></label>
        <label>পেজ URL (ঐচ্ছিক) <input id="m_url" placeholder="https://…"></label>
      </div>
      <div class="row2">
        <label>ভাষা <select id="m_lang"><option value="en">English</option><option value="bn">বাংলা</option></select></label>
        <label>টোন <select id="m_tone"><option>professional</option><option>friendly</option><option>persuasive</option><option>formal</option><option>casual</option></select></label>
      </div>
      <label>টার্গেট অডিয়েন্স / লোকেশন <input id="m_aud" placeholder="Dubai, UAE-র ব্যবসায়ী"></label>
      <label>বিশেষ নির্দেশনা (ঐচ্ছিক) <input id="m_extra" placeholder="যেমন: 'Free quote' উল্লেখ করুন"></label>
      <div class="row2">
        <label>AI প্রোভাইডার <select id="m_prov" class="provSel"></select></label>
        <label>&nbsp;<button class="btn primary block" id="m_go">🤖 Meta তৈরি করুন</button></label>
      </div>
      <div id="m_msg" class="alert bad" hidden></div>
    </div>

    <div class="card" id="m_out" hidden>
      <h2>ফলাফল</h2>
      <div class="serp-tabs"><button data-dev="desktop" class="on">Desktop</button><button data-dev="mobile">Mobile</button></div>
      <div id="m_serp" class="serp"></div>
      <h3>Facebook / WhatsApp / LinkedIn শেয়ার প্রিভিউ</h3>
      <div id="m_og"></div>

      <h3>Meta Title <span class="cnt" data-for="o_title"></span></h3>
      <input id="o_title"><div id="o_titles" class="chips"></div>
      <h3>Meta Description <span class="cnt" data-for="o_desc"></span></h3>
      <textarea id="o_desc" rows="3"></textarea><div id="o_descs" class="chips"></div>
      <h3>Keywords / Tags</h3>
      <textarea id="o_kw" rows="2"></textarea>
      <div class="row2">
        <label>URL Slug <input id="o_slug"></label>
        <label>H1 <input id="o_h1"></label>
      </div>
      <div class="row2">
        <label>Schema type <select id="o_schema"><option>WebPage</option><option>Article</option><option>Product</option><option>Service</option><option>LocalBusiness</option><option>Organization</option><option>FAQPage</option><option>CollectionPage</option><option>ContactPage</option><option>AboutPage</option></select></label>
        <label>Canonical / পেজ URL <input id="o_url"></label>
      </div>
      <label>OG/Twitter ছবির URL (ঐচ্ছিক) <input id="o_img" placeholder="https://…/image.webp"></label>
      <h3>কপি-রেডি HTML <button class="btn sm" id="copyHtml">কপি</button></h3>
      <textarea id="o_html" rows="14" readonly class="code"></textarea>
    </div>
  </div>
</section>

<!-- ===================== OPTIMIZER ===================== -->
<section id="tab-opt" class="tab">
  <div class="cols">
    <div class="card">
      <h2>যেকোনো টেক্সট SEO অপটিমাইজ করুন</h2>
      <label>কনটেন্টের ধরন
        <select id="p_type">
          <option value="title">SEO পেজ টাইটেল</option>
          <option value="meta_description">Meta Description</option>
          <option value="product_title">প্রোডাক্ট টাইটেল (E-commerce)</option>
          <option value="product_description">প্রোডাক্ট ডেসক্রিপশন</option>
        </select>
      </label>
      <label>আপনার টেক্সট পেস্ট করুন <textarea id="p_text" rows="6"></textarea></label>
      <div class="row2">
        <label>Focus keyword <input id="p_focus"></label>
        <label>ব্র্যান্ড <input id="p_brand"></label>
      </div>
      <div class="row2">
        <label>আউটপুট ভাষা <select id="p_lang"><option value="en">English</option><option value="bn">বাংলা</option></select></label>
        <label>AI প্রোভাইডার <select id="p_prov" class="provSel"></select></label>
      </div>
      <p><button class="btn primary" id="p_go">🤖 ৩টি অপটিমাইজড ভার্সন তৈরি করুন</button></p>
      <div id="p_msg" class="alert bad" hidden></div>
    </div>
    <div class="card">
      <h2>SEO স্কোর (লাইভ)</h2>
      <div id="p_score"></div>
      <div id="p_serp" class="serp"></div>
      <div id="p_card"></div>
      <h3>অপটিমাইজড ভার্সন</h3>
      <div id="p_variants" class="muted">AI চালালে এখানে দেখাবে</div>
    </div>
  </div>
</section>

<!-- ===================== SETTINGS ===================== -->
<section id="tab-settings" class="tab">
  <div class="cols">
    <div class="card">
      <h2>সাইট ও ডিফল্ট</h2>
      <div class="row2">
        <label>ব্র্যান্ড / সাইটের নাম <input id="s_site_name"></label>
        <label>ওয়েবসাইট URL <input id="s_site_url" placeholder="https://creativesit.com"></label>
      </div>
      <div class="row2">
        <label>Author / Creator <input id="s_author"></label>
        <label>Copyright টেমপ্লেট <input id="s_copyright" placeholder="© {year} {site_name}"></label>
      </div>
      <div id="s_cprev" class="muted" style="margin:-4px 0 10px"></div>
      <label>ছবির পাবলিক ফোল্ডার URL (CSV/Sitemap-এ ব্যবহার হবে)
        <input id="s_base" placeholder="https://yoursite.com/wp-content/uploads/2026/11/"></label>
      <div class="row2">
        <label>ডিফল্ট ভাষা <select id="s_lang"><option value="en">English</option><option value="bn">বাংলা</option></select></label>
        <label>প্রাইমারি AI <select id="s_primary"></select></label>
      </div>
      <h3>ডিফল্ট ইমেজ অপটিমাইজেশন</h3>
      <div class="row3">
        <label>ফরম্যাট <select id="s_fmt"><option value="webp">WebP (সেরা)</option><option value="jpeg">JPEG</option><option value="png">PNG</option><option value="keep">মূল ফরম্যাট</option></select></label>
        <label>Max width (px) <input id="s_mw" type="number" min="0"></label>
        <label>Quality (30–100) <input id="s_q" type="number" min="30" max="100"></label>
      </div>
      <div class="row3">
        <label>Max height (px, 0 = অটো) <input id="s_mh" type="number" min="0"></label>
        <label>টার্গেট সাইজ KB (0 = বন্ধ) <input id="s_kb" type="number" min="0"></label>
        <label class="chk pad"><input type="checkbox" id="s_resp"> Responsive সাইজ (480/768/1200/1600w) তৈরি</label>
      </div>
      <p><button class="btn primary" id="s_save">সেভ করুন</button> <span id="s_msg" class="muted"></span></p>
    </div>

    <div class="card">
      <h2>AI API Keys</h2>
      <p class="muted">Key সার্ভারে এনক্রিপ্টেড থাকে এবং ব্রাউজারে আর কখনো দেখানো হয় না। একাধিক key দিলে প্রাইমারি ব্যর্থ হলে পরেরটি অটো চলবে।</p>
      <div id="provBox"></div>
      <h3>পাসওয়ার্ড পরিবর্তন</h3>
      <div class="row2">
        <label>বর্তমান <input type="password" id="pw_cur"></label>
        <label>নতুন (৮+) <input type="password" id="pw_new"></label>
      </div>
      <p><button class="btn" id="pw_go">পাসওয়ার্ড বদলান</button> <span id="pw_msg" class="muted"></span></p>
      <h3>সার্ভার</h3>
      <div id="caps" class="muted"></div>
    </div>
  </div>
</section>
</main>

<!-- ===================== IMAGE EDITOR MODAL ===================== -->
<div id="modal" class="modal" hidden>
  <div class="mbox">
    <div class="mhead"><b id="mTitle"></b><span class="sp"></span><button class="btn sm" id="mPrev">◀</button><button class="btn sm" id="mNext">▶</button><button class="btn sm" id="mClose">✕</button></div>
    <div class="mbody">
      <div class="mleft">
        <div class="preview"><img id="mImg" alt=""></div>
        <div id="mStats" class="stats"></div>
        <div class="seo-check" id="mCheck"></div>
        <div class="row2 gap">
          <a class="btn sm" id="mDlOut" target="_blank">⇩ অপটিমাইজড</a>
          <a class="btn sm" id="mDlJpg">⇩ JPG</a>
          <a class="btn sm" id="mDlOrig" target="_blank">⇩ অরিজিনাল</a>
        </div>
      </div>
      <div class="mright">
        <div class="mtabs"><button data-mt="seo" class="on">SEO কনটেন্ট</button><button data-mt="opt">অপটিমাইজ</button><button data-mt="meta">মেটাডেটা</button><button data-mt="prev">👁 প্রিভিউ</button><button data-mt="html">HTML কোড</button></div>

        <div class="mt on" id="mt-seo">
          <div class="row3">
            <label>Focus keyword <input id="e_focus" placeholder="যেমন: dhaka street food"></label>
            <label>ভাষা <select id="e_lang"><option value="en">English</option><option value="bn">বাংলা</option></select></label>
            <label>AI <select id="e_prov" class="provSel"></select></label>
          </div>
          <label>কনটেক্সট (ঐচ্ছিক — ছবি কোথায়/কী নিয়ে) <input id="e_ctx" placeholder="যেমন: রেস্টুরেন্টের মেনু পেজ"></label>
          <p><button class="btn primary" id="e_ai">🤖 AI দিয়ে সব লিখুন</button> <span id="e_msg" class="muted"></span></p>
          <label>SEO ফাইলনাম <span class="cnt" id="c_fn"></span><input id="e_filename"></label>
          <label>Title (ছবির টাইটেল) <span class="cnt" data-for="e_title"></span><input id="e_title"></label>
          <label>Alt Text <span class="cnt" data-for="e_alt"></span><input id="e_alt"></label>
          <label>Caption <span class="cnt" data-for="e_caption"></span><input id="e_caption"></label>
          <label>Description <span class="cnt" data-for="e_desc"></span><textarea id="e_desc" rows="3"></textarea></label>
          <label>Tags / Keywords (কমা দিয়ে আলাদা)<textarea id="e_kw" rows="2"></textarea></label>
        </div>

        <div class="mt" id="mt-opt">
          <div class="row3">
            <label>ফরম্যাট <select id="o_fmt"><option value="webp">WebP</option><option value="jpeg">JPEG</option><option value="png">PNG</option><option value="keep">মূল ফরম্যাট</option></select></label>
            <label>Max width (px) <input id="o_mw" type="number" min="0"></label>
            <label>Max height (px) <input id="o_mh" type="number" min="0"></label>
          </div>
          <div class="row3">
            <label>Quality <input id="o_q" type="number" min="30" max="100"></label>
            <label>টার্গেট KB (0=বন্ধ) <input id="o_kb" type="number" min="0"></label>
            <label class="chk pad"><input type="checkbox" id="o_resp"> Responsive সাইজ</label>
          </div>
          <p class="muted">Process চাপলে ছবি ছোট/কম্প্রেস হবে, অরিয়েন্টেশন ঠিক হবে এবং SEO ফাইলনাম ও সব মেটাডেটা ছবির ভেতরে বসে যাবে।</p>
        </div>

        <div class="mt" id="mt-meta">
          <p class="muted">খালি রাখলে SEO ট্যাব ও Settings থেকে অটো নেওয়া হবে। এই ঘরগুলো দিলে সেগুলোই EXIF/IPTC/XMP-তে বসবে।</p>
          <label>Title override <input id="x_title"></label>
          <label>Description override <textarea id="x_desc" rows="2"></textarea></label>
          <div class="row2"><label>Author / Creator <input id="x_author"></label><label>Copyright <input id="x_copy"></label></div>
          <div class="row2"><label>Website / Source URL <input id="x_web"></label><label>Software / Creator tool <input id="x_soft"></label></div>
          <div class="row3"><label>Camera make <input id="x_make"></label><label>Camera model <input id="x_model"></label><label>তোলার তারিখ <input id="x_dt" placeholder="2026-11-13 11:48"></label></div>
          <div class="row3"><label>Latitude <input id="x_lat" placeholder="23.7104"></label><label>Longitude <input id="x_lon" placeholder="90.4074"></label><label>&nbsp;<button class="btn block" id="x_clearGps" type="button">GPS মুছুন</button></label></div>
          <p><button class="btn" id="x_read">🔍 বর্তমান মেটাডেটা দেখুন</button> <button class="btn" id="x_readOut">🔍 Process-করা ছবির মেটাডেটা যাচাই</button></p>
          <div id="x_view" class="metaview"></div>
        </div>

        <div class="mt" id="mt-prev">
          <p><button class="btn primary" id="pv_go">🔄 প্রিভিউ তৈরি করুন</button> <span id="pv_msg" class="muted">ফর্মের বর্তমান মান দিয়ে (সেভ না করেই) আসল প্রসেস চালিয়ে দেখানো হয়</span></p>
          <div id="pv_out" hidden>
            <h3>ছবি: আগে → পরে <small class="muted">(বড় করতে ক্লিক)</small></h3>
            <div class="cmp">
              <figure><img id="pv_before" class="zoomable" alt=""><figcaption id="pv_bcap"></figcaption></figure>
              <figure><img id="pv_after" class="zoomable" alt=""><figcaption id="pv_acap"></figcaption></figure>
            </div>
            <h3>ছবির ভেতরের মেটাডেটা: আগে → পরে</h3>
            <div id="pv_meta"></div>
            <h3>Google Images-এ যেমন দেখাবে</h3>
            <div id="pv_gimg"></div>
            <h3>Facebook / WhatsApp শেয়ারে যেমন দেখাবে</h3>
            <div id="pv_share"></div>
            <h3>ফাইলনাম, URL ও Alt</h3>
            <div id="pv_names"></div>
          </div>
        </div>

        <div class="mt" id="mt-html">
          <label>ছবির URL (ঐচ্ছিক) <input id="h_url" placeholder="https://…"></label>
          <p class="muted">&lt;img&gt;, &lt;picture&gt;, JSON-LD ImageObject — কপি করে পেজে বসান।</p>
          <textarea id="h_code" rows="16" class="code" readonly></textarea>
          <p><button class="btn" id="h_copy">কপি</button></p>
        </div>
      </div>
    </div>
    <div class="mfoot">
      <span id="mSaveState" class="muted"></span><span class="sp"></span>
      <button class="btn" id="mSave">💾 সেভ</button>
      <button class="btn primary" id="mProcess">⚡ সেভ ও Process</button>
    </div>
  </div>
</div>

<div id="lightbox" class="lightbox" hidden><img alt=""></div>
<div id="toast" class="toast" hidden></div>
<script>window.__V = <?= json_encode($v) ?>;</script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
