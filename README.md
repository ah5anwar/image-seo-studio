# SEO Studio — ছবি, টাইটেল, ডেসক্রিপশন ও Meta SEO টুল

PHP + MySQL (cPanel-এ চলে) · AI: Gemini, Claude, OpenAI, OpenRouter · UI: বাংলা

## কী কী করা যায়

**১. ছবি SEO (Images ট্যাব)**
- Bulk আপলোড (ড্র্যাগ-ড্রপ / ফাইল বাছাই / Ctrl+V পেস্ট) — JPG, PNG, WebP, GIF
- AI ছবি *দেখে* লেখে: SEO ফাইলনাম, Title, Alt Text, Caption, Description, Tags (বাংলা বা ইংরেজি, Focus keyword সহ)
- কম্প্রেশন, Resize, WebP/JPEG/PNG কনভার্ট, টার্গেট KB, Responsive সাইজ (480/768/1200/1600w)
- ছবির ভেতরে মেটাডেটা বসায় (EXIF + IPTC + XMP, বাংলা Unicode সহ): Title, Description, Keywords, Author, Copyright, Website, Camera, তারিখ, GPS
- অরিজিনাল ছবির ক্যামেরা/GPS (ব্যক্তিগত লোকেশন) স্বয়ংক্রিয়ভাবে মুছে যায়; GPS শুধু তখনই বসে যখন আপনি নিজে দেন
- Orientation ঠিক করে (মোবাইলের ঘোরানো ছবি সোজা হয়)
- Process-এর পর ছবির ভেতরের মেটাডেটা পড়ে যাচাই করে দেখায়
- **👁 প্রিভিউ ট্যাব (সেভ না করেই):** আসল প্রসেস চালিয়ে দেখায় — ছবি আগে/পরে (সাইজ, ডাইমেনশন, সাশ্রয়), ছবির ভেতরের মেটাডেটা আগে → পরে (নতুন / বদলেছে / মুছে গেছে), Google Images কার্ড, Facebook/WhatsApp শেয়ার কার্ড, ফাইলনাম/URL/Alt। ছবিতে ক্লিক করলে বড় করে দেখা যায়
- প্রতিটি ছবির লাইভ SEO স্কোর (১০০ এর মধ্যে) ও কী ঠিক করতে হবে তার তালিকা
- `<img>` / `srcset` / JSON-LD `ImageObject` কপি-রেডি কোড
- Export: ZIP (ছবি + CSV), CSV, WordPress CSV, Google Image Sitemap

**২. ওয়েবসাইট Meta (Meta ট্যাব)**
- Meta Title (≤৫৮), Meta Description (১৩০–১৫৫), ৩টি করে বিকল্প, Keywords/Tags, Slug, H1
- Open Graph + Twitter Card + Canonical + Robots + Schema.org JSON-LD — একসাথে কপি-রেডি HTML
- Google SERP প্রিভিউ (Desktop/Mobile) + পিক্সেল-প্রস্থ চেক এবং Facebook/WhatsApp শেয়ার কার্ড প্রিভিউ

**৩. Title/Description Optimizer**
- যেকোনো SEO টাইটেল, Meta Description, প্রোডাক্ট টাইটেল, প্রোডাক্ট ডেসক্রিপশন পেস্ট করুন
- লাইভ SEO স্কোর (দৈর্ঘ্য, পিক্সেল, keyword, power word, CTA, density…)
- AI ৩টি অপটিমাইজড ভার্সন দেয় + প্রতিটির স্কোর

AI-এর লেখা সবসময় সার্ভারে দৈর্ঘ্য-যাচাই হয়: সীমার বেশি হলে AI-কে দিয়ে ছোট করানো হয়, তারপরও বেশি থাকলে শব্দের সীমানায় ছাঁটা হয়।

## cPanel-এ ইনস্টল (৫ মিনিট)

1. **PHP ভার্সন:** cPanel → *Select PHP Version* → PHP **8.1 বা তার বেশি**। Extensions চালু রাখুন: `gd`, `mbstring`, `curl`, `zip`, `exif`, `openssl`, `pdo_mysql`, `fileinfo`, `iconv`।
2. **ডাটাবেস:** cPanel → *MySQL Databases* → নতুন Database + User বানিয়ে User-কে Database-এ *ALL PRIVILEGES* দিন।
3. **আপলোড:** `seo-studio.zip` → File Manager → `public_html/seo` (বা সাবডোমেইন ফোল্ডার, যেমন `seo.yourdomain.com`) → *Extract*।
4. ব্রাউজারে `https://yourdomain.com/seo/install.php` খুলুন → সার্ভার চেক সব ✔ হলে DB তথ্য, Admin username/password দিন → **ইনস্টল করুন**।
5. ইনস্টল শেষে **`install.php` মুছে দিন**।
6. লগইন করে **Settings** ট্যাবে: সাইটের নাম, URL, Author, Copyright এবং AI API key দিন → *টেস্ট* বাটনে কাজ করছে কিনা দেখুন।

> HTTPS ব্যবহার করুন (cPanel-এর AutoSSL চালু করুন)।
> আপলোড লিমিট ছোট হলে cPanel → *MultiPHP INI Editor* থেকে `upload_max_filesize` ও `post_max_size` বাড়ান (প্যাকেজে `.user.ini` ও `.htaccess`-এ ৬৪MB দেওয়া আছে)।

## AI API key কোথায় পাবেন

| প্রোভাইডার | কোথায় | ডিফল্ট মডেল (বদলানো যায়) |
|---|---|---|
| Gemini | aistudio.google.com → Get API key | `gemini-2.5-flash` |
| Claude | console.anthropic.com → API keys | `claude-sonnet-5-5` |
| OpenAI | platform.openai.com → API keys | `gpt-4o-mini` |
| OpenRouter | openrouter.ai → Keys | `google/gemini-2.5-flash` |

- একাধিক key দিলে **প্রাইমারি** ব্যর্থ হলে (কোটা শেষ, নেটওয়ার্ক ইত্যাদি) পরেরটি নিজে থেকে চলে।
- ছবির জন্য এমন model লাগবে যা **ইমেজ বোঝে (vision)**। OpenRouter-এ শুধু vision model-ই তালিকায় আসে ("Model তালিকা" বাটন)।
- Key সার্ভারে AES-256 দিয়ে এনক্রিপ্টেড থাকে এবং ব্রাউজারে আর দেখানো হয় না।

## ১০০% কাজ করানোর ওয়ার্কফ্লো (ছবি)

1. ছবি আপলোড করুন → ছবিতে ক্লিক করুন
2. **Focus keyword** লিখুন (যে শব্দে র‍্যাংক করাতে চান) + কনটেক্সট (ঐচ্ছিক) → **🤖 AI দিয়ে সব লিখুন**
3. Title/Alt/Description দেখে নিন; স্কোর **৯০+** রাখুন (সবুজ ✔)
4. **⚡ সেভ ও Process** → ছবির ভেতরের মেটাডেটা যাচাই হয়ে যাবে
5. **ZIP** নামিয়ে ওয়েবসাইটে আপলোড করুন (ফাইলনামই SEO-ফ্রেন্ডলি), Alt Text/Caption **WP CSV** বা **HTML কোড** থেকে বসান
6. ছবির পাবলিক ফোল্ডার URL Settings-এ দিলে **Image Sitemap** বানানো যাবে → Google Search Console-এ সাবমিট করুন

> Bulk-এ: ছবি সিলেক্ট করে **AI দিয়ে SEO লিখুন → Process (সব) → ZIP**।

## সীমাবদ্ধতা (সততার সাথে)

- **AVIF** সাপোর্ট নেই (সাধারণ cPanel GD-তে থাকে না); WebP/JPEG/PNG আছে।
- মেটাডেটা ইঞ্জিন Pure-PHP — কোনো `exiftool` লাগে না। শুধু JPEG, PNG, WebP-তে লেখে। GIF-এ মেটাডেটা বসে না।
- Google র‍্যাংক নিশ্চিত করা কোনো টুলের পক্ষে সম্ভব নয়। এই টুল সবকিছু **সঠিকভাবে অপটিমাইজ** করে — র‍্যাংক কনটেন্টের মান, লিংক ও প্রতিযোগিতার উপরও নির্ভর করে।
- AI-র দেওয়া তথ্য প্রকাশের আগে একবার চোখ বুলিয়ে নিন (ভুল সনাক্তকরণ হতে পারে)।
- ৫০ মেগাপিক্সেলের বেশি ছবি নেওয়া হয় না। সার্ভারের `memory_limit` কম হলে (যেমন ১২৮MB-তে ~১৫ মেগাপিক্সেলের বেশি) টুল ক্র্যাশ না করে পরিষ্কার বার্তা দেয় — তখন ছবি ছোট করে আপলোড করুন বা `memory_limit` বাড়ান।
- অ্যানিমেটেড GIF অপটিমাইজ করলে শুধু প্রথম ফ্রেম থাকে।
- CSV/Excel-এ `=`, `+`, `-`, `@` দিয়ে শুরু হওয়া লেখার আগে স্বয়ংক্রিয়ভাবে `'` বসে (Excel ফর্মুলা ইনজেকশন থেকে সুরক্ষা)।

## নিরাপত্তা

- লগইন (password hash), CSRF সুরক্ষা, ৫ বার ভুলে ১ মিনিট লক
- `storage/`, `lib/`, `config.php` ওয়েব থেকে সরাসরি খোলা যায় না (`.htaccess`) — LiteSpeed/Apache-এ কাজ করে
- ছবির ফাইল শুধু লগইন করা ইউজার দেখতে পারে

## ফোল্ডার

```
index.php  login.php  install.php  api.php
assets/    app.css  app.js
lib/       core.php  image.php  meta.php  ai.php  install.php
storage/   uploads/ output/ thumbs/ tmp/   (অটো তৈরি, ব্যাকআপ রাখুন)
```
