<?php
/**
 * AI layer: Gemini, Claude (Anthropic), OpenAI, OpenRouter — with vision, JSON mode,
 * automatic fallback to the next configured provider, and server-side length enforcement.
 */

function ai_providers()
{
    return array(
        'gemini' => array('label' => 'Google Gemini', 'model' => 'gemini-2.5-flash'),
        'claude' => array('label' => 'Anthropic Claude', 'model' => 'claude-sonnet-5-5'),
        'openai' => array('label' => 'OpenAI', 'model' => 'gpt-4o-mini'),
        'openrouter' => array('label' => 'OpenRouter', 'model' => 'google/gemini-2.5-flash'),
    );
}

function ai_key($p)
{
    return dec(setting('key_' . $p));
}

function ai_model($p)
{
    $p_ = ai_providers();
    $m = trim((string)setting('model_' . $p));
    return $m !== '' ? $m : $p_[$p]['model'];
}

/** Providers that have a key, primary first. */
function ai_chain($force = null)
{
    $all = array_keys(ai_providers());
    if ($force && in_array($force, $all, true)) {
        return ai_key($force) !== '' ? array($force) : array();
    }
    $primary = setting('ai_primary');
    $order = array();
    if (in_array($primary, $all, true)) {
        $order[] = $primary;
    }
    foreach ($all as $p) {
        if (!in_array($p, $order, true)) {
            $order[] = $p;
        }
    }
    $out = array();
    foreach ($order as $p) {
        if (ai_key($p) !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

if (!function_exists('http_json')) {
function http_json($url, array $headers, $body = null, $timeout = 90, $method = 'POST')
{
    $ch = curl_init($url);
    $h = array_merge(array('Content-Type: application/json', 'Accept: application/json'), $headers);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Network error: ' . $err);
    }
    $j = json_decode($raw, true);
    if ($code >= 400 || $j === null) {
        $msg = '';
        if (is_array($j)) {
            if (isset($j['error']['message'])) {
                $msg = $j['error']['message'];
            } elseif (isset($j['error']) && is_string($j['error'])) {
                $msg = $j['error'];
            } elseif (isset($j[0]['error']['message'])) {
                $msg = $j[0]['error']['message'];
            }
        }
        if ($msg === '') {
            $msg = substr(trim(strip_tags((string)$raw)), 0, 300);
        }
        throw new RuntimeException('HTTP ' . $code . ': ' . $msg);
    }
    return $j;
}
} // function_exists('http_json') — lets tests inject a mock transport

/** One call to one provider. $image = array('mime'=>..,'b64'=>..) or null. Returns text. */
function ai_call($provider, $system, $user, $image = null)
{
    $key = ai_key($provider);
    $model = ai_model($provider);
    if ($key === '') {
        throw new RuntimeException(ai_providers()[$provider]['label'] . ': API key not set');
    }

    if ($provider === 'gemini') {
        $parts = array(array('text' => $user));
        if ($image) {
            $parts[] = array('inline_data' => array('mime_type' => $image['mime'], 'data' => $image['b64']));
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $j = http_json($url, array('x-goog-api-key: ' . $key), array(
            'systemInstruction' => array('parts' => array(array('text' => $system))),
            'contents' => array(array('role' => 'user', 'parts' => $parts)),
            'generationConfig' => array('responseMimeType' => 'application/json', 'temperature' => 0.7),
        ));
        $t = '';
        if (isset($j['candidates'][0]['content']['parts'])) {
            foreach ($j['candidates'][0]['content']['parts'] as $p) {
                if (isset($p['text']) && empty($p['thought'])) {
                    $t .= $p['text'];
                }
            }
        }
        if ($t === '') {
            $why = isset($j['promptFeedback']['blockReason']) ? $j['promptFeedback']['blockReason'] : (isset($j['candidates'][0]['finishReason']) ? $j['candidates'][0]['finishReason'] : 'empty');
            throw new RuntimeException('Gemini returned no text (' . $why . ')');
        }
        return $t;
    }

    if ($provider === 'claude') {
        $content = array();
        if ($image) {
            $content[] = array('type' => 'image', 'source' => array('type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['b64']));
        }
        $content[] = array('type' => 'text', 'text' => $user);
        $j = http_json('https://api.anthropic.com/v1/messages', array('x-api-key: ' . $key, 'anthropic-version: 2023-06-01'), array(
            'model' => $model,
            'max_tokens' => 3000,
            'system' => $system,
            'messages' => array(array('role' => 'user', 'content' => $content)),
        ));
        $t = '';
        if (isset($j['content']) && is_array($j['content'])) {
            foreach ($j['content'] as $c) {
                if (isset($c['type']) && $c['type'] === 'text') {
                    $t .= $c['text'];
                }
            }
        }
        if ($t === '') {
            throw new RuntimeException('Claude returned no text');
        }
        return $t;
    }

    // OpenAI-compatible (openai, openrouter)
    $content = array(array('type' => 'text', 'text' => $user));
    if ($image) {
        $content[] = array('type' => 'image_url', 'image_url' => array('url' => 'data:' . $image['mime'] . ';base64,' . $image['b64']));
    }
    $body = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $content),
        ),
    );
    if ($provider === 'openai') {
        $body['response_format'] = array('type' => 'json_object');
        $url = 'https://api.openai.com/v1/chat/completions';
        $hdr = array('Authorization: Bearer ' . $key);
    } else {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $hdr = array('Authorization: Bearer ' . $key, 'X-Title: SEO Studio');
        $site = setting('site_url');
        if ($site !== '') {
            $hdr[] = 'HTTP-Referer: ' . $site;
        }
    }
    $j = http_json($url, $hdr, $body);
    $t = isset($j['choices'][0]['message']['content']) ? $j['choices'][0]['message']['content'] : '';
    if (is_array($t)) {
        $s = '';
        foreach ($t as $part) {
            if (isset($part['text'])) {
                $s .= $part['text'];
            }
        }
        $t = $s;
    }
    if (trim((string)$t) === '') {
        throw new RuntimeException(ai_providers()[$provider]['label'] . ' returned no text');
    }
    return $t;
}

function ai_parse_json($text)
{
    $t = trim($text);
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
    $a = strpos($t, '{');
    $b = strrpos($t, '}');
    if ($a === false || $b === false || $b <= $a) {
        throw new RuntimeException('AI did not return JSON');
    }
    $t = substr($t, $a, $b - $a + 1);
    $j = json_decode($t, true);
    if (!is_array($j)) {
        $j = json_decode(preg_replace('/[\x00-\x1F]+/', ' ', $t), true);
    }
    if (!is_array($j)) {
        throw new RuntimeException('AI returned malformed JSON');
    }
    return $j;
}

/** Try providers in order until one returns valid JSON. Returns array(json, provider). */
function ai_json($system, $user, $image = null, $force = null)
{
    $chain = ai_chain($force);
    if (!$chain) {
        throw new RuntimeException('কোনো AI API key সেট করা নেই — Settings থেকে Gemini / Claude / OpenAI / OpenRouter এর key দিন।');
    }
    $errors = array();
    foreach ($chain as $p) {
        try {
            $txt = ai_call($p, $system, $user, $image);
            return array(ai_parse_json($txt), $p);
        } catch (Exception $e) {
            $errors[] = ai_providers()[$p]['label'] . ' → ' . $e->getMessage();
        }
    }
    throw new RuntimeException(implode(' | ', $errors));
}

function lang_name($l)
{
    return $l === 'bn' ? 'Bengali (বাংলা)' : 'English';
}

/* ---------------- Length enforcement ---------------- */

/**
 * Make sure every field in $r obeys its max length. First asks the AI to shorten, then hard-trims at a word boundary.
 * $limits: field => max chars. Returns the fixed array.
 */
function ai_enforce(array $r, array $limits, $lang, $provider = null)
{
    $over = array();
    foreach ($limits as $k => $max) {
        if (isset($r[$k]) && is_string($r[$k]) && mb_strlen($r[$k]) > $max) {
            $over[$k] = array('text' => $r[$k], 'max_chars' => $max);
        }
    }
    if ($over) {
        try {
            list($fix) = ai_json(
                'You shorten SEO text without losing the focus keyword or meaning. Output ONLY JSON.',
                "Language: " . lang_name($lang) . "\nShorten each text so its character count is AT MOST max_chars (count characters, not words; aim for 90% of max). Keep the same language, keep the main keyword. Return JSON with the same keys and the shortened strings as values.\n" . json_encode($over, JSON_UNESCAPED_UNICODE),
                null,
                $provider
            );
            foreach ($over as $k => $_) {
                if (isset($fix[$k]) && is_string($fix[$k]) && trim($fix[$k]) !== '') {
                    $r[$k] = trim($fix[$k]);
                }
            }
        } catch (Exception $e) {
            // fall through to hard trim
        }
    }
    foreach ($limits as $k => $max) {
        if (isset($r[$k]) && is_string($r[$k])) {
            $r[$k] = mb_strlen($r[$k]) > $max ? fit_text($r[$k], $max) : trim($r[$k]);
        }
    }
    return $r;
}

function ai_clean_keywords($kw, $max = 15)
{
    if (is_string($kw)) {
        $kw = preg_split('/[,\n،;]+/u', $kw);
    }
    $out = array();
    foreach ((array)$kw as $k) {
        $k = trim(preg_replace('/\s+/u', ' ', (string)$k), " \t#\"'");
        if ($k === '' || mb_strlen($k) > 60) {
            continue;
        }
        $dupe = false;
        foreach ($out as $o) {
            if (mb_strtolower($o) === mb_strtolower($k)) {
                $dupe = true;
            }
        }
        if (!$dupe) {
            $out[] = $k;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/* ---------------- Prompts ---------------- */

function prompt_image_seo(array $o)
{
    $sys = 'You are a senior SEO specialist focused on image SEO (Google Images, Google Lens, Bing Images). '
        . 'Return ONLY a single valid JSON object — no markdown, no commentary. '
        . 'Describe only what is actually visible in the image or stated in the supplied context. Never invent brands, places, prices or people.';
    $ctx = array(
        'language_for_text_fields' => lang_name($o['lang']),
        'focus_keyword' => $o['focus'] !== '' ? $o['focus'] : '(none given — infer the best primary keyword from the image)',
        'site_or_brand' => $o['site'] !== '' ? $o['site'] : '(none)',
        'page_or_product_context' => $o['context'] !== '' ? $o['context'] : '(none)',
        'original_filename' => $o['orig'],
    );
    $usr = "Create image SEO data for the attached image.\n\nINPUT:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n\n"
        . "RULES:\n"
        . "- title: max 55 characters (hard limit 60). Compelling, focus keyword near the start. No clickbait, no ALL CAPS.\n"
        . "- alt_text: 70-125 characters. Literal, specific description of what is visible plus useful context, using the focus keyword once naturally. Never start with 'image of', 'picture of' or 'photo of'. No emojis, no keyword stuffing.\n"
        . "- caption: one sentence, max 150 characters.\n"
        . "- description: 150-300 characters, two short sentences, includes the focus keyword and related terms.\n"
        . "- keywords: 8-12 tags: primary keyword, long-tail variations, related topics. No duplicates, no hashtags.\n"
        . "- filename: ENGLISH ONLY, lowercase ASCII, words separated by hyphens, 3-6 meaningful words, include the focus keyword (translate or transliterate it to English if needed), NO file extension, no stop-words (a, the, of), no dates, no 'img'/'dsc'.\n"
        . "- All text fields (title, alt_text, caption, description, keywords) must be written in " . lang_name($o['lang']) . ".\n"
        . "- Count characters carefully; if unsure, be shorter.\n\n"
        . 'OUTPUT JSON SHAPE: {"title":"","alt_text":"","caption":"","description":"","keywords":[""],"filename":""}';
    return array($sys, $usr);
}

function image_seo_limits()
{
    return array('title' => 60, 'alt_text' => 125, 'caption' => 160, 'description' => 320);
}

function prompt_meta(array $o)
{
    $sys = 'You are a senior technical SEO and conversion copywriter. Return ONLY a single valid JSON object, no markdown. '
        . 'Never invent facts, prices, awards or statistics that were not supplied.';
    $ctx = array(
        'page_type' => $o['type'], 'topic_or_page_title' => $o['topic'], 'page_summary_or_content' => $o['summary'],
        'focus_keyword' => $o['focus'], 'secondary_keywords' => $o['secondary'], 'brand' => $o['brand'],
        'url' => $o['url'], 'language' => lang_name($o['lang']), 'tone' => $o['tone'], 'audience_or_location' => $o['audience'],
        'extra_instructions' => $o['extra'],
    );
    $usr = "Write on-page SEO metadata for this page.\n\nINPUT:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n\n"
        . "RULES:\n"
        . "- meta_title: max 58 characters. Focus keyword at the start when natural. Add the brand at the end with ' | ' only if it fits within the limit. Unique, matches search intent.\n"
        . "- meta_description: 130-155 characters. Includes the focus keyword once, states a concrete benefit, ends with a soft call to action. No quotes, no truncation dots.\n"
        . "- titles: 3 different meta_title options (angles: benefit-led, question/curiosity, number/list or how-to). titles[0] must equal meta_title.\n"
        . "- descriptions: 3 different meta_description options. descriptions[0] must equal meta_description.\n"
        . "- keywords: 8-12 relevant tags/keywords (primary, long-tail, related). No duplicates.\n"
        . "- og_title: max 60. og_description: max 150. twitter_title: max 60. twitter_description: max 150.\n"
        . "- slug: ENGLISH lowercase ASCII, hyphen-separated, 3-6 words. h1: max 70 characters, different wording from meta_title.\n"
        . "- schema_type: exactly one of WebPage, Article, Product, Service, LocalBusiness, Organization, FAQPage, CollectionPage, ContactPage, AboutPage.\n"
        . "- Everything except slug in " . lang_name($o['lang']) . ". Count characters carefully; if unsure, be shorter.\n\n"
        . 'OUTPUT JSON SHAPE: {"meta_title":"","meta_description":"","titles":["","",""],"descriptions":["","",""],"keywords":[""],"og_title":"","og_description":"","twitter_title":"","twitter_description":"","slug":"","h1":"","schema_type":""}';
    return array($sys, $usr);
}

function optimize_limits($type)
{
    $m = array('title' => 58, 'meta_description' => 155, 'product_title' => 80, 'product_description' => 1500);
    return isset($m[$type]) ? $m[$type] : 58;
}

function prompt_optimize(array $o)
{
    $max = optimize_limits($o['type']);
    $rules = array(
        'title' => "SEO page title (shown in Google results). Max $max characters, ideal 50-58. Focus keyword at the start when natural, one clear benefit or hook, brand at the end only if it fits.",
        'meta_description' => "Meta description. 130-$max characters, includes the focus keyword once, concrete benefit, soft call to action. No truncation dots.",
        'product_title' => "E-commerce product title. Max $max characters. Format: main keyword/product type + brand/model + key attribute (size, colour, material). No promotional spam, no ALL CAPS.",
        'product_description' => "E-commerce product description, max $max characters. Start with a strong keyword-rich first sentence, then short benefit-led sentences. Keep every fact from the original; add no invented specifications. Plain text, no HTML.",
    );
    $sys = 'You are a senior SEO copywriter. Return ONLY a single valid JSON object, no markdown. Preserve all factual details from the original text; never invent specs, prices or claims.';
    $ctx = array(
        'content_type' => $o['type'], 'original_text' => $o['text'], 'focus_keyword' => $o['focus'],
        'brand' => $o['brand'], 'language_of_output' => lang_name($o['lang']),
    );
    $usr = "Rewrite the original text into 3 SEO-optimized versions.\n\nINPUT:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n\n"
        . "TARGET: " . $rules[$o['type']] . "\n"
        . "- Each of the 3 versions must use a different angle but stay truthful to the original.\n"
        . "- 'why' = one short sentence in Bengali (বাংলা) saying what SEO improvement you made.\n"
        . "- Count characters carefully and stay within the limit; if unsure, be shorter.\n\n"
        . 'OUTPUT JSON SHAPE: {"variants":[{"text":"","why":""},{"text":"","why":""},{"text":"","why":""}]}';
    return array($sys, $usr);
}
