<?php
/**
 * Plugin Name: KAU Site
 * Description: KAU 網站的「資料庫版」內容管理。HTML 存資料庫，點頁面就能視覺化編輯、存檔即生效。不需要再上傳 zip。
 * Version: 2.3.19
 * Author: KAU
 */

if (!defined('ABSPATH')) exit;

const KAU_SITE_OPT = 'kau_site_pages_v2';
const KAU_SITE_DATA_OPT = 'kau_site_data_v2';
const KAU_SITE_VERSION = '2.3.19';

function kau_site_pages_map(): array {
    return [
        ''         => ['key' => 'home',     'title' => 'ホーム'],
        'home'     => ['key' => 'home',     'title' => 'ホーム'],
        'about'    => ['key' => 'about',    'title' => '会社概要'],
        'products' => ['key' => 'products', 'title' => '製品情報'],
        'news'     => ['key' => 'news',     'title' => '最新情報'],
    ];
}

function kau_site_can_edit(): bool {
    return is_user_logged_in() && current_user_can('edit_theme_options');
}

function kau_site_asset_base(): string {
    $own_assets = plugin_dir_path(__FILE__) . 'assets';
    $own_cms = plugin_dir_path(__FILE__) . 'cms-content.js';
    if (is_dir($own_assets) || is_file($own_cms)) {
        return plugin_dir_url(__FILE__);
    }
    // 萬一缺檔，找舊外掛當 fallback
    foreach ((array) glob(WP_PLUGIN_DIR . '/kau-original-site-editor*/static/assets', GLOB_ONLYDIR) as $dir) {
        $plugin_slug = basename(dirname(dirname($dir)));
        return content_url('plugins/' . $plugin_slug . '/static/');
    }
    return '';
}

// 逐檔 fallback：URL 指到的資料夾若沒有該檔案，改指向實際有檔案的 kau-site-lite* 兄弟資料夾。
// 背景：WordPress.com 上傳外掛常建立新資料夾（-1/-3 後綴），後續用 /asset endpoint 推的檔案
// 只存在當時 active 的資料夾裡，切換資料夾後就 404（例如 hashed 動畫引擎 JS）。
function kau_site_asset_fallback_url(string $slug, string $rel): string {
    static $cache = [];
    $key = $slug . '|' . $rel;
    if (isset($cache[$key])) return $cache[$key];
    $url = content_url('plugins/' . $slug . '/' . $rel);
    if (!is_file(WP_PLUGIN_DIR . '/' . $slug . '/' . $rel)) {
        foreach ((array) glob(WP_PLUGIN_DIR . '/kau-site-lite*', GLOB_ONLYDIR) as $dir) {
            if (is_file($dir . '/' . $rel)) {
                $url = content_url('plugins/' . basename($dir) . '/' . $rel);
                break;
            }
        }
    }
    return $cache[$key] = $url;
}

// 自我修復：把兄弟 kau-site-lite* 資料夾裡有、自己沒有的 asset 檔案複製過來，
// 讓 active 資料夾自足（之後就算刪掉舊資料夾也不會破圖破 JS）。每個版本跑一次。
function kau_site_self_heal_assets(): void {
    if (get_option('kau_site_assets_healed') === KAU_SITE_VERSION) return;
    $own_dir = plugin_dir_path(__FILE__);
    $own_assets = $own_dir . 'assets/';
    if (!is_dir($own_assets)) wp_mkdir_p($own_assets);
    $own_real = realpath($own_dir);
    foreach ((array) glob(WP_PLUGIN_DIR . '/kau-site-lite*', GLOB_ONLYDIR) as $dir) {
        if (realpath($dir) === $own_real) continue;
        foreach ((array) glob($dir . '/assets/*') as $f) {
            if (!is_file($f)) continue;
            $dest = $own_assets . basename($f);
            if (!is_file($dest)) @copy($f, $dest);
        }
        $cms = $dir . '/cms-content.js';
        if (is_file($cms) && !is_file($own_dir . 'cms-content.js')) @copy($cms, $own_dir . 'cms-content.js');
    }
    update_option('kau_site_assets_healed', KAU_SITE_VERSION, false);
}
add_action('admin_init', 'kau_site_self_heal_assets');
register_activation_hook(__FILE__, function() {
    delete_option('kau_site_assets_healed');
    kau_site_self_heal_assets();
});

// media/ 商品圖：優先用 WordPress 媒體庫（使用者已全部上傳），媒體庫沒有才退回 GitHub Pages。
// WP 上傳重名檔會自動加 -1/-2 後綴，所以也嘗試這些變體（例：商品_21.png → 商品_21-1.png）。
// favicon 檔在自己的外掛資料夾裡。舊版寫死 kau-site-lite/，資料夾一漂移就 404
//（而且它是在 rewrite_asset_paths 之後才注入的，逐檔 fallback 救不到）。
function kau_site_favicon_url(): string {
    $rel = 'assets/053b8cb4-734b-4eab-8c12-07be625736fb.svg';
    $base = kau_site_asset_base();
    if ($base !== '' && is_file(plugin_dir_path(__FILE__) . $rel)) return $base . $rel;
    return kau_site_asset_fallback_url(basename(rtrim(plugin_dir_path(__FILE__), '/\\')), $rel);
}

function kau_site_media_library_url(string $file): string {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $decoded = rawurldecode($file);
    $up = wp_upload_dir();
    $dot = strrpos($decoded, '.');
    $stem = $dot === false ? $decoded : substr($decoded, 0, $dot);
    $ext  = $dot === false ? '' : substr($decoded, $dot);
    foreach ([$decoded, $stem . '-1' . $ext, $stem . '-2' . $ext] as $cand) {
        foreach ((array) glob($up['basedir'] . '/*/*/' . $cand) as $hit) {
            if (!is_file($hit)) continue;
            $rel = ltrim(str_replace('\\', '/', substr($hit, strlen($up['basedir']))), '/');
            $enc = implode('/', array_map('rawurlencode', explode('/', $rel)));
            return $cache[$file] = $up['baseurl'] . '/' . $enc;
        }
    }
    return $cache[$file] = 'https://kau-jp.github.io/media/' . $file;
}

function kau_site_get_pages(): array {
    $pages = get_option(KAU_SITE_OPT, []);
    return is_array($pages) ? $pages : [];
}

function kau_site_get_page(string $key): array {
    $pages = kau_site_get_pages();
    return isset($pages[$key]) && is_array($pages[$key]) ? $pages[$key] : ['html' => '', 'updated' => 0];
}

function kau_site_save_page(string $key, string $html): void {
    // 存檔前先過一次垃圾清理，避免編輯器沒清乾淨的 WP/瀏覽器注入物累積在 DB
    if (function_exists('kau_site_strip_wp_pollution')) {
        $html = kau_site_strip_wp_pollution($html);
    }
    $pages = kau_site_get_pages();
    $pages[$key] = ['html' => $html, 'updated' => time()];
    update_option(KAU_SITE_OPT, $pages, false);
}

// ─── 初次匯入：從現有外掛資料夾把 HTML 抓進資料庫 ────────────────────────────

function kau_site_import_from_files(bool $force = false): array {
    $report = [];
    $existing = kau_site_get_pages();
    foreach (kau_site_pages_map() as $slug => $info) {
        $key = $info['key'];
        if (!$force && !empty($existing[$key]['html'])) {
            $report[$key] = 'skip (已存在)';
            continue;
        }
        // 從所有 sibling 資料夾找該檔案
        $found = '';
        foreach ((array) glob(WP_PLUGIN_DIR . '/kau-original-site-editor*/static/' . $key . '.html') as $file) {
            $content = (string) @file_get_contents($file);
            if (strlen($content) > 5000) {
                $found = $content;
                break;
            }
        }
        if ($found === '') {
            $report[$key] = 'no source';
            continue;
        }
        kau_site_save_page($key, $found);
        $report[$key] = 'imported (' . number_format(strlen($found)) . ' bytes)';
    }
    return $report;
}

register_activation_hook(__FILE__, function() {
    kau_site_import_from_files(false);
});

// ─── 前端：依網址供應 HTML ─────────────────────────────────────────────────

function kau_site_current_slug(): ?string {
    // WP 內建 query（指定文章/頁面/分類等）優先讓 WP 處理，不被我們的靜態頁攔截
    foreach (['p', 'page_id', 'post_type', 'name', 'cat', 'category_name', 'tag', 'author', 's', 'feed', 'attachment_id', 'preview', 'preview_id'] as $q) {
        if (isset($_GET[$q]) && $_GET[$q] !== '') return null;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $raw = trim((string) $path, '/');
    if ($raw !== '' && strpos($raw, '/') !== false) return null;
    $slug = $raw === '' ? '' : basename((string) preg_replace('/\.html$/', '', $raw));
    return isset(kau_site_pages_map()[$slug]) ? $slug : null;
}

function kau_site_serve(): void {
    if (is_admin()) return;
    $slug = kau_site_current_slug();
    if ($slug === null) return;
    $info = kau_site_pages_map()[$slug];
    $page = kau_site_get_page($info['key']);
    if (empty($page['html'])) return; // 讓主外掛或 404 接手

    $html = $page['html'];
    // 輸出前即時清理：DB 內既有的累積垃圾（重複 stats script、瀏覽器擴充 CSS、壞連結）
    // 在下次存檔把 DB 洗乾淨之前，訪客也能先拿到乾淨的 HTML
    $html = kau_site_strip_wp_pollution($html);
    $html = kau_site_rewrite_asset_paths($html);

    $edit_mode = kau_site_can_edit() && isset($_GET['kau_edit']) && $_GET['kau_edit'] === '1';
    $html = kau_site_inject_runtime($html, $info['key'], $edit_mode);

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
add_action('template_redirect', 'kau_site_serve', 0);

// 防止其他外掛/WP 把我們的 slug 判定為 404
add_filter('pre_handle_404', function($preempt, $wp_query) {
    $slug = kau_site_current_slug();
    if ($slug === null) return $preempt;
    $info = kau_site_pages_map()[$slug];
    $page = kau_site_get_page($info['key']);
    if (!empty($page['html'])) {
        if ($wp_query) $wp_query->is_404 = false;
        return true;
    }
    return $preempt;
}, 10, 2);

// 在 parse_request 階段就清掉可能的 404 query
add_action('parse_request', function($wp) {
    $slug = kau_site_current_slug();
    if ($slug === null) return;
    $info = kau_site_pages_map()[$slug];
    $page = kau_site_get_page($info['key']);
    if (!empty($page['html'])) {
        $wp->query_vars = [];
        $wp->matched_rule = null;
    }
});

function kau_site_rewrite_asset_paths(string $html): string {
    $base = kau_site_asset_base();
    if ($base === '') return $html;
    $media = 'https://kau-jp.github.io/';
    $pairs = [
        'href="assets/'  => 'href="' . $base . 'assets/',
        "href='assets/"  => "href='" . $base . "assets/",
        'href="/assets/' => 'href="' . $base . 'assets/',
        'src="assets/'   => 'src="' . $base . 'assets/',
        "src='assets/"   => "src='" . $base . "assets/",
        'src="/assets/'  => 'src="' . $base . 'assets/',
        'url("assets/'   => 'url("' . $base . 'assets/',
        "url('assets/"   => "url('" . $base . "assets/",
        'url("/assets/'  => 'url("' . $base . 'assets/',
        'src="cms-content.js"' => 'src="' . $base . 'cms-content.js?v=' . filemtime(plugin_dir_path(__FILE__) . 'cms-content.js') . '"',
        'src="assets/cms-content.js"' => 'src="' . $base . 'assets/cms-content.js?v=' . filemtime(plugin_dir_path(__FILE__) . 'cms-content.js') . '"',
        'src="/media/'   => 'src="' . $media . 'media/',
        "src='/media/"   => "src='" . $media . "media/",
        'src="media/'    => 'src="' . $media . 'media/',
    ];
    $html = strtr($html, $pairs);
    $cms_ver = (string) filemtime(plugin_dir_path(__FILE__) . 'cms-content.js');
    // 強制改寫任何 cms-content.js 的 src（不管是絕對 URL 還是相對路徑）為當前 active 插件位置 + cache-bust
    // 這修復了 DB 內存有舊插件資料夾名（kau-site-lite、kau-site-lite-2 等）時 404 的問題
    $cms_url = $base . 'cms-content.js?v=' . $cms_ver;
    $html = preg_replace(
        '#src=(["\'])[^"\']*\bcms-content\.js(?:\?[^"\']*)?\1#i',
        'src=$1' . $cms_url . '$1',
        $html
    );
    // 逐檔 fallback：上面改寫出來的（或 DB 裡烤死的）kau-site-lite* asset URL，
    // 若該資料夾實際沒有這個檔案，改指向真的有檔案的兄弟資料夾（防 404）
    $html = preg_replace_callback(
        '#(?:https?://[^"\'\s>]*)?/wp-content/plugins/(kau-site-lite[^/"\'\s>]*)/((?:assets/)?[^"\'\s>?\#]+\.(?:js|css|svg|woff2?|ttf|png|jpe?g|webp|gif|ico))#i',
        function($m) { return kau_site_asset_fallback_url($m[1], $m[2]); },
        $html
    );
    // media/ 商品圖改指向 WordPress 媒體庫（找不到同名檔才維持 GitHub Pages）
    $html = preg_replace_callback(
        '#https://kau-jp\.github\.io/media/([^"\'\s>)]+)#u',
        function($m) { return kau_site_media_library_url($m[1]); },
        $html
    );
    return $html;
}

function kau_site_safe_origin(): string {
    // 完全不依賴 WP 初始化，從 $_SERVER 直接組
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host;
}

// ─── 單篇 WP 文章用 KAU 品牌外觀渲染 ────────────────────────────────────────
add_action('template_redirect', function() {
    if (is_admin()) return;
    if (!is_singular('post')) return;
    $post = get_queried_object();
    if (!($post instanceof WP_Post)) return;
    $shell = kau_site_render_post_shell($post);
    if ($shell === null) return; // 回退讓 WP 預設主題接手
    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo $shell;
    exit;
}, 1);

function kau_site_render_post_shell(WP_Post $post): ?string {
    // 拿 home 頁面當外殼模板
    $page = kau_site_get_page('home');
    if (empty($page['html'])) return null;
    $home = kau_site_rewrite_asset_paths((string) $page['html']);

    // 切出 head→nav 結尾 / footer 開頭→結尾
    if (!preg_match('#^(.+?</nav>)#is', $home, $top_m)) return null;
    if (!preg_match('#(<footer\b[\s\S]+)$#i', $home, $btm_m)) return null;
    $top = $top_m[1];
    $bottom = $btm_m[1];

    // 文章頁是白色背景，要用普通 nav（about/products/news 用的版本），不能用 home 的 on-dark 白字
    $top = preg_replace('#<nav class="nav on-dark"#i', '<nav class="nav"', $top, 1);

    // 文章 URL 是深層路徑 (/2026/06/22/.../) → 相對連結會 404
    // 把 home/about/products/news.html 通通改成絕對 /xxx
    $link_pairs = [
        'href="home.html'     => 'href="/home.html',
        "href='home.html"     => "href='/home.html",
        'href="about.html'    => 'href="/about.html',
        "href='about.html"    => "href='/about.html",
        'href="products.html' => 'href="/products.html',
        "href='products.html" => "href='/products.html",
        'href="news.html'     => 'href="/news.html',
        "href='news.html"     => "href='/news.html",
    ];
    $top    = strtr($top,    $link_pairs);
    $bottom = strtr($bottom, $link_pairs);

    // 文章內容
    $title   = esc_html(get_the_title($post));
    $date    = esc_html(get_the_date('Y.m.d', $post));
    $cats    = get_the_category($post->ID);
    $catname = $cats ? esc_html($cats[0]->name) : '';
    setup_postdata($post);
    $content = apply_filters('the_content', $post->post_content);
    wp_reset_postdata();
    $excerpt = wp_strip_all_tags(get_the_excerpt($post) ?: wp_trim_words($post->post_content, 40));
    $thumb   = get_the_post_thumbnail_url($post, 'large');

    $cat_html = $catname !== '' ? '<span>·</span><span>' . $catname . '</span>' : '';
    $article = '<main class="kau-post-main" style="padding:140px 6vw 80px;max-width:880px;margin:0 auto;color:var(--ink,#1a1a2e);font-family:var(--sans,system-ui,-apple-system,sans-serif)">'
        . '<a href="/news.html" style="display:inline-block;margin-bottom:24px;color:var(--grey,#64708e);font-size:12px;letter-spacing:.1em;text-decoration:none">← 最新情報</a>'
        . '<header style="margin-bottom:40px;padding-bottom:24px;border-bottom:1px solid var(--line,#e2ddd7)">'
        . '<div style="display:flex;gap:12px;align-items:center;font:600 11px/1 var(--latin,monospace);letter-spacing:.16em;color:var(--gold,#d4a574);text-transform:uppercase;margin-bottom:18px">'
        . '<span>' . $date . '</span>' . $cat_html
        . '</div>'
        . '<h1 style="font:500 clamp(28px,3.4vw,44px)/1.45 var(--serif,\'Noto Serif JP\',serif);letter-spacing:.01em;color:var(--ink,#1a1a2e);margin:0">' . $title . '</h1>'
        . '</header>'
        . '<article class="kau-post-body" style="font-size:16px;line-height:2;color:var(--ink,#1a1a2e)">' . $content . '</article>'
        . '<div style="margin-top:80px;padding-top:30px;border-top:1px solid var(--line,#e2ddd7);text-align:center"><a href="/news.html" style="display:inline-block;padding:14px 32px;border:1px solid var(--ink,#1a1a2e);color:var(--ink,#1a1a2e);font:600 13px/1 var(--sans,system-ui);letter-spacing:.1em;text-decoration:none">最新情報一覧へ</a></div>'
        . '</main>'
        . '<style>'
        . '.kau-post-body h2{font:500 clamp(22px,2.4vw,30px)/1.5 var(--serif,serif);margin:48px 0 16px;color:var(--ink,#1a1a2e)}'
        . '.kau-post-body h3{font:600 17px/1.6 var(--sans,system-ui);margin:32px 0 10px;color:var(--ink,#1a1a2e)}'
        . '.kau-post-body p{margin:0 0 1.4em}'
        . '.kau-post-body a{color:var(--gold,#d4a574);text-decoration:underline;text-underline-offset:3px}'
        . '.kau-post-body ul,.kau-post-body ol{margin:0 0 1.4em 1.2em;padding-left:1em}'
        . '.kau-post-body li{margin-bottom:.4em}'
        . '.kau-post-body img{max-width:100%;height:auto;margin:24px 0;border-radius:4px}'
        . '.kau-post-body blockquote{border-left:3px solid var(--gold,#d4a574);padding:6px 0 6px 20px;margin:24px 0;color:var(--grey,#64708e);font-style:italic}'
        . '</style>';

    // SEO 注入
    $origin = kau_site_safe_origin();
    $url = esc_url(get_permalink($post));
    $hdesc = esc_attr(mb_substr(trim(preg_replace('/\s+/u', ' ', $excerpt)), 0, 160));
    $himg  = esc_url($thumb ?: kau_site_favicon_url());
    $iso_date = get_the_date('c', $post);
    $brand_name = (string) (kau_site_get_data()['global']['company_name'] ?? 'KAU');

    $seo  = '<script>window.KAU_CONTENT_URL=' . wp_json_encode($origin . '/wp-json/kau-site/v1/data?v=' . kau_site_get_data_updated()) . ';</script>';
    $favicon_url = kau_site_favicon_url();
    $seo .= '<link rel="icon" type="image/svg+xml" href="' . esc_url($favicon_url) . '">';
    $seo .= '<link rel="apple-touch-icon" href="' . esc_url($favicon_url) . '">';
    $seo .= '<meta name="description" content="' . $hdesc . '">';
    $seo .= '<link rel="canonical" href="' . $url . '">';
    $seo .= '<meta property="og:type" content="article">';
    $seo .= '<meta property="og:title" content="' . esc_attr(get_the_title($post)) . '">';
    $seo .= '<meta property="og:description" content="' . $hdesc . '">';
    $seo .= '<meta property="og:url" content="' . $url . '">';
    $seo .= '<meta property="og:image" content="' . $himg . '">';
    $seo .= '<meta property="article:published_time" content="' . esc_attr($iso_date) . '">';
    $seo .= '<meta name="twitter:card" content="summary_large_image">';
    $seo .= '<meta name="robots" content="index,follow,max-image-preview:large">';

    $jsonld = [
        '@context' => 'https://schema.org',
        '@type'    => 'Article',
        'headline' => get_the_title($post),
        'datePublished' => $iso_date,
        'dateModified'  => get_the_modified_date('c', $post),
        'description'   => mb_substr(trim(preg_replace('/\s+/u', ' ', $excerpt)), 0, 160),
        'image'     => $thumb ?: '',
        'mainEntityOfPage' => $url,
        'author'    => ['@type' => 'Organization', 'name' => $brand_name],
        'publisher' => ['@type' => 'Organization', 'name' => $brand_name, 'logo' => ['@type' => 'ImageObject', 'url' => kau_site_favicon_url()]],
    ];
    $seo .= '<script type="application/ld+json">' . wp_json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

    // 套上：注入 head + 換 title + 拼接
    $top = preg_replace('#<title>[^<]*</title>#i', '<title>' . $title . ' | ' . esc_html($brand_name) . '</title>', $top, 1);
    $top = str_ireplace('</head>', $seo . '</head>', $top);

    return $top . $article . $bottom;
}

function kau_site_build_seo(string $page_key, string $origin): string {
    $data = kau_site_get_data();
    $global = is_array($data['global'] ?? null) ? $data['global'] : [];
    $home = is_array($data['home'] ?? null) ? $data['home'] : [];

    $brand = (string) ($global['company_name'] ?? 'KAU');
    $base_desc = '人間工学に基づくオフィスチェア・学習チェアの専門ブランド。日本のものづくりが出会う、ふだん使いの上質を。';

    // 各頁 title / description / og 預設
    $page_url = $origin . '/' . ($page_key === 'home' ? '' : $page_key . '.html');

    switch ($page_key) {
        case 'about':
            $title = ($about_title = (string) (($data['about']['hero']['title'] ?? '')))
                ? $about_title . ' | ' . $brand
                : '会社概要 | ' . $brand;
            $desc  = (string) ($data['about']['statement']['text'] ?? $base_desc);
            $og_image = (string) ($data['about']['craft']['image'] ?? '');
            break;
        case 'products':
            $title = '製品情報 | ' . $brand;
            $lead  = (string) ($data['products']['hero']['lead'] ?? '');
            $desc  = $lead !== '' ? $lead : 'KAU のオフィスチェア・学習チェア・エグゼクティブチェアの全製品ラインナップ。';
            $first_prod = ($data['products']['items'][0] ?? null);
            $og_image = $first_prod ? (string) ($first_prod['image'] ?? '') : '';
            break;
        case 'news':
            $title = '最新情報 | ' . $brand;
            $featured = $data['news']['featured'] ?? null;
            if (is_array($featured)) {
                $sum = trim((string) ($featured['summary'] ?? ''));
                $ftitle = trim((string) ($featured['title'] ?? ''));
                $desc = $sum !== '' ? $sum : ($ftitle !== '' ? $ftitle : '新製品・展示会・お知らせなど、KAU の最新ニュース。');
                $og_image = (string) ($featured['image'] ?? '');
            } else {
                $desc = '新製品・展示会・お知らせなど、KAU の最新ニュース。';
                $og_image = '';
            }
            break;
        case 'home':
        default:
            $hero_line1  = (string) ($home['hero']['line_1'] ?? '');
            $hero_accent = (string) ($home['hero']['accent'] ?? '');
            $hero_sub    = (string) ($home['hero']['subtitle'] ?? '');
            $title = ($hero_line1 . $hero_accent) !== ''
                ? trim($hero_line1 . $hero_accent) . ' | ' . $brand
                : $brand . ' — Sit Beautifully';
            $desc  = $hero_sub !== '' ? $hero_sub : $base_desc;
            $og_image = (string) ($home['hero']['image'] ?? '');
    }

    $desc = mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($desc))), 0, 160);
    if ($og_image && stripos($og_image, 'http') !== 0) {
        $og_image = $origin . '/' . ltrim($og_image, '/');
    }
    if (!$og_image) {
        $og_image = kau_site_favicon_url();
    }

    $h_title = esc_attr($title);
    $h_desc  = esc_attr($desc);
    $h_url   = esc_url($page_url);
    $h_img   = esc_url($og_image);
    $h_brand = esc_attr($brand);

    $tags  = '<meta name="description" content="' . $h_desc . '">';
    $tags .= '<link rel="canonical" href="' . $h_url . '">';
    $tags .= '<meta property="og:type" content="website">';
    $tags .= '<meta property="og:site_name" content="' . $h_brand . '">';
    $tags .= '<meta property="og:title" content="' . $h_title . '">';
    $tags .= '<meta property="og:description" content="' . $h_desc . '">';
    $tags .= '<meta property="og:url" content="' . $h_url . '">';
    $tags .= '<meta property="og:image" content="' . $h_img . '">';
    $tags .= '<meta property="og:locale" content="ja_JP">';
    $tags .= '<meta name="twitter:card" content="summary_large_image">';
    $tags .= '<meta name="twitter:title" content="' . $h_title . '">';
    $tags .= '<meta name="twitter:description" content="' . $h_desc . '">';
    $tags .= '<meta name="twitter:image" content="' . $h_img . '">';
    $tags .= '<meta name="robots" content="index,follow,max-image-preview:large">';

    // JSON-LD
    $ld = [];
    $org = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => $brand,
        'url'      => $origin,
        'logo'     => kau_site_favicon_url(),
    ];
    if (!empty($global['phone']))   $org['telephone'] = $global['phone'];
    if (!empty($global['email']))   $org['email'] = $global['email'];
    if (!empty($global['address_line_1']) || !empty($global['address_line_2'])) {
        $org['address'] = [
            '@type' => 'PostalAddress',
            'streetAddress' => trim(($global['address_line_1'] ?? '') . ' ' . ($global['address_line_2'] ?? '')),
            'postalCode'    => $global['postal_code'] ?? '',
            'addressCountry'=> 'JP',
        ];
    }
    $ld[] = $org;

    if ($page_key === 'products') {
        $items = $data['products']['items'] ?? [];
        if (is_array($items) && $items) {
            $list = [];
            foreach ($items as $i => $p) {
                $list[] = [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'item'     => [
                        '@type'       => 'Product',
                        'name'        => $p['name'] ?? '',
                        'image'       => $p['image'] ?? '',
                        'description' => $p['description'] ?? '',
                        'offers'      => [
                            '@type'         => 'Offer',
                            'priceCurrency' => 'JPY',
                            'price'         => preg_replace('/[^0-9]/', '', (string) ($p['price'] ?? '0')) ?: '0',
                            'availability'  => 'https://schema.org/InStock',
                        ],
                    ],
                ];
            }
            $ld[] = [
                '@context'       => 'https://schema.org',
                '@type'          => 'ItemList',
                'itemListElement'=> $list,
            ];
        }
    }

    if ($page_key === 'news' && !empty($data['news']['featured'])) {
        $f = $data['news']['featured'];
        $ld[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'NewsArticle',
            'headline'    => $f['title'] ?? '',
            'datePublished'=> kau_site_parse_date($f['date'] ?? ''),
            'description' => $f['summary'] ?? '',
            'image'       => $f['image'] ?? '',
            'publisher'   => ['@type' => 'Organization', 'name' => $brand],
        ];
    }

    foreach ($ld as $obj) {
        $tags .= '<script type="application/ld+json">' . wp_json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }

    return $tags;
}

function kau_site_parse_date(string $d): string {
    $d = trim($d);
    if ($d === '') return date('c');
    // 支援 2026.04.08 / 2026-04-08 / 2026/04/08
    $d = str_replace(['.', '/'], '-', $d);
    $ts = strtotime($d);
    return $ts ? date('c', $ts) : date('c');
}

function kau_site_apply_global_footer_address(string $html): string {
    $data = kau_site_get_data();
    $global = is_array($data['global'] ?? null) ? $data['global'] : [];
    $lines = [];
    foreach (['company_name', 'postal_code', 'address_line_1', 'address_line_2', 'address_line_3'] as $key) {
        $value = trim((string) ($global[$key] ?? ''));
        if ($value !== '') $lines[] = $value;
    }
    foreach (preg_split('/\r?\n/', (string) ($global['note'] ?? '')) ?: [] as $note_line) {
        $note_line = trim($note_line);
        if ($note_line !== '' && !in_array($note_line, $lines, true)) $lines[] = $note_line;
    }
    if (!$lines) return $html;

    $content = implode('<br>', array_map('esc_html', $lines));
    return (string) preg_replace_callback(
        '#(<p\b[^>]*\bclass=(["\'])[^"\']*\bfooter-addr\b[^"\']*\2[^>]*>).*?(</p>)#is',
        static fn($match) => $match[1] . $content . $match[3],
        $html
    );
}

function kau_site_sanitize_link_url(string $url): string {
    $url = trim($url);
    if (preg_match('~^(?:https?://)?/?(home|about|products|news)\.html/?([?#].*)?$~i', $url, $match)) {
        return '/' . strtolower($match[1]) . '.html' . ($match[2] ?? '');
    }
    return esc_url_raw($url === '' ? '#' : $url);
}

function kau_site_inject_runtime(string $html, string $page_key, bool $edit_mode): string {
    // 防護：清掉 DB 內舊版 v2.2.0 sidebar 殘留（早期 cleanForSave 沒納入 #kau-ve-sidebar 就被存進 HTML）
    $html = preg_replace('#<aside\s[^>]*\bid=["\']kau-ve-sidebar["\'][^>]*>.*?</aside>#is', '', (string) $html);
    $html = preg_replace('#\bkau-ve-(?:has-sidebar|sidebar-collapsed|block-highlight)\b#i', '', (string) $html);
    $html = preg_replace('#\s+data-kau-ve-sb-key=["\'][^"\']*["\']#i', '', (string) $html);
    // Footer company data is global. Render it on the server for every page so
    // stale browser drafts or a blocked REST request cannot hide saved changes.
    $html = kau_site_apply_global_footer_address($html);
    // nav 狀態要在原本前台 JS 執行前就修正：首頁才用 on-dark，其他頁一律普通白底深字。
    if ($page_key === 'home') {
        $html = preg_replace_callback('#<nav\b([^>]*)\bclass=(["\'])([^"\']*)\2([^>]*)>#i', function($m) {
            $classes = preg_split('/\s+/', trim($m[3])) ?: [];
            if (!in_array('on-dark', $classes, true)) $classes[] = 'on-dark';
            return '<nav' . $m[1] . 'class=' . $m[2] . esc_attr(trim(implode(' ', array_filter($classes)))) . $m[2] . $m[4] . '>';
        }, $html, 1);
    } else {
        $html = preg_replace_callback('#<nav\b([^>]*)\bclass=(["\'])([^"\']*)\2([^>]*)>#i', function($m) {
            $classes = array_values(array_filter(preg_split('/\s+/', trim($m[3])) ?: [], fn($c) => strtolower($c) !== 'on-dark'));
            return '<nav' . $m[1] . 'class=' . $m[2] . esc_attr(trim(implode(' ', $classes))) . $m[2] . $m[4] . '>';
        }, $html, 1);
    }

    $origin = kau_site_safe_origin();
    // 注入 KAU_CONTENT_URL（讓 cms-content.js 抓我們的 API）
    $data_url = $origin . '/wp-json/kau-site/v1/data?v=' . (int) (kau_site_get_data_updated());
    // 所有 cms_inject 的元素加 kau-cms-* ID，cleanForSave 才能精準剔除（不然每次存檔會被烤進 DB 累積）
    $cms_inject = '<script id="kau-cms-content-url">window.KAU_CONTENT_URL=' . wp_json_encode($data_url) . ';</script>';
    $favicon_url = kau_site_favicon_url();
    $cms_inject .= '<link id="kau-cms-favicon" rel="icon" type="image/svg+xml" href="' . esc_url($favicon_url) . '">';
    $cms_inject .= '<link id="kau-cms-apple-icon" rel="apple-touch-icon" href="' . esc_url($favicon_url) . '">';
    // 強制淺色模式：避免 Chrome 自動深色模式（force-dark flag）把白底深字反成黑底白字導致導覽列文字看不見
    $cms_inject .= '<meta id="kau-cms-color-scheme" name="color-scheme" content="only light">';
    $cms_inject .= '<style id="kau-cms-color-scheme-style">:root{color-scheme:only light}</style>';
    // Editable Japanese copy can contain glyphs outside the bundled subset
    // webfonts. Use one complete system Japanese font stack in the corporate
    // purchasing block so a single sentence never mixes fallback glyphs.
    $cms_inject .= '<style id="kau-cms-jp-font-fix">.section.center h2,.section.center h2 *,.section.center .lead{font-family:"Yu Gothic","YuGothic","Hiragino Kaku Gothic ProN","Meiryo",sans-serif!important}.kau-user-sized-text{max-width:100%!important}</style>';
    // v2.3.10 以前的縮放工具已存下 width/height，卻沒有解除原始 max-width。
    // 同時帶有 inline width + height 的文字元素可確定是舊縮放工具產物，直接補上持久 class。
    $cms_inject .= '<script id="kau-cms-user-size-migrate">(function(){function fix(){document.querySelectorAll("h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],p[style],span[style],a[style],li[style],blockquote[style],figcaption[style],button[style]").forEach(function(el){if(el.style.width&&el.style.height)el.classList.add("kau-user-sized-text");});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",fix);else fix();})();</script>';
    // Path 別名表 / 不同步清單：編輯器（寫入）與 final-sync（讀取）共用同一份，
    // 兩邊對「這個元素對應哪個資料 key」的認知必須一致，否則存好的文字會被舊 key 蓋回去。
    $cms_inject .= '<script id="kau-cms-path-maps">window.KAU_PATH_ALIASES=' . wp_json_encode(kau_site_path_aliases())
        . ';window.KAU_SYNC_SKIP=' . wp_json_encode(kau_site_sync_skip_paths()) . ';</script>';
    $cms_inject .= '<meta id="kau-cms-notranslate-meta" name="google" content="notranslate">';
    $cms_inject .= '<script id="kau-cms-notranslate-attr">document.documentElement.setAttribute("translate","no");document.documentElement.classList.add("notranslate");</script>';
    // 在 cms-content.js 跑之前把 data-kau-edit / data-kau-media / data-kau-link 備份到 data-kau-static-*
    // （cms-content.js render 時會拔掉這些 attr，導致視覺編輯器改文字後同步找不到 path）
    $cms_inject .= '<script id="kau-cms-backup-paths">(function(){function backup(){document.querySelectorAll("[data-kau-edit]").forEach(function(el){if(!el.dataset.kauStaticPath)el.dataset.kauStaticPath=el.getAttribute("data-kau-edit");});document.querySelectorAll("[data-kau-media]").forEach(function(el){if(!el.dataset.kauStaticMedia)el.dataset.kauStaticMedia=el.getAttribute("data-kau-media");});document.querySelectorAll("[data-kau-link]").forEach(function(el){if(!el.dataset.kauStaticLink)el.dataset.kauStaticLink=el.getAttribute("data-kau-link");});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",backup,true);else backup();})();</script>';

    // image-slot Shadow DOM 注入：用 polling 重試 + 監聽 attribute 變化，確保 shadowRoot 一就緒就注入
    // 首頁 nav 兜底：DB 若曾被髒儲存（on-dark 被烤掉），DOMReady 時補回，並重綁 scroll handler
    // 非首頁兜底：若 DB 烤進了 on-dark class（白字白底看不見），DOMReady 時移除
    // 另：clone-replace nav links — 修復 Chrome force-dark 把舊 <a> 元素卡在白字狀態的瀏覽器渲染快取 bug
    $cms_inject .= '<script id="kau-cms-nav-fix">(function(){function refreshNavLinks(){document.querySelectorAll(".nav-links a, .nav-cta, .nav-shop-btn").forEach(function(a){var c=a.cloneNode(true);a.parentElement.replaceChild(c,a);});}function fix(){var p=location.pathname;var n=document.getElementById("nav")||document.querySelector("nav.nav");if(!n)return;var isHome=(p==="/"||/\/home\.html$/.test(p));if(!isHome){n.classList.remove("on-dark");refreshNavLinks();return;}n.classList.add("on-dark");if(n.style&&n.style.boxShadow==="none")n.style.boxShadow="";var onScroll=function(){var solid=window.scrollY>window.innerHeight*0.7;n.classList.toggle("on-dark",!solid);n.style.boxShadow=window.scrollY>10?"0 1px 0 rgba(0,0,0,.04)":"none";};window.addEventListener("scroll",onScroll,{passive:true});onScroll();}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",fix);else fix();})();</script>';
    // reveal 動畫安全網：讓原本動畫正常跑，但 3 秒後檢查若有 [data-reveal] 仍 opacity:0（reveal JS 沒載入或壞掉）就強制顯示
    // 這樣同事看訪客頁能看到 fade-in 動畫，又不會因為 JS 壞掉整頁白屏
    $cms_inject .= '<script id="kau-cms-reveal-safety">(function(){function forceShow(){document.querySelectorAll("[data-reveal],[data-hero]").forEach(function(el){if(el.classList.contains("kau-rv"))return;var cs=getComputedStyle(el);if(parseFloat(cs.opacity)<0.1){el.style.opacity="1";el.style.transform="none";}});}setTimeout(forceShow,3000);setTimeout(forceShow,6000);})();</script>';
    $cms_inject .= '<script id="kau-cms-shadow-fix">(function(){var SEL=".split-b image-slot, .gcard image-slot, .pcard image-slot, .feat2 image-slot";var CSS=".frame img{max-width:100%!important;max-height:100%!important;width:auto!important;height:auto!important;object-fit:contain!important;left:50%!important;top:50%!important;transform:translate(-50%,-50%)!important}.frame{background:#fff!important;overflow:hidden!important}";function inject(s){if(s.dataset.kauFit)return;if(!s.shadowRoot)return;var st=document.createElement("style");st.textContent=CSS;s.shadowRoot.appendChild(st);s.dataset.kauFit="1";}function fixAll(){document.querySelectorAll(SEL).forEach(inject);}function loop(){fixAll();var unfinished=Array.from(document.querySelectorAll(SEL)).filter(function(s){return !s.dataset.kauFit;});if(unfinished.length)setTimeout(loop,200);}function boot(){loop();if(document.body)new MutationObserver(fixAll).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:["src","shape"]});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();})();</script>';
    // SEO meta + JSON-LD（訪客模式才注入；編輯模式避免污染存檔）
    if (!$edit_mode) {
        $cms_inject .= kau_site_build_seo($page_key, $origin);
    }

    // 捲動 reveal 動畫（訪客模式限定；編輯模式不注入避免干擾編輯與存檔）
    // 頁面 assets 裡本來就有「KAU animation engine」（rAF tween，處理 [data-reveal]/[data-hero]/[data-count]），
    // 但內頁 HTML 幾乎沒有掛 data-reveal 標記，所以看起來沒動畫。
    // 策略：
    //   1. DOMContentLoaded 先跑（head script 的 listener 比引擎的先註冊）→ 幫靜態區塊補上 data-reveal，交給既有引擎動畫。
    //   2. 引擎 init 之後才動態長出來的元素（cms-content.js 的商品/新聞卡片）引擎接不到，
    //      用自己的 IntersectionObserver + .kau-rv class 補上一次性的 fade-up。
    if (!$edit_mode) {
        $cms_inject .= '<style id="kau-cms-reveal-style">'
            . 'html.kau-anim .kau-rv{opacity:0;transform:translateY(26px);transition:opacity .7s cubic-bezier(.22,.61,.36,1),transform .7s cubic-bezier(.22,.61,.36,1);transition-delay:var(--kau-rd,0s)}'
            . 'html.kau-anim .kau-rv.kau-in{opacity:1;transform:none}'
            . '@media(prefers-reduced-motion:reduce){html.kau-anim .kau-rv{opacity:1!important;transform:none!important;transition:none!important}}'
            . '</style>';
        $cms_inject .= '<script id="kau-cms-reveal">(function(){'
            . 'try{'
            . 'if(!("IntersectionObserver" in window))return;'
            . 'if(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches)return;'
            // 要補動畫標記的區塊（內頁原本幾乎沒有）
            . 'var SEL=".page-hero .wrap>*,.section-head,.otable .row,.tl-item,.feat2>*,.philo3 .p,.about-statement .wrap>*,.pcard,.gcard,.nrow2,.news-feat,.filters,.banner .b-txt,.pager,.split-b .txt>*";'
            . 'function pick(root){'
            . 'var list=[];'
            . 'if(root.matches&&root.matches(SEL))list.push(root);'
            . 'if(root.querySelectorAll)list=list.concat(Array.prototype.slice.call(root.querySelectorAll(SEL)));'
            . 'return list.filter(function(el){return !(el.closest&&(el.closest("nav")||el.closest("footer")));});'
            . '}'
            // 階段 1：引擎 init 前補 data-reveal（引擎在 DOMContentLoaded init，我們的 listener 先註冊所以先跑）
            . 'function preTag(){pick(document.body).forEach(function(el){if(!el.hasAttribute("data-reveal"))el.setAttribute("data-reveal","");});}'
            // 階段 2：引擎 init 後才出現的元素 → 自己的一次性 IO reveal
            . 'var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add("kau-in");io.unobserve(e.target);}});},{rootMargin:"0px 0px -8% 0px",threshold:0.05});'
            . 'var n=0;'
            . 'function adopt(nd){'
            . 'var list=pick(nd);'
            . 'if(nd.matches&&nd.matches("[data-reveal]")&&list.indexOf(nd)<0)list.push(nd);'
            . 'if(nd.querySelectorAll)Array.prototype.forEach.call(nd.querySelectorAll("[data-reveal]"),function(el){if(list.indexOf(el)<0)list.push(el);});'
            . 'list.forEach(function(el){'
            . 'if(el.classList.contains("kau-rv"))return;'
            . 'el.classList.add("kau-rv");'
            . 'el.style.setProperty("--kau-rd",((n++)%5)*70+"ms");'
            . 'io.observe(el);'
            . '});'
            . '}'
            . 'function boot(){'
            . 'preTag();'
            . 'document.documentElement.classList.add("kau-anim");'
            . 'new MutationObserver(function(ms){ms.forEach(function(m){Array.prototype.forEach.call(m.addedNodes,function(nd){if(nd.nodeType===1)adopt(nd);});});}).observe(document.body,{childList:true,subtree:true});'
            // 安全網：4 秒後可視範圍內還沒 reveal 的 .kau-rv 直接顯示（IO 異常時不留白）
            . 'setTimeout(function(){document.querySelectorAll(".kau-rv:not(.kau-in)").forEach(function(el){var r=el.getBoundingClientRect();if(r.top<innerHeight&&r.bottom>0)el.classList.add("kau-in");});},4000);'
            . '}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();'
            . '}catch(e){document.documentElement.classList.remove("kau-anim");}'
            . '})();</script>';
    }
    $nav_mode_css = $page_key === 'home'
        ? ''
        : '.nav{background:rgba(255,255,255,.94)!important;backdrop-filter:blur(14px)!important}.nav:not(.on-dark) .nav-links a,.nav:not(.on-dark) .nav-cta{color:var(--ink,#001b3d)!important}.nav:not(.on-dark) .nav-links a small{color:rgba(0,27,61,.55)!important}.nav:not(.on-dark) .nav-shop-btn{color:#111!important;background:var(--gold,#d4a574)!important}';
    // 修正首頁 showcase 兩條多餘的線：(1) .gm border-top 分隔線 (2) 橫向捲軸槽
    // 另外：data-reveal 動畫元素若 reveal JS 沒跑會永遠 opacity:0 整頁看不到 → fallback 強制顯示
    $cms_inject .= '<style id="kau-cms-style">'
        // 鎖死 html/body 字級 + 取消 Gutenberg 的字級變數（兜底防漏）
        . 'html{font-size:16px!important}body{font-size:16px!important;color:var(--ink,#001b3d)!important}'
        . 'h1,h2,h3,h4,h5,h6{font-size:revert;color:revert}'
        . $nav_mode_css
        . '.philo3 .p .n,.feat2 .eyebrow,.profile .label .eyebrow,.history .label .eyebrow,.access .label .eyebrow{font-family:var(--latin,"Inter","Noto Sans JP","Noto Sans TC",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif)!important;font-weight:700!important;letter-spacing:.16em!important}'
        . '.philo3 .p h3,.feat2 h2,.profile .title,.history .title,.access .title{font-family:var(--sans,"Noto Sans JP","Noto Sans TC","Yu Gothic","Hiragino Kaku Gothic ProN","Meiryo",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif)!important;font-weight:700!important;letter-spacing:.02em!important}'
        . '.show-b .gcard .gm{border-top:0!important;padding-top:0!important;margin-top:14px!important}'
        . '.show-b .track{scrollbar-width:none!important}'
        . '.show-b .track::-webkit-scrollbar{display:none!important;height:0!important;width:0!important}'
        // 精選椅子卡片縮小：圖片改方形比例（從 4:5 變 1:1），整張卡片變矮
        . '.show-b .gcard .gph,.show-b .gcard .ph{aspect-ratio:1/1!important}'
        . '.show-b .track{gap:18px!important}'
        // Signature 大區塊（feature）整體縮小 padding + 隱藏統計數字
        . '.split-b,.feat2,section.split,section[data-kau-block*="feature"]{padding-top:40px!important;padding-bottom:40px!important}'
        // 不強制 min-height 避免椅子圖被切；讓圖容器自然跟著右側文字長度
        . '.split-b .img,.feat2 .img-side{min-height:0!important}'
        . '.split-b .img image-slot,.split-b .img img,.feat2 .img-side image-slot,.feat2 .img-side img{object-fit:contain!important;background:#fff}'
        . '.split-b .txt h2,.feat2 .txt h2,.feat2 h2{font-size:clamp(22px,2vw,30px)!important;line-height:1.4!important}'
        . '.split-b .specs,.feat2 .specs,.feature-stats,[data-kau-list*="feature.stats"]{display:none!important}'
        // 商品彈窗「詳細資訊」保留使用者輸入的換行（DB 內舊版頁面 CSS 缺這條）
        . '.product-detail-desc{white-space:pre-line!important}'
        . '.product-detail-thumbs{display:flex!important;gap:8px!important;flex-wrap:wrap!important;margin:10px 0 18px!important}.product-detail-thumb{width:54px!important;height:54px!important;padding:3px!important;border:1px solid var(--line,#e2ddd7)!important;background:#fff!important;cursor:pointer!important}.product-detail-thumb.on{border-color:var(--gold,#d4a574)!important}.product-detail-thumb img{width:100%!important;height:100%!important;object-fit:contain!important;display:block!important}'
        // news.html 分頁的「次へ」按鈕 — 文字與箭頭原本垂直疊，改成水平排
        . '.pager .nx,.pager .pv{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;padding:0 14px!important;white-space:nowrap!important}'
        . '.pager .nx svg,.pager .pv svg{display:inline-block!important;vertical-align:middle!important;width:14px!important;height:14px!important}'
        . '</style>';
    if (stripos($html, '</head>') !== false) {
        $html = str_ireplace('</head>', $cms_inject . '</head>', $html);
    } else {
        $html = $cms_inject . $html;
    }

    if (!$edit_mode) {
        // 訪客模式：插入「編輯此頁面」按鈕（只給可編輯者看到）
        $final_sync = <<<'HTML'
<script id="kau-cms-final-sync">(function(){
  function getPath(obj,path){
    var m=String(path||"").match(/^home\.values\.item(\d+)\.(title|desc|icon)$/);
    if(m){
      var item=obj&&obj.home&&obj.home.values&&obj.home.values.items&&obj.home.values.items[Number(m[1])-1];
      return item ? item[m[2]==="desc"?"description":(m[2]==="icon"?"image":"title")] : undefined;
    }
    return String(path||"").split(".").reduce(function(cur,key){return cur&&Object.prototype.hasOwnProperty.call(cur,key)?cur[key]:undefined;},obj);
  }
  // 元素上的 path 可能是舊名（home.hero.sub），存檔時值是寫進正式 key（home.hero.subtitle）的。
  // 先查正式 key，沒有值才退回原 path，否則會拿舊 key 的舊值蓋掉剛存的文字。
  function readPath(data,path){
    if(!path)return undefined;
    var alias=(window.KAU_PATH_ALIASES||{})[path];
    if(alias){
      var aliased=getPath(data,alias);
      if(aliased!==undefined&&aliased!==null)return aliased;
    }
    return getPath(data,path);
  }
  function skipped(path){
    var list=window.KAU_SYNC_SKIP||["home.hero.title","home.cta.title","home.footer.addr","home.footer.brand"];
    return list.indexOf(path)>=0;
  }
  function apply(data){
    if(!data)return;
    document.querySelectorAll("[data-kau-edit]").forEach(function(el){
      var path=el.getAttribute("data-kau-edit");
      if(skipped(path)||el.matches(".hero-b h1"))return;
      if(el.children&&el.children.length)return;
      var value=readPath(data,path);
      if(value===undefined||value===null)return;
      el.textContent=String(value);
    });
    document.querySelectorAll("[data-kau-link]").forEach(function(el){
      var path=el.getAttribute("data-kau-link")+"_url";
      if(skipped(path))return;
      var value=readPath(data,path);
      if(value)el.setAttribute("href",String(value));
    });
    document.querySelectorAll("[data-kau-media]").forEach(function(el){
      var value=readPath(data,el.getAttribute("data-kau-media"));
      if(value)el.setAttribute("src",String(value));
    });
  }
  function load(){
    var url=window.KAU_CONTENT_URL;
    if(!url)return;
    fetch(url+(url.indexOf("?")>=0?"&":"?")+"final="+Date.now(),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(data){
      apply(data);
      setTimeout(function(){apply(data);},300);
      setTimeout(function(){apply(data);},1200);
    }).catch(function(){});
  }
  if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",load);else load();
  window.addEventListener("load",load);
})();</script>
HTML;
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $final_sync . '</body>', $html);
        } else {
            $html .= $final_sync;
        }
        if (kau_site_can_edit()) {
            $edit_url = esc_url(add_query_arg('kau_edit', '1'));
            $btn = '<a href="' . $edit_url . '" id="kau-site-enter" style="position:fixed;bottom:20px;right:20px;background:#111;color:#fff;padding:10px 18px;border-radius:999px;font:600 13px/1 system-ui;text-decoration:none;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.3)">編輯此頁面 →</a>';
            $html = str_ireplace('</body>', $btn . '</body>', $html);
        }
        return $html;
    }

    // 編輯模式：注入 JS + CSS + WordPress 媒體庫
    $page_view_urls = [
        'home'     => $origin . '/',
        'about'    => $origin . '/about/',
        'products' => $origin . '/products/',
        'news'     => $origin . '/news/',
    ];
    $page_edit_urls = [
        'home'     => $page_view_urls['home'] . '?kau_edit=1',
        'about'    => $page_view_urls['about'] . '?kau_edit=1',
        'products' => $page_view_urls['products'] . '?kau_edit=1',
        'news'     => $page_view_urls['news'] . '?kau_edit=1',
    ];
    $cfg = [
        'pageKey'      => $page_key,
        'ajaxUrl'      => $origin . '/wp-admin/admin-ajax.php',
        'nonce'        => wp_create_nonce('kau_site_save'),
        'viewUrl'      => esc_url_raw($page_view_urls[$page_key] ?? $origin . '/'),
        'pageEditUrls' => array_map('esc_url_raw', $page_edit_urls),
    ];
    $cfg_json = wp_json_encode($cfg);
    $css = kau_site_editor_css();
    $js  = kau_site_editor_js();

    // 載入 WordPress 媒體庫（template_redirect 階段，安全）
    // 完整印出 WP enqueue 內容；存檔時 cleanForSave() 會把這些 WP 注入過濾掉，
    // 不會污染 DB；admin-bar 額外手動 dequeue，避免編輯模式頂部多 32px 白條
    $wp_media = '';
    if (function_exists('wp_enqueue_media')) {
        add_filter('show_admin_bar', '__return_false');
        wp_enqueue_media();
        ob_start();
        do_action('wp_enqueue_scripts');
        // 移除 admin-bar 樣式（避免 html{margin-top:32px} 在編輯模式顯示為白條）
        wp_dequeue_style('admin-bar');
        wp_deregister_style('admin-bar');
        wp_dequeue_script('admin-bar');
        wp_deregister_script('admin-bar');
        // Gutenberg / 區塊編輯器 CSS 會把 h1-h6 字級設成 --wp--custom--typography 變數，污染前台元素字級
        // 也把 body color 設成 #000。全部 dequeue。
        foreach ([
            'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles',
            'wc-blocks-style', 'core-block-supports', 'gutenberg-style',
            'help-center-wp-admin-disconnected-style', 'jetpack-newsletter-reader-link',
        ] as $h) {
            wp_dequeue_style($h);
            wp_deregister_style($h);
        }
        wp_print_styles();
        wp_print_head_scripts();
        wp_print_scripts();
        wp_print_footer_scripts();
        if (function_exists('wp_print_media_templates')) {
            wp_print_media_templates();
        }
        $wp_media = (string) ob_get_clean();
        // 清掉 WordPress.com mu-plugin 注入的 admin-bar / wpcom-admin-bar 樣式
        // 它們會印 html{margin-top:32px !important}，造成編輯模式頂部白條
        $wp_media = preg_replace('#<link[^>]*\b(admin-bar|wpcom-admin-bar)[^>]*>#i', '', $wp_media);
        $wp_media = preg_replace('#<style[^>]*\b(admin-bar|wpcom-admin-bar)[^>]*>.*?</style>#is', '', $wp_media);
        // 保險：移除任何 html{margin-top:NNpx} 規則
        $wp_media = preg_replace('#html\s*\{\s*margin-top\s*:[^}]+\}#i', '', $wp_media);
        $wp_media = preg_replace('#\.admin-bar\s*\{[^}]+\}#i', '', $wp_media);
    }

    // 前台媒體庫 modal 修復：前台沒有 wp-admin 的 common.css（.screen-reader-text 等），
    // 頁面自己的 reset（*{margin:0;padding:0}、letter-spacing、line-height:1.8）又會滲進 modal。
    // 這裡補齊缺的規則 + 用 !important 把 modal 版面釘回 wp-admin 的樣子。
    // id 用 kau-cms- 前綴 → cleanForSave / strip_wp_pollution 存檔時自動剔除。
    $media_fix = '<style id="kau-cms-media-fix">'
        // screen-reader 隱藏文字（"Selected media actions" 之類）
        . '.media-modal .screen-reader-text,.media-frame .screen-reader-text,.media-modal h2.media-frame-menu-heading{border:0!important;clip:rect(1px,1px,1px,1px)!important;clip-path:inset(50%)!important;height:1px!important;width:1px!important;margin:-1px!important;overflow:hidden!important;padding:0!important;position:absolute!important;word-wrap:normal!important}'
        // modal 外框 + 背景遮罩（z-index 要高於編輯器 sidebar 的 2147483600）
        . '.media-modal{position:fixed!important;top:28px!important;left:28px!important;right:28px!important;bottom:28px!important;z-index:2147483645!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP","Noto Sans TC",sans-serif!important;letter-spacing:normal!important}'
        . '.media-modal-backdrop{position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;background:#000!important;opacity:.75!important;z-index:2147483644!important}'
        . '.media-modal-content{position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;background:#fff!important;box-shadow:0 5px 15px rgba(0,0,0,.7)!important;border-radius:4px;overflow:hidden}'
        . '.media-modal *{letter-spacing:normal;line-height:1.4}'
        . '.media-modal button.media-modal-close{position:absolute!important;top:0!important;right:0!important;width:50px!important;height:50px!important;background:none!important;border:0!important;cursor:pointer;z-index:3}'
        // 標題列
        . '.media-frame-title{position:absolute!important;top:0!important;left:0!important;right:0!important;height:50px!important;background:#fff!important;border-bottom:1px solid #dcdcde!important;z-index:2}'
        . '.media-frame-title h1{font-size:20px!important;line-height:50px!important;margin:0!important;padding:0 16px!important;font-weight:600!important;font-family:inherit!important;color:#1d2327!important}'
        // 分頁（上傳檔案 / 媒體庫）
        . '.media-frame-router{position:absolute!important;top:50px!important;left:0!important;right:0!important;height:36px!important;background:#fff!important;border-bottom:1px solid #dcdcde!important;padding:0 10px!important;z-index:2}'
        . '.media-frame-router .media-router{display:flex!important;gap:2px!important;height:36px!important}'
        . '.media-frame-router .media-menu-item{border:0!important;border-bottom:4px solid transparent!important;background:none!important;font-size:14px!important;padding:0 12px!important;margin:0!important;cursor:pointer;color:#2271b1!important;height:36px!important;line-height:32px!important}'
        . '.media-frame-router .media-menu-item.active{border-bottom-color:#3582c4!important;color:#1d2327!important;font-weight:600!important}'
        // 內容區與底部工具列
        . '.media-frame-content{position:absolute!important;top:86px!important;left:0!important;right:0!important;bottom:61px!important;overflow:auto!important;background:#fff!important}'
        . '.media-frame-toolbar{position:absolute!important;left:0!important;right:0!important;bottom:0!important;height:60px!important;z-index:2}'
        . '.media-frame-toolbar .media-toolbar{position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;border-top:1px solid #dcdcde!important;background:#fff!important;padding:0 16px!important}'
        . '.media-frame-toolbar .media-toolbar-primary{float:right!important;height:60px!important;display:flex!important;align-items:center!important}'
        // 按鈕補回 wp-admin 樣式
        . '.media-modal .button{display:inline-flex!important;align-items:center!important;height:32px!important;padding:0 12px!important;border:1px solid #2271b1!important;border-radius:3px!important;background:#f6f7f7!important;color:#2271b1!important;font-size:13px!important;cursor:pointer!important;text-decoration:none!important}'
        . '.media-modal .button-primary{background:#2271b1!important;border-color:#2271b1!important;color:#fff!important}'
        . '.media-modal .button:disabled,.media-modal .button[disabled]{border-color:#dcdcde!important;background:#f6f7f7!important;color:#a7aaad!important;cursor:default!important}'
        // 上傳區塊
        . '.media-frame .uploader-inline{text-align:center!important;padding-top:70px!important}'
        . '.media-frame .uploader-inline h2{font-size:20px!important;font-weight:400!important;color:#3c434a!important;margin:0 0 10px!important}'
        . '</style>';

    $head = '<style id="kau-site-editor-css">' . $css . '</style>';
    $foot = $wp_media . $media_fix . '<script>window.KAU_SITE_CFG=' . $cfg_json . ';</script><script>' . $js . '</script>';
    if (stripos($html, '</head>') !== false) $html = str_ireplace('</head>', $head . '</head>', $html);
    else $html = $head . $html;
    if (stripos($html, '</body>') !== false) $html = str_ireplace('</body>', $foot . '</body>', $html);
    else $html .= $foot;
    return $html;
}

// ─── 編輯器 CSS / JS ─────────────────────────────────────────────────────

function kau_site_editor_css(): string {
    return <<<'CSS'
/* ─── 左側區塊清單 sidebar ─────────────────────────────────────── */
body.kau-ve-has-sidebar { --kau-ve-sidebar-offset: 300px; margin-left: 0 !important; padding-left: 0 !important; box-sizing: border-box !important; overflow-x: hidden !important; }
body.kau-ve-sidebar-collapsed,
body.kau-ve-has-sidebar.kau-ve-sidebar-collapsed { --kau-ve-sidebar-offset: 44px; padding-left: 0 !important; }
#kau-ve-sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 300px; z-index: 2147483600; background: rgba(15,15,20,.96); color: #fff; font: 13px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif; display: flex; flex-direction: column; border-right: 1px solid rgba(255,255,255,.08); backdrop-filter: blur(14px); }
body.kau-ve-sidebar-collapsed #kau-ve-sidebar { width: 44px; }
body.kau-ve-sidebar-collapsed #kau-ve-sidebar .kau-ve-sb-body,
body.kau-ve-sidebar-collapsed #kau-ve-sidebar .kau-ve-sb-title { display: none; }
#kau-ve-sidebar .kau-ve-sb-head { display: flex; align-items: center; gap: 10px; padding: 14px 14px 12px; border-bottom: 1px solid rgba(255,255,255,.08); flex: 0 0 auto; }
#kau-ve-sidebar .kau-ve-sb-logo { width: 22px; height: 22px; border-radius: 5px; background: #d4a574; color: #000; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex: 0 0 auto; }
#kau-ve-sidebar .kau-ve-sb-title { font-weight: 600; font-size: 13px; flex: 1; }
#kau-ve-sidebar .kau-ve-sb-toggle { background: none; border: 1px solid rgba(255,255,255,.15); color: #fff; border-radius: 6px; width: 26px; height: 26px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; padding: 0; }
#kau-ve-sidebar .kau-ve-sb-toggle:hover { background: rgba(255,255,255,.08); }
#kau-ve-sidebar .kau-ve-sb-body { flex: 1; overflow-y: auto; padding: 8px 6px 14px; }
#kau-ve-sidebar .kau-ve-sb-section-label { font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,.45); padding: 10px 10px 6px; }
#kau-ve-sidebar .kau-ve-sb-block { border-radius: 6px; margin: 1px 4px; overflow: hidden; }
#kau-ve-sidebar .kau-ve-sb-block.is-active { background: rgba(212,165,116,.16); }
#kau-ve-sidebar .kau-ve-sb-block.is-hover { background: rgba(255,255,255,.06); }
#kau-ve-sidebar .kau-ve-sb-block-head { display: flex; align-items: center; gap: 8px; padding: 8px 10px; cursor: pointer; user-select: none; }
#kau-ve-sidebar .kau-ve-sb-block.is-active .kau-ve-sb-block-head { color: #d4a574; }
#kau-ve-sidebar .kau-ve-sb-block-caret { font-size: 9px; opacity: .6; width: 10px; flex: 0 0 auto; transition: transform .15s; }
#kau-ve-sidebar .kau-ve-sb-block.is-open .kau-ve-sb-block-caret { transform: rotate(90deg); }
#kau-ve-sidebar .kau-ve-sb-block-name { flex: 1; font-weight: 500; font-size: 12.5px; }
#kau-ve-sidebar .kau-ve-sb-block-count { font-size: 10px; color: rgba(255,255,255,.4); padding: 1px 6px; border-radius: 8px; background: rgba(255,255,255,.06); }
#kau-ve-sidebar .kau-ve-sb-fields { display: none; padding: 4px 10px 10px 22px; border-left: 1px solid rgba(212,165,116,.3); margin: 0 10px 4px; }
#kau-ve-sidebar .kau-ve-sb-block.is-open .kau-ve-sb-fields { display: block; }
#kau-ve-sidebar .kau-ve-sb-field { margin: 6px 0; }
#kau-ve-sidebar .kau-ve-sb-field-label { font-size: 10px; color: rgba(255,255,255,.5); margin-bottom: 3px; display: flex; gap: 6px; align-items: center; }
#kau-ve-sidebar .kau-ve-sb-field-tag { background: rgba(255,255,255,.08); color: rgba(255,255,255,.7); padding: 1px 5px; border-radius: 3px; font: 600 9px/1.4 ui-monospace,"SF Mono",monospace; }
#kau-ve-sidebar .kau-ve-sb-field-input, #kau-ve-sidebar .kau-ve-sb-field-textarea { width: 100%; box-sizing: border-box; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); color: #fff; border-radius: 4px; padding: 5px 7px; font: 12px/1.4 system-ui,-apple-system,sans-serif; outline: none; resize: vertical; }
#kau-ve-sidebar .kau-ve-sb-field-textarea { min-height: 52px; }
#kau-ve-sidebar .kau-ve-sb-field-input:focus, #kau-ve-sidebar .kau-ve-sb-field-textarea:focus { border-color: #d4a574; background: rgba(212,165,116,.1); }
#kau-ve-sidebar .kau-ve-sb-field.is-synced .kau-ve-sb-field-input,
#kau-ve-sidebar .kau-ve-sb-field.is-synced .kau-ve-sb-field-textarea { border-color: #d4a574; }
#kau-ve-sidebar .kau-ve-sb-field-image { display: flex; align-items: center; gap: 8px; padding: 5px 7px; background: rgba(255,255,255,.04); border: 1px dashed rgba(255,255,255,.15); border-radius: 4px; cursor: pointer; }
#kau-ve-sidebar .kau-ve-sb-field-image:hover { border-color: #d4a574; background: rgba(212,165,116,.1); }
#kau-ve-sidebar .kau-ve-sb-field-image .thumb { width: 32px; height: 24px; border-radius: 3px; background: rgba(255,255,255,.1) center/cover no-repeat; flex: 0 0 auto; }
#kau-ve-sidebar .kau-ve-sb-field-image .name { flex: 1; font-size: 11px; color: rgba(255,255,255,.65); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#kau-ve-sidebar .kau-ve-sb-note { color: rgba(255,255,255,.56); font-size: 11.5px; line-height: 1.55; padding: 8px 10px 10px 22px; border-left: 1px solid rgba(212,165,116,.3); margin: 0 10px 4px; }
#kau-ve-sidebar .kau-ve-sb-empty { padding: 30px 14px; text-align: center; color: rgba(255,255,255,.4); font-size: 12px; }
[data-kau-block].kau-ve-block-highlight { outline: 3px solid #d4a574 !important; outline-offset: -3px; position: relative; }

/* 編輯模式：避免 data-reveal 動畫元素開始時 opacity:0 而無法看到/點到 */
[data-reveal] { opacity: 1 !important; transform: none !important; }
/* 強制蓋掉 admin-bar / wpcom-admin-bar 殘留的頂部留白 */
html, html.wp-toolbar { margin-top: 0 !important; padding-top: 0 !important; }
html body.admin-bar, body.admin-bar { margin-top: 0 !important; padding-top: 0 !important; }
#wpadminbar, #wpcom-toolbar, #wpcom-masterbar { display: none !important; }
body { padding-bottom: 92px !important; }
#kau-site-toolbar { position: fixed; bottom: 0; left: var(--kau-ve-sidebar-offset, 300px); right: 0; min-height: 64px; background: #111; color: #fff; padding: 12px 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; z-index: 2147483590; font: 13px/1 system-ui, -apple-system, sans-serif; box-shadow: 0 -4px 16px rgba(0,0,0,.2); box-sizing: border-box; transition: left .15s; }
body.kau-ve-sidebar-collapsed #kau-site-toolbar,
body.kau-ve-has-sidebar.kau-ve-sidebar-collapsed #kau-site-toolbar,
body:not(.kau-ve-has-sidebar).kau-ve-sidebar-collapsed #kau-site-toolbar { left: 44px !important; }
#kau-site-toolbar strong { height: 40px; display: inline-flex; align-items: center; flex: 0 0 auto; }
#kau-site-toolbar button, #kau-site-toolbar a { height: 40px; min-height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; box-sizing: border-box; background: #fff; color: #111; border: 0; padding: 0 16px; border-radius: 6px; font: 600 13px/1 system-ui, -apple-system, sans-serif; cursor: pointer; text-decoration: none; white-space: nowrap; }
#kau-site-toolbar button.primary { background: #d4a574; color: #fff; }
#kau-site-toolbar button:disabled { opacity: .5; cursor: not-allowed; }
#kau-site-toolbar .status { margin-left: auto; opacity: .85; max-width: min(52%, 620px); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#kau-site-toolbar .kau-page-switch { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; }
#kau-site-toolbar .kau-page-switch a { height: 40px; min-height: 40px; padding: 0 14px; background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.18); }
#kau-site-toolbar .kau-page-switch a.active { background: #d4a574; color: #111; border-color: #d4a574; }
/* contenteditable 不再強制 font/color — 元素本身的 CSS（含 .accent 金色等）會正常生效。
   防止「編輯後字變樣」改用 paste handler 攔截外來 style（只插純文字） */
.kau-edit-text { outline: 2px dashed transparent; outline-offset: 2px; transition: outline-color .15s; }
/* 防止編輯時誤貼超長文字蓋到下方區塊：focus 時自動允許 vertical scroll，內容不會溢出 */
.kau-edit-text:focus { max-height: 60vh; overflow-y: auto; }
.kau-edit-text:hover { outline-color: rgba(212,165,116,.6); cursor: text; }
.kau-edit-text:focus { outline-color: #d4a574; background: rgba(212,165,116,.08); }
.kau-edit-img { position: relative; cursor: pointer; }
.kau-edit-img:hover { outline: 2px dashed #d4a574; outline-offset: 2px; }
.kau-img-badge { position: absolute; top: 6px; right: 6px; background: rgba(0,0,0,.7); color: #fff; font: 14px/1 sans-serif; padding: 4px 6px; border-radius: 4px; pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 100; }
.kau-edit-img:hover .kau-img-badge { opacity: 1; }
.kau-link-selected { outline: 2px solid #d4a574 !important; outline-offset: 3px !important; }
#kau-site-link-panel { position: fixed; bottom: 70px; left: 20px; background: #fff; border: 1px solid #ddd; padding: 16px; border-radius: 8px; box-shadow: 0 6px 24px rgba(0,0,0,.18); z-index: 2147483646; display: none; min-width: 360px; font: 13px/1.5 system-ui; color: #111; }
#kau-site-link-panel.open { display: block; }
#kau-site-link-panel h4 { margin: 0 0 8px; font-size: 14px; }
#kau-site-link-panel .hint { color: #666; font-size: 11.5px; margin: 0 0 8px; line-height: 1.5; }
#kau-site-link-panel input { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 5px; margin: 4px 0 12px; box-sizing: border-box; font: 13px monospace; }
#kau-site-link-panel .row { display: flex; gap: 8px; justify-content: flex-end; }
#kau-site-link-panel button { padding: 7px 14px; border: 0; border-radius: 5px; cursor: pointer; font: 600 13px system-ui; }
#kau-site-link-panel button.primary { background: #d4a574; color: #fff; }
#kau-site-image-panel { position: fixed; bottom: 82px; right: 20px; background: #fff; border: 1px solid #ddd; padding: 14px; border-radius: 8px; box-shadow: 0 6px 24px rgba(0,0,0,.18); z-index: 2147483646; display: none; max-height: calc(100vh - 110px); overflow-y: auto; width: 360px; font: 13px/1.4 system-ui; color: #111; }
#kau-site-image-panel.open { display: block; }
#kau-site-image-panel h4 { margin: 0 0 10px; font-size: 14px; }
#kau-site-image-panel .img-item { display: flex; gap: 10px; padding: 8px; border: 1px solid #eee; border-radius: 6px; margin-bottom: 8px; align-items: center; }
#kau-site-image-panel .img-item img { width: 60px; height: 60px; object-fit: contain; background: #f6f7f7; border-radius: 4px; }
#kau-site-image-panel .img-item .meta { flex: 1; min-width: 0; }
#kau-site-image-panel .img-item .label { font-weight: 600; font-size: 12px; }
#kau-site-image-panel .img-item .src { font-size: 10.5px; color: #777; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace; }
#kau-site-image-panel .img-item button { background: #d4a574; color: #fff; border: 0; padding: 6px 10px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; }
/* 文字元素拖拉縮放（點工具列 ↔ 後啟用） — 解除父容器 max-width 限制讓 user 可拉到任意大 */
.kau-edit-text.kau-resizable { resize: both !important; overflow: auto !important; outline: 2px solid #d4a574 !important; outline-offset: 2px; min-width: 80px; min-height: 30px; max-width: none !important; max-height: none !important; padding: 2px; box-sizing: border-box; position: relative; }
/* 把瀏覽器原生右下角拉手換成明顯的金色三角 + 加個提示 */
.kau-edit-text.kau-resizable::-webkit-resizer { background: linear-gradient(135deg, transparent 50%, #d4a574 50%); border-bottom-right-radius: 4px; }
.kau-edit-text.kau-resizable::after { content: '↘ 拉這裡'; position: absolute; right: 4px; bottom: 4px; background: #d4a574; color: #111; font: 700 10px/1 system-ui; padding: 3px 6px; border-radius: 4px; pointer-events: none; z-index: 10; }
/* 浮動文字工具列（調字級）*/
#kau-text-toolbar { position: absolute; display: none; z-index: 2147483645; background: #111; color: #fff; padding: 6px 8px; border-radius: 8px; gap: 4px; align-items: center; box-shadow: 0 6px 20px rgba(0,0,0,.35); font: 12px/1 system-ui; }
#kau-text-toolbar.open { display: inline-flex; }
#kau-text-toolbar button { background: rgba(255,255,255,.1); color: #fff; border: 0; width: 30px; height: 28px; border-radius: 5px; cursor: pointer; font: 700 14px/1 inherit; transition: background .15s; }
#kau-text-toolbar button:hover { background: var(--kau-gold, #d4a574); color: #111; }
#kau-text-toolbar .kau-tb-size { background: #222; padding: 0 10px; height: 28px; display: inline-flex; align-items: center; border-radius: 5px; font: 700 12px/1 monospace; min-width: 56px; justify-content: center; }
#kau-text-toolbar .sep { width: 1px; height: 18px; background: rgba(255,255,255,.18); margin: 0 4px; }
/* 區塊（section）刪除按鈕 */
/* 不強制 position — 否則會把原本 position:fixed 的 nav 變成 relative 露出白底；JS 內只在 static 時才補 relative */
.kau-edit-block::before { content: ''; position: absolute; inset: 0; pointer-events: none; outline: 2px dashed transparent; outline-offset: -4px; transition: outline-color .15s; z-index: 1; }
.kau-edit-block:hover::before { outline-color: rgba(220,38,38,.5); }
/* 媒體庫開啟時：藏所有編輯器 UI 避免擋住關閉鈕 */
body.kau-media-open #kau-site-toolbar,
body.kau-media-open #kau-site-image-panel,
body.kau-media-open #kau-site-link-panel,
body.kau-media-open #kau-text-toolbar,
body.kau-media-open .kau-block-tools,
body.kau-media-open .kau-block-scale-popup { display: none !important; }
/* 確保 media modal 永遠在最頂層；backdrop 要在 modal 下面，不能同 z-index 否則蓋住整個內容 */
.media-modal-backdrop { z-index: 159900 !important; }
.media-modal { z-index: 160000 !important; }
.media-modal-close { z-index: 160001 !important; }

.kau-block-tools { position: absolute; top: 12px; right: 12px; z-index: 2147483645; display: flex; gap: 6px; opacity: 0; transition: opacity .15s; }
.kau-edit-block:hover .kau-block-tools { opacity: 1; }
.kau-block-tools button { border: 0; padding: 7px 11px; border-radius: 6px; font: 600 12px/1 system-ui; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.3); display: inline-flex; align-items: center; gap: 4px; }
.kau-block-del { background: #dc2626; color: #fff; }
.kau-block-del:hover { background: #b91c1c; }
.kau-block-scale { background: #111; color: #fff; }
.kau-block-scale:hover { background: var(--kau-gold, #d4a574); color: #111; }
.kau-block-scale-popup { position: absolute; top: 44px; right: 0; background: #111; color: #fff; padding: 8px; border-radius: 8px; display: none; gap: 4px; align-items: center; box-shadow: 0 6px 20px rgba(0,0,0,.35); font: 12px/1 monospace; }
.kau-block-scale-popup.open { display: inline-flex; }
.kau-block-scale-popup button { background: rgba(255,255,255,.1); color: #fff; border: 0; width: 28px; height: 28px; border-radius: 5px; cursor: pointer; font: 700 14px/1 system-ui; }
.kau-block-scale-popup button:hover { background: var(--kau-gold, #d4a574); color: #111; }
.kau-block-scale-popup .val { padding: 0 8px; min-width: 50px; text-align: center; font-weight: 700; }
/* 已調過的區塊：右上角小提示 */
.kau-edit-block[data-kau-zoom]::after { content: 'Zoom ' attr(data-kau-zoom); position: absolute; top: 12px; left: 12px; background: rgba(212,165,116,.95); color: #111; font: 700 10px/1 monospace; padding: 4px 8px; border-radius: 4px; z-index: 2; pointer-events: none; }
/* 子項目刪除鈕 — 小尺寸，hover 才顯示 */
.kau-item-del { position: absolute !important; top: 6px; right: 6px; z-index: 2147483644; width: 24px; height: 24px; padding: 0; border: 0; border-radius: 50%; background: rgba(220,38,38,.92); color: #fff; font: 700 12px/24px system-ui; cursor: pointer; opacity: 0; transition: opacity .12s; box-shadow: 0 2px 6px rgba(0,0,0,.4); }
[data-kau-item]:hover > .kau-item-del { opacity: 1; }
.kau-item-del:hover { background: #b91c1c; transform: scale(1.1); }
/* 新版區塊縮放 popup：高度/寬度兩排，文字大小不受影響 */
.kau-block-scale-popup .axis-row { display: flex; gap: 4px; align-items: center; }
.kau-block-scale-popup .axis-label { color: #d4a574; font: 600 10px/1 system-ui; min-width: 32px; }
CSS;
}

function kau_site_editor_js(): string {
    return <<<'JS'
(function(){
"use strict";
var cfg = window.KAU_SITE_CFG || {};
var dirty = false;
var selectedLink = null;

function $(sel, root){ return (root||document).querySelector(sel); }
function $$(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

function status(msg){ var el = $('#kau-status'); if (el) el.textContent = msg; }
function markDirty(){ dirty = true; status('未儲存的變更'); }
function isInEditor(el){ return !!(el && el.closest && el.closest('#kau-site-toolbar, #kau-site-link-panel, #kau-site-image-panel, #kau-text-toolbar, #kau-ve-sidebar, .media-modal, .media-modal-backdrop, .media-frame, .ui-autocomplete, .kau-block-del, .kau-block-scale, .kau-block-scale-popup, .kau-block-tools, .kau-item-del')); }
function isEditableImageTarget(el){
  if (!el || isInEditor(el)) return false;
  var tag = (el.tagName || '').toLowerCase();
  if (tag !== 'img' && tag !== 'image-slot') return false;
  if (tag === 'img' && el.closest('image-slot')) return false;
  if (el.closest('.show-b, .gcard, .pcard, .news-card, #grid, #nlist, [data-kau-list], [data-kau-item]')) return false;
  var src = el.getAttribute('src') || '';
  if (/^chrome-extension:|^moz-extension:|^about:|^blob:/i.test(src)) return false;
  if (el.closest('.media-modal,.media-frame,#wpadminbar,#wpcom-toolbar,#kau-site-toolbar,#kau-site-image-panel,#kau-ve-sidebar')) return false;
  return true;
}
function imageTargetSrc(el){
  if (!el) return '';
  return el.getAttribute('src') || el.getAttribute('data-src') || el.getAttribute('placeholder') || '';
}
function imageTargetPath(el){
  if (!el) return '';
  return el.getAttribute('data-kau-media') || el.dataset.kauStaticMedia || '';
}
function imageTargetLabel(el, index){
  var path = imageTargetPath(el);
  var id = el.id ? '#' + el.id : '';
  var map = {
    'home.nav.logo': '導覽 Logo',
    'home.hero.image': 'Hero 主視覺',
    'home.hero.bg': 'Hero 主視覺',
    'home.feature.image': 'Signature 圖片',
    'home.cta.image': 'CTA 背景',
    'home.cta.bg': 'CTA 背景',
    'home.footer.logo': 'Footer Logo',
    'about.craft.image': 'Our Craft 圖片',
    'about.access.map_image': 'Access 地圖',
    'products.banner.image': 'Banner 圖片',
    'news.featured.image': '精選最新情報圖片'
  };
  if (path && map[path]) return (id ? id + ' · ' : '') + map[path];
  if (path) return (id ? id + ' · ' : '') + path;
  if (id) return id;
  if (el.alt) return el.alt;
  return (el.tagName || 'IMG').toLowerCase() + ' ' + (index + 1);
}
function collectEditableImages(){
  var seen = {};
  return $$('img, image-slot').filter(isEditableImageTarget).filter(function(el, index){
    var key = imageTargetPath(el) || (el.tagName + '|' + (el.id || '') + '|' + imageTargetSrc(el));
    if (seen[key]) return false;
    seen[key] = true;
    return true;
  });
}
function cleanupImageSlotChildren(root){
  $$('image-slot > img', root || document).forEach(function(img){ img.remove(); });
}

function isEditorChromeNode(el){
  if (!el || !el.id && !el.classList) return false;
  var ids = {
    'kau-ve-sidebar': 1,
    'kau-site-toolbar': 1,
    'kau-site-link-panel': 1,
    'kau-site-image-panel': 1,
    'kau-text-toolbar': 1,
    'kau-site-editor-css': 1,
    'wpadminbar': 1,
    'wpcom-toolbar': 1,
    'wpcom-masterbar': 1
  };
  if (ids[el.id]) return true;
  if (/^(SCRIPT|STYLE|LINK|META|TEMPLATE)$/i.test(el.tagName || '')) return true;
  return !!(el.classList && (
    el.classList.contains('media-modal') ||
    el.classList.contains('media-modal-backdrop') ||
    el.classList.contains('media-frame')
  ));
}

function restorePreviewLayout(root){
  $$( '[data-kau-ve-shifted]', root || document ).forEach(function(el){
    var original = el.getAttribute('data-kau-ve-shift-style');
    if (original) el.setAttribute('style', original);
    else el.removeAttribute('style');
    el.removeAttribute('data-kau-ve-shifted');
    el.removeAttribute('data-kau-ve-shift-style');
  });
}

function applyPreviewLayout(offset){
  restorePreviewLayout(document);
  document.body.style.setProperty('--kau-ve-sidebar-offset', offset, 'important');
  Array.from(document.body.children || []).forEach(function(el){
    if (isEditorChromeNode(el)) return;
    el.setAttribute('data-kau-ve-shifted', '1');
    el.setAttribute('data-kau-ve-shift-style', el.getAttribute('style') || '');
    var cs = getComputedStyle(el);
    el.style.setProperty('box-sizing', 'border-box', 'important');
    if (cs.position === 'fixed' || cs.position === 'sticky') {
      el.style.setProperty('left', offset, 'important');
      el.style.setProperty('right', '0', 'important');
      el.style.setProperty('width', 'auto', 'important');
      el.style.setProperty('max-width', 'calc(100% - ' + offset + ')', 'important');
    } else {
      el.style.setProperty('margin-left', offset, 'important');
      el.style.setProperty('width', 'calc(100% - ' + offset + ')', 'important');
      el.style.setProperty('max-width', 'calc(100% - ' + offset + ')', 'important');
    }
  });
}

function dedupeToolbar(){
  // 刪除舊版本可能殘留的所有 toolbar / panel
  ['#kau-site-toolbar','#kau-site-link-panel','#kau-site-image-panel','#kau-ve-toolbar','#kau-ve-link-panel','#kau-site-enter'].forEach(function(sel){
    $$(sel).forEach(function(n){ n.remove(); });
  });
}

function buildToolbar(){
  dedupeToolbar();
  cleanupImageSlotChildren();
  var bar = document.createElement('div');
  bar.id = 'kau-site-toolbar';
  bar.innerHTML =
    '<strong>KAU 編輯器</strong>' +
    '<button type="button" class="primary" id="kau-save">💾 儲存</button>' +
    '<button type="button" id="kau-image">📷 圖片</button>' +
    '<button type="button" id="kau-link-btn">🔗 編輯連結</button>' +
    '<span class="kau-page-switch" id="kau-page-switch"></span>' +
    '<a id="kau-exit" href="'+(cfg.viewUrl||location.pathname)+'">離開</a>' +
    '<span class="status" id="kau-status">點文字改字 · 點圖片換圖 · 點連結改網址</span>';
  document.body.appendChild(bar);
  var switcher = $('#kau-page-switch');
  if (switcher) {
    [
      ['home', '首頁'],
      ['about', '会社概要'],
      ['products', '製品情報'],
      ['news', '最新情報']
    ].forEach(function(item){
      var key = item[0], label = item[1];
      var href = (cfg.pageEditUrls && cfg.pageEditUrls[key]) || null;
      if (!href) return;
      var a = document.createElement('a');
      a.href = href;
      a.textContent = label;
      if (key === cfg.pageKey) a.className = 'active';
      a.addEventListener('click', function(e){
        if (dirty && !confirm('目前頁面有未儲存的變更，要先離開嗎？')) e.preventDefault();
      });
      switcher.appendChild(a);
    });
  }

  var lp = document.createElement('div');
  lp.id = 'kau-site-link-panel';
  lp.innerHTML =
    '<h4>編輯連結網址</h4>' +
    '<p class="hint">同站頁面：<code>/products/</code>、<code>/about/</code>、<code>/news/</code><br>外部網址：<code>https://...</code><br>無連結：<code>#</code></p>' +
    '<input type="text" id="kau-link-url" autocomplete="off" spellcheck="false">' +
    '<div class="row">' +
      '<button type="button" id="kau-link-cancel">取消</button>' +
      '<button type="button" class="primary" id="kau-link-apply">套用</button>' +
    '</div>';
  document.body.appendChild(lp);

  var ip = document.createElement('div');
  ip.id = 'kau-site-image-panel';
  ip.innerHTML = '<h4>頁面所有圖片</h4><p class="hint">點「換圖」上傳新圖片（含 hero 大背景圖）</p><div id="kau-img-list"></div>';
  document.body.appendChild(ip);

  // 浮動文字工具列（字級調整）
  var tt = document.createElement('div');
  tt.id = 'kau-text-toolbar';
  tt.innerHTML =
    '<button type="button" id="kau-tb-smaller" title="縮小 (-2px)">A−</button>' +
    '<span class="kau-tb-size" id="kau-tb-size">—</span>' +
    '<button type="button" id="kau-tb-bigger" title="放大 (+2px)">A+</button>' +
    '<span class="sep"></span>' +
    '<button type="button" id="kau-tb-resize" title="切換拖拉縮放（從元素右下角拉）">↔</button>' +
    '<button type="button" id="kau-tb-reset" title="還原預設" style="width:auto;padding:0 8px">↺</button>';
  document.body.appendChild(tt);

  $('#kau-save').addEventListener('click', save);
  $('#kau-image').addEventListener('click', toggleImagePanel);
  $('#kau-link-btn').addEventListener('click', openLinkPanel);
  $('#kau-link-cancel').addEventListener('click', function(){ lp.classList.remove('open'); });
  $('#kau-link-apply').addEventListener('click', applyLink);

  // 字級調整 handlers
  function bumpSize(delta){
    if (!currentTextEl) return;
    var cs = parseFloat(getComputedStyle(currentTextEl).fontSize) || 16;
    var next = Math.max(8, Math.min(120, Math.round(cs + delta)));
    currentTextEl.style.fontSize = next + 'px';
    $('#kau-tb-size').textContent = next + 'px';
    markDirty();
    positionTextToolbar();
  }
  $('#kau-tb-smaller').addEventListener('mousedown', function(e){ e.preventDefault(); bumpSize(-2); });
  $('#kau-tb-bigger').addEventListener('mousedown', function(e){ e.preventDefault(); bumpSize(2); });
  $('#kau-tb-reset').addEventListener('mousedown', function(e){
    e.preventDefault();
    if (!currentTextEl) return;
    currentTextEl.style.fontSize = '';
    currentTextEl.style.width = '';
    currentTextEl.style.height = '';
    currentTextEl.classList.remove('kau-resizable');
    currentTextEl.classList.remove('kau-user-sized-text');
    var cs = parseFloat(getComputedStyle(currentTextEl).fontSize) || 16;
    $('#kau-tb-size').textContent = Math.round(cs) + 'px';
    markDirty();
    positionTextToolbar();
  });
  $('#kau-tb-resize').addEventListener('mousedown', function(e){
    e.preventDefault();
    if (!currentTextEl) return;
    currentTextEl.classList.toggle('kau-resizable');
    var enabled = currentTextEl.classList.contains('kau-resizable');
    if (enabled) {
      // 鎖定當下尺寸給 resize 起點
      var r = currentTextEl.getBoundingClientRect();
      currentTextEl.style.width = Math.round(r.width) + 'px';
      currentTextEl.style.height = Math.round(r.height) + 'px';
      // 訪客頁仍要尊重拖拉寬度；100% 上限可避免窄螢幕溢出父容器。
      currentTextEl.classList.add('kau-user-sized-text');
      status('拖元素右下角即可縮放');
    } else {
      // 關閉時保留尺寸（已是 inline style）
      status('縮放關閉');
    }
    markDirty();
    positionTextToolbar();
  });
}

var currentTextEl = null;
function positionTextToolbar(){
  var tb = $('#kau-text-toolbar'); if (!tb || !currentTextEl) return;
  var r = currentTextEl.getBoundingClientRect();
  var top = window.scrollY + r.top - 44;
  if (top < window.scrollY + 8) top = window.scrollY + r.bottom + 8;
  tb.style.top = top + 'px';
  tb.style.left = (window.scrollX + r.left) + 'px';
}
function showTextToolbar(el){
  currentTextEl = el;
  var tb = $('#kau-text-toolbar'); if (!tb) return;
  var cs = parseFloat(getComputedStyle(el).fontSize) || 16;
  $('#kau-tb-size').textContent = Math.round(cs) + 'px';
  tb.classList.add('open');
  positionTextToolbar();
}
function hideTextToolbar(){
  var tb = $('#kau-text-toolbar'); if (tb) tb.classList.remove('open');
  currentTextEl = null;
}
// 聚焦可編輯文字 → 顯示工具列
document.addEventListener('focusin', function(e){
  if (e.target && e.target.classList && e.target.classList.contains('kau-edit-text')) {
    showTextToolbar(e.target);
  }
});
document.addEventListener('focusout', function(e){
  setTimeout(function(){
    if (document.activeElement && document.activeElement.closest && document.activeElement.closest('#kau-text-toolbar')) return;
    if (document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('kau-edit-text')) return;
    hideTextToolbar();
  }, 50);
});
window.addEventListener('scroll', positionTextToolbar, { passive: true });
window.addEventListener('resize', positionTextToolbar);

function makeEditable(){
  // 文字節點 — 含 div（純文字葉節點），由下方結構性子元素檢查過濾掉容器
  var textSelectors = 'h1,h2,h3,h4,h5,h6,p,span,div,a,button,li,dt,dd,em,strong,small,td,th,[data-kau-edit]';
  $$(textSelectors).forEach(function(el){
    if (isInEditor(el)) return;
    var ownPath = el.getAttribute('data-kau-edit') || el.dataset.kauStaticPath || '';
    if (el.querySelector('[data-kau-edit]') && (!ownPath || /^(home\.(hero|cta)\.title)$/.test(ownPath))) {
      el.classList.remove('kau-edit-text');
      el.removeAttribute('contenteditable');
      el.removeAttribute('spellcheck');
      return;
    }
    if (!ownPath && el.querySelector('a,button')) return;
    if (el.classList.contains('kau-edit-text')) return; // 已處理過
    // 有「結構性」子元素就跳過（容器）；svg / img / image-slot 視為裝飾，允許編輯
    if (el.querySelector('div,section,article,header,footer,nav,ul,ol,figure,table,form')) return;
    if (!el.textContent.trim()) return;
    // 避免抓到 .kau-block-del / 已隱藏元素
    if (el.classList.contains('kau-block-del')) return;
    el.classList.add('kau-edit-text');
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'false');
    el.addEventListener('input', markDirty);
    // 貼上：擋掉預設行為（避免帶 HTML style），只插純文字
    el.addEventListener('paste', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      var text = (ev.clipboardData || window.clipboardData).getData('text/plain') || '';
      // 用 Selection API 在 caret 位置插入（execCommand 部分瀏覽器會 fallback 預設行為造成翻倍）
      var sel = window.getSelection();
      if (!sel || sel.rangeCount === 0) return;
      var range = sel.getRangeAt(0);
      range.deleteContents();
      range.insertNode(document.createTextNode(text));
      range.collapse(false);
      sel.removeAllRanges();
      sel.addRange(range);
      markDirty();
    });
  });

  // 圖片（img + image-slot）— 但排除「從資料動態渲染的」精選 / 商品列表 / 新聞列表圖
  // 這些圖換了會被下次 cms-content.js 拉資料時蓋掉，要改去 商品管理 / 最新情報 設定
  var DYNAMIC_IMG_PARENTS = '.show-b, .gcard, .pcard, .news-card, [data-kau-list], [data-kau-item]';
  $$('img, image-slot').forEach(function(el){
    if (!isEditableImageTarget(el)) return;
    if (isInEditor(el)) return;
    if (el.closest(DYNAMIC_IMG_PARENTS)) return; // 動態圖：跳過
    el.classList.add('kau-edit-img');
    if (!el.querySelector('.kau-img-badge')) {
      try {
        var badge = document.createElement('span');
        badge.className = 'kau-img-badge';
        badge.textContent = '📷';
        el.appendChild(badge);
      } catch(e) {}
    }
    el.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      pickImage(el);
    }, true);
  });

  // 貼上時強制純文字 — 直接 attach 到元素本身（document-level 的 preventDefault 不保證擋住瀏覽器預設動作，會造成貼上重複）

  // 每個 [data-kau-item] 子項目右上加 ✕ 小刪除鈕
  $$('[data-kau-item]').forEach(function(el){
    if (isInEditor(el)) return;
    if (el.closest('#kau-site-toolbar, #kau-site-link-panel, #kau-site-image-panel')) return;
    if (el.querySelector(':scope > .kau-item-del')) return;
    var cs = getComputedStyle(el);
    if (cs.position === 'static') el.style.position = 'relative';
    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'kau-item-del';
    del.textContent = '✕';
    del.title = '刪除這個項目';
    del.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      if (!confirm('刪除這個項目？\n（可按離開不存檔來反悔）')) return;
      el.parentNode && el.parentNode.removeChild(el);
      dirty = true;
      status('✓ 已刪除項目，記得按儲存');
    });
    el.appendChild(del);
  });

  // 區塊工具：每個 [data-kau-block] 右上角加 ✕刪除 + ↕縮放
  $$('[data-kau-block]').forEach(function(el){
    if (isInEditor(el)) return;
    if (el.querySelector('.kau-block-tools')) return;
    var cs = getComputedStyle(el);
    if (cs.position === 'static') el.style.position = 'relative';
    el.classList.add('kau-edit-block');

    var tools = document.createElement('div');
    tools.className = 'kau-block-tools';

    // 縮放按鈕
    var scaleBtn = document.createElement('button');
    scaleBtn.type = 'button';
    scaleBtn.className = 'kau-block-scale';
    scaleBtn.textContent = '↕ 縮放';
    scaleBtn.title = '縮小/放大整個區塊';

    var popup = document.createElement('div');
    popup.className = 'kau-block-scale-popup';
    popup.innerHTML =
      '<div class="axis-row"><span class="axis-label">高度</span><button type="button" data-axis="h" data-d="-1">−</button><span class="val val-h">—</span><button type="button" data-axis="h" data-d="1">+</button></div>' +
      '<div class="axis-row"><span class="axis-label">寬度</span><button type="button" data-axis="w" data-d="-1">−</button><span class="val val-w">—</span><button type="button" data-axis="w" data-d="1">+</button></div>' +
      '<button type="button" data-act="r" title="還原">↺</button>';

    function readPad(){
      var cs = getComputedStyle(el);
      return { py: parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom), mw: parseFloat(cs.maxWidth) || el.getBoundingClientRect().width };
    }
    function refresh(){
      // 偏移以「自訂值」為主；沒設過顯示「—」
      popup.querySelector('.val-h').textContent = el.dataset.kauPadY ? el.dataset.kauPadY + 'px' : '—';
      popup.querySelector('.val-w').textContent = el.dataset.kauMaxW ? el.dataset.kauMaxW + 'px' : '—';
    }

    scaleBtn.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      $$('.kau-block-scale-popup.open').forEach(function(p){ if (p !== popup) p.classList.remove('open'); });
      popup.classList.toggle('open');
      refresh();
    });

    popup.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      var t = e.target;
      if (t.dataset && t.dataset.act === 'r') {
        el.style.paddingTop = ''; el.style.paddingBottom = ''; el.style.maxWidth = ''; el.style.margin = '';
        delete el.dataset.kauPadY; delete el.dataset.kauMaxW;
        refresh(); markDirty(); return;
      }
      var axis = t.dataset && t.dataset.axis; var d = parseFloat(t.dataset && t.dataset.d || 0); if (!axis || !d) return;
      if (axis === 'h') {
        var cur = parseFloat(el.dataset.kauPadY || (parseFloat(getComputedStyle(el).paddingTop) || 40));
        var next = Math.max(0, Math.min(300, cur + d * 8));
        el.style.paddingTop = next + 'px'; el.style.paddingBottom = next + 'px';
        el.dataset.kauPadY = String(next);
      } else if (axis === 'w') {
        var box = el.parentElement ? el.parentElement.getBoundingClientRect().width : 1600;
        var curW = parseFloat(el.dataset.kauMaxW || box);
        var nextW = Math.max(320, Math.min(2400, curW + d * 40));
        el.style.maxWidth = nextW + 'px'; el.style.marginLeft = 'auto'; el.style.marginRight = 'auto';
        el.dataset.kauMaxW = String(nextW);
      }
      refresh(); markDirty();
    });

    // 刪除按鈕
    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'kau-block-del';
    del.textContent = '✕ 刪除';
    del.title = '刪除此區：' + (el.getAttribute('data-kau-block') || '');
    del.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      var name = el.getAttribute('data-kau-block') || '此區塊';
      if (!confirm('確定刪除「' + name + '」整個區塊？\n（按離開不存檔可反悔）')) return;
      el.parentNode && el.parentNode.removeChild(el);
      dirty = true;
      status('✓ 已刪除「' + name + '」，記得按儲存');
    });

    tools.appendChild(scaleBtn);
    tools.appendChild(popup);
    tools.appendChild(del);
    el.appendChild(tools);
  });

  // 全域點擊攔截
  document.addEventListener('click', function(e){
    if (isInEditor(e.target)) return;

    var a = e.target.closest && e.target.closest('a[href]');
    var isEditableText = e.target.classList && e.target.classList.contains('kau-edit-text');
    var isImg = e.target.classList && e.target.classList.contains('kau-edit-img');

    // 圖片：交給 pickImage handler 處理（已 attached）
    if (isImg) { return; }

    if (a) {
      // 標記為當前選中連結（之後按「編輯連結」可改 URL）
      selectedLink = a;
      $$('.kau-link-selected').forEach(function(n){ n.classList.remove('kau-link-selected'); });
      a.classList.add('kau-link-selected');

      // 點到可編輯文字 → 擋連結跳轉，手動 focus 讓 contenteditable 可打字
      if (isEditableText) {
        e.preventDefault();
        e.stopPropagation();
        var target = e.target.classList.contains('kau-edit-text') ? e.target : a;
        try { target.focus(); } catch(_) {}
        // 把游標放到點擊位置（caretPositionFromPoint / caretRangeFromPoint）
        try {
          var sel = window.getSelection();
          var range = document.caretRangeFromPoint ? document.caretRangeFromPoint(e.clientX, e.clientY) : null;
          if (!range && document.caretPositionFromPoint) {
            var p = document.caretPositionFromPoint(e.clientX, e.clientY);
            if (p) { range = document.createRange(); range.setStart(p.offsetNode, p.offset); range.collapse(true); }
          }
          if (range) { sel.removeAllRanges(); sel.addRange(range); }
        } catch(_) {}
        status('文字編輯中（要改 URL 請按下方「編輯連結」）');
        return;
      }

      // 點到連結本體（不是文字）→ 擋跳轉、開 URL 編輯
      e.preventDefault();
      e.stopPropagation();
      openLinkPanel();
      return;
    }

    // 阻止所有按鈕跳轉
    var btn = e.target.closest && e.target.closest('button');
    if (btn) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // 阻止表單送出
  document.addEventListener('submit', function(e){ if (!isInEditor(e.target)) { e.preventDefault(); e.stopPropagation(); } }, true);
}

function openLinkPanel(){
  if (!selectedLink) { status('請先點選一個連結'); return; }
  var input = $('#kau-link-url');
  input.value = selectedLink.getAttribute('href') || '';
  $('#kau-site-image-panel').classList.remove('open');
  $('#kau-site-link-panel').classList.add('open');
  input.focus();
  input.select();
}
function applyLink(){
  if (!selectedLink) return;
  var v = $('#kau-link-url').value || '#';
  // 站內頁面連結正規化：products.html / http://products.html → /products.html
  // （曾發生使用者輸入被存成 http://products.html，瀏覽器會把它當網域導致 404）
  var m = v.match(/^(?:https?:\/\/)?\/?(home|about|products|news)\.html\/?$/i);
  if (m) v = '/' + m[1].toLowerCase() + '.html';
  selectedLink.setAttribute('href', v);
  $('#kau-site-link-panel').classList.remove('open');
  status('已更新連結：' + v + '（記得儲存）');
  markDirty();
}

function toggleImagePanel(){
  var panel = $('#kau-site-image-panel');
  if (panel.classList.contains('open')) { panel.classList.remove('open'); return; }
  $('#kau-site-link-panel').classList.remove('open');
  // 列出所有圖片
  var list = $('#kau-img-list');
  list.innerHTML = '';
  collectEditableImages().forEach(function(el, i){
    el.classList.add('kau-edit-img');
    var src = imageTargetSrc(el);
    var tag = el.tagName.toLowerCase();
    var label = imageTargetLabel(el, i);
    var thumb = (tag === 'img' && src) ? '<img src="'+src+'">' : '<div style="width:60px;height:60px;background:#eee;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:20px">🖼</div>';
    var item = document.createElement('div');
    item.className = 'img-item';
    item.innerHTML = thumb + '<div class="meta"><div class="label">'+label+'</div><div class="src">'+(src||'(尚無圖片)')+'</div></div><button type="button">換圖</button>';
    item.querySelector('button').addEventListener('click', function(){ pickImage(el); panel.classList.remove('open'); el.scrollIntoView({behavior:'smooth',block:'center'}); });
    list.appendChild(item);
  });
  panel.classList.add('open');
}

function applyImageUrl(target, url){
  if (!url) return;
  if (target.tagName.toLowerCase() === 'image-slot') {
    target.setAttribute('src', url);
    target.querySelectorAll('img').forEach(function(img){ img.remove(); });
  } else {
    target.src = url;
    target.removeAttribute('srcset');
  }
  target.setAttribute('data-kau-media-dirty', '1');
  markDirty();
  status('✓ 圖片已替換，記得按儲存');
}

var kauMediaFrame = null;
var kauMediaTarget = null;
function pickImage(target){
  // 優先使用 WordPress 媒體庫
  if (window.wp && window.wp.media) {
    kauMediaTarget = target;
    // 全域單例：避免每次點圖都新開一個 modal，導致疊很多層 → 關不完
    if (!kauMediaFrame) {
      kauMediaFrame = wp.media({
        title: '從媒體庫選擇圖片',
        button: { text: '使用這張圖片' },
        multiple: false,
        library: { type: 'image' }
      });
      var hide = function(){ document.body.classList.add('kau-media-open'); };
      var restore = function(){ document.body.classList.remove('kau-media-open'); };
      kauMediaFrame.on('open', hide);
      kauMediaFrame.on('close', restore);
      kauMediaFrame.on('escape', function(){ try { kauMediaFrame.close(); } catch(_) {} restore(); });
      kauMediaFrame.on('select', function(){
        var file = kauMediaFrame.state().get('selection').first().toJSON();
        if (kauMediaTarget) applyImageUrl(kauMediaTarget, file.url);
      });
      // 全域 ESC 一次性綁定
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && document.body.classList.contains('kau-media-open')) {
          try { kauMediaFrame.close(); } catch(_) {}
          restore();
        }
      });
    }
    kauMediaFrame.open();
    return;
  }

  // Fallback：直接上傳（媒體庫沒載入時）
  var input = document.createElement('input');
  input.type = 'file'; input.accept = 'image/*';
  input.onchange = function(){
    var file = input.files && input.files[0]; if (!file) return;
    var fd = new FormData();
    fd.append('action', 'kau_site_upload');
    fd.append('nonce', cfg.nonce);
    fd.append('file', file);
    status('上傳中...');
    fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.success) throw new Error((data && data.data && data.data.message) || '上傳失敗');
        applyImageUrl(target, data.data.url);
      })
      .catch(function(err){ alert('上傳失敗：' + (err.message || '')); status('✗ 上傳失敗'); });
  };
  input.click();
}

function cleanForSave(){
  var doc = document.documentElement.cloneNode(true);
  cleanupImageSlotChildren(doc);
  doc.querySelectorAll('[data-kau-media-dirty]').forEach(function(el){ el.removeAttribute('data-kau-media-dirty'); });
  // 編輯器自己的節點
  ['#kau-site-toolbar','#kau-site-link-panel','#kau-site-image-panel','#kau-site-editor-css','#kau-text-toolbar','#kau-ve-sidebar'].forEach(function(sel){
    var n = doc.querySelector(sel); if (n) n.remove();
  });
  // wp.media 媒體庫 modal 的整包 DOM（開過媒體庫後會掛在 body 上；attribute 順序不固定，
  // PHP 端 regex 攔不到的就是這批 → 曾整包被存進 DB，用 DOM 移除最可靠）
  doc.querySelectorAll('.supports-drag-drop, .media-modal, .media-modal-backdrop, [id^="__wp-uploader-id"]').forEach(function(n){ n.remove(); });
  // body 上 sidebar 開關 class 不要存到 DB
  var bodyEl = doc.querySelector('body');
  if (bodyEl) {
    bodyEl.classList.remove('kau-ve-has-sidebar');
    bodyEl.classList.remove('kau-ve-sidebar-collapsed');
    bodyEl.style.removeProperty('--kau-ve-sidebar-offset');
    bodyEl.style.paddingLeft = '';
    bodyEl.style.marginLeft = '';
    bodyEl.style.overflowX = '';
  }
  doc.querySelectorAll('[data-kau-ve-shifted]').forEach(function(el){
    var original = el.getAttribute('data-kau-ve-shift-style');
    if (original) el.setAttribute('style', original);
    else el.removeAttribute('style');
    el.removeAttribute('data-kau-ve-shifted');
    el.removeAttribute('data-kau-ve-shift-style');
  });
  // sidebar 在 [data-kau-block] 上殘留的 highlight class
  doc.querySelectorAll('.kau-ve-block-highlight').forEach(function(n){ n.classList.remove('kau-ve-block-highlight'); });
  // sidebar 在文字元素上殘留的 dataset 對應 key
  doc.querySelectorAll('[data-kau-ve-sb-key]').forEach(function(n){ n.removeAttribute('data-kau-ve-sb-key'); });
  doc.querySelectorAll('.kau-edit-text, .kau-edit-img, .kau-link-selected, .kau-resizable').forEach(function(el){
    el.classList.remove('kau-edit-text'); el.classList.remove('kau-edit-img'); el.classList.remove('kau-link-selected'); el.classList.remove('kau-resizable');
    el.removeAttribute('contenteditable'); el.removeAttribute('spellcheck');
  });
  doc.querySelectorAll('.kau-img-badge').forEach(function(n){ n.remove(); });
  // 區塊工具 / 編輯類別（含反向 zoom inline style）
  doc.querySelectorAll('.kau-block-tools, .kau-block-del, .kau-block-scale-popup, .kau-item-del').forEach(function(n){ n.remove(); });
  // 清掉 data-kau-pad-y / data-kau-max-w 屬性（inline padding/maxWidth 已留在 style，不需重複）
  doc.querySelectorAll('[data-kau-pad-y]').forEach(function(n){ n.removeAttribute('data-kau-pad-y'); });
  doc.querySelectorAll('[data-kau-max-w]').forEach(function(n){ n.removeAttribute('data-kau-max-w'); });
  doc.querySelectorAll('.kau-edit-block').forEach(function(n){ n.classList.remove('kau-edit-block'); });
  // nav: scroll JS 會在 scroll>70%vh 時把 on-dark toggle 掉。若編輯時 scroll 過 hero 再存檔，
  // on-dark 會被烤掉，之後永遠沒玻璃感。強制保留首頁 nav 的 on-dark + 清掉編輯期間的 box-shadow inline
  var navEl = doc.querySelector('nav#nav.nav, nav.nav#nav');
  if (navEl && /\/(home\.html)?$/.test(location.pathname)) {
    navEl.classList.add('on-dark');
    if (/box-shadow/.test(navEl.getAttribute('style')||'')) {
      navEl.style.boxShadow = '';
      if (!navEl.getAttribute('style')) navEl.removeAttribute('style');
    }
  }
  // image-slot 被注入的 shadow style 不會被序列化（shadow DOM 不會），但 data-kau-fit 屬性要清
  doc.querySelectorAll('[data-kau-fit]').forEach(function(n){ n.removeAttribute('data-kau-fit'); });

  // 清掉所有我們自己 cms_inject 注入的 link/script/style/meta（避免每次存檔累積）
  doc.querySelectorAll('[id^="kau-cms-"]').forEach(function(n){ n.remove(); });
  // 去重複：把 textContent 完全相同的 inline <style>/<script> 只保留第一個
  var seen = {};
  doc.querySelectorAll('style, script:not([src])').forEach(function(n){
    var t = (n.textContent || '').trim();
    if (!t || t.length < 50) return;
    if (seen[t]) { n.remove(); return; }
    seen[t] = true;
  });
  // 也清掉重複的 inline meta translate / favicon link（萬一同事舊版有殘留）
  ['meta[name="google"][content="notranslate"]','link[rel="icon"]','link[rel="apple-touch-icon"]'].forEach(function(sel){
    var nodes = doc.querySelectorAll(sel);
    for (var i = 1; i < nodes.length; i++) nodes[i].remove();
  });

  // 清掉所有 WordPress / Jetpack / 媒體庫注入的 <link>/<style>/<script>/<template>
  // 否則 admin-bar 的 html{margin-top:32px} 等樣式會被存進 DB，造成訪客模式頂部白條
  var WP_PATTERNS = /(wp-includes|wp-content\/mu-plugins|wpcom|jetpack|admin-bar|media-views|media-editor|media-models|mediaelement|wp-mediaelement|wp-backbone|underscore|dashicons|wpcomsh|page-optimize|stats\.wp\.com|widgets\.wp\.com|gravatar\.com|\/_static\/|chrome-extension|^data:text\/css)/i;
  var WP_IDS = /^(admin-bar|media-|wp-|mediaelement|jetpack|wpcom|all-css|akismet|gutenberg|page-optimize|underscore|dashicons|jquery|utils-js|help-center|grofiles|wpgroho|hovercards|stickynote|gravatar-card)/i;

  doc.querySelectorAll('link[rel="stylesheet"], style, script').forEach(function(n){
    var href = n.getAttribute('href') || n.getAttribute('src') || '';
    var id = n.id || '';
    var txt = n.textContent || '';
    if (WP_PATTERNS.test(href) || WP_PATTERNS.test(id) || WP_IDS.test(id)) { n.remove(); return; }
    // 內聯 style/script：判內容
    if (!href && (WP_PATTERNS.test(txt) || /wpadminbar|wp-admin-bar|wp\.media|KAU_SITE_CFG/i.test(txt))) { n.remove(); return; }
  });
  // 媒體庫的 underscore 模板
  doc.querySelectorAll('script[type="text/html"][id^="tmpl-"]').forEach(function(n){ n.remove(); });
  // dns-prefetch / preload 也清掉
  doc.querySelectorAll('link[rel="dns-prefetch"], link[rel="preload"], link[rel="preconnect"]').forEach(function(n){
    var href = n.getAttribute('href') || '';
    if (WP_PATTERNS.test(href) || /s\.w\.org|s0\.wp\.com|c0\.wp\.com|stats\.wp\.com/.test(href)) n.remove();
  });

  return '<!DOCTYPE html>\n' + doc.outerHTML;
}

function cleanText(el){
  if (!el) return '';
  var clone = el.cloneNode(true);
  clone.querySelectorAll('svg,.en,.mk,.kau-item-del,.kau-block-tools,.kau-block-scale-popup,.kau-img-badge').forEach(function(n){ n.remove(); });
  return (clone.textContent || '').replace(/\s+/g, ' ').trim();
}

function directText(el){
  if (!el) return '';
  var clone = el.cloneNode(true);
  clone.querySelectorAll('svg,.en,.mk,.kau-item-del,.kau-block-tools,.kau-block-scale-popup,.kau-img-badge').forEach(function(n){ n.remove(); });
  return (clone.textContent || '').replace(/\s+/g, ' ').trim();
}

function splitVisualLines(el){
  if (!el) return [];
  var raw = (el.innerText || el.textContent || '').replace(/\r/g, '');
  return raw.split(/\n+/).map(function(v){ return v.trim(); }).filter(Boolean);
}

function isFooterNoteLine(line){
  return /^※/.test(line || '');
}

function syncGlobalFromDom(syncMap){
  var navLinks = document.querySelectorAll('.nav-links a');
  var navKeys = ['home','about','products','news'];
  navKeys.forEach(function(key, index){
    var a = navLinks[index];
    if (!a) return;
    var en = a.querySelector('.en');
    var label = directText(a);
    if (label) syncMap['global.navigation.' + key + '_label'] = label;
    if (en && cleanText(en)) syncMap['global.navigation.' + key + '_en'] = cleanText(en);
  });

  var shopBtn = document.querySelector('.nav-shop-btn');
  if (shopBtn && directText(shopBtn)) syncMap['global.navigation.shop_label'] = directText(shopBtn);
  var navCta = document.querySelector('.nav-cta');
  if (navCta && cleanText(navCta)) syncMap['global.navigation.contact_label'] = cleanText(navCta);
  if (navCta && navCta.getAttribute('href')) syncMap['global.contact_url'] = navCta.getAttribute('href');

  var shopLinks = document.querySelectorAll('.nav-shop-menu a');
  if (shopLinks[0]) {
    if (directText(shopLinks[0])) syncMap['global.shop.amazon_label'] = directText(shopLinks[0]);
    if (shopLinks[0].getAttribute('href')) syncMap['global.amazon_url'] = shopLinks[0].getAttribute('href');
  }
  if (shopLinks[1]) {
    if (directText(shopLinks[1])) syncMap['global.shop.rakuten_label'] = directText(shopLinks[1]);
    if (shopLinks[1].getAttribute('href')) syncMap['global.rakuten_url'] = shopLinks[1].getAttribute('href');
  }

  var cs = document.querySelector('#kau-cs-modal');
  if (cs) {
    var p = cs.querySelectorAll('p');
    if (p[0] && cleanText(p[0])) syncMap['global.shop.coming_soon_label'] = cleanText(p[0]);
    if (p[1] && cleanText(p[1])) syncMap['global.shop.coming_soon_title'] = cleanText(p[1]);
    if (p[2] && cleanText(p[2])) syncMap['global.shop.coming_soon_description'] = cleanText(p[2]);
  }

  var addr = document.querySelector('.footer-addr');
  var lines = splitVisualLines(addr);
  if (lines[0]) syncMap['global.company_name'] = lines[0];
  if (lines[1]) syncMap['global.postal_code'] = lines[1];
  if (lines[2]) syncMap['global.address_line_1'] = lines[2];
  if (lines[3]) syncMap['global.address_line_2'] = lines[3];
  if (lines[4] && isFooterNoteLine(lines[4])) {
    syncMap['global.address_line_3'] = '';
    syncMap['global.note'] = lines.slice(4).join("\n");
  } else {
    syncMap['global.address_line_3'] = lines[4] || '';
    syncMap['global.note'] = lines.slice(5).join("\n");
  }

  var cols = document.querySelectorAll('.footer-col');
  ['products','company','support'].forEach(function(key, index){
    var col = cols[index];
    if (!col) return;
    var h = col.querySelector('h5');
    if (h && cleanText(h)) syncMap['global.footer.' + key + '_title'] = cleanText(h);
    var links = [];
    col.querySelectorAll('a').forEach(function(a){
      var label = cleanText(a);
      if (!label) return;
      links.push({ label: label, url: a.getAttribute('href') || '#' });
    });
    syncMap['global.footer.' + key + '_links'] = links;
  });

  var bottom = document.querySelector('.footer-bottom');
  if (bottom) {
    var spans = bottom.querySelectorAll('span');
    if (spans[0] && cleanText(spans[0])) syncMap['global.footer.copyright'] = cleanText(spans[0]);
    if (spans[1] && cleanText(spans[1])) syncMap['global.footer.suffix'] = cleanText(spans[1]);
  }
}

function save(){
  var btn = $('#kau-save');
  btn.disabled = true;
  status('儲存中...');

  // 同時收集所有動態元素的內容，雙向同步到 kau_site_data_v2
  // 用 data-kau-edit 或 data-kau-static-path（cms_inject 在 cms-content.js 跑前的備份）兩個都接受
  var syncMap = {};
  var ITEM_RE = /\.(item|card|spec|stat|value|principle|profile|history|news|product|cat|nf|fl\d*)\d+\./i;
  var VALUES_RE = /^home\.values\.item\d+\.(title|desc)$/i;
  var VALUES_ICON_RE = /^home\.values\.item\d+\.icon$/i;
  // HTML 內 data-kau-edit path → admin 用的 path 對應表（路徑名不同步問題）
  // 由 PHP 的 kau_site_path_aliases() 注入，跟前台 final-sync 讀的是同一份
  var PATH_ALIASES = window.KAU_PATH_ALIASES || {};
  // 合併標題（line_1 + accent + suffix 拼成 h1）跟 footer 整段文字 — 視覺編輯器無法拆，跳過 sync，請用 admin 改
  var SKIP_SYNC = {};
  (window.KAU_SYNC_SKIP || ['home.hero.title','home.cta.title','home.footer.addr','home.footer.brand']).forEach(function(p){ SKIP_SYNC[p] = 1; });

  document.querySelectorAll('[data-kau-edit],[data-kau-static-path]').forEach(function(el){
    if (isInEditor(el)) return;
    var path = el.getAttribute('data-kau-edit') || el.dataset.kauStaticPath;
    if (!path) return;
    if (VALUES_RE.test(path)) { syncMap[path] = el.textContent.trim(); return; }
    if (ITEM_RE.test(path)) return;
    if (SKIP_SYNC[path]) return;
    if (PATH_ALIASES[path]) path = PATH_ALIASES[path];
    syncMap[path] = el.textContent.trim();
  });
  document.querySelectorAll('[data-kau-media],[data-kau-static-media]').forEach(function(el){
    if (isInEditor(el)) return;
    // 只同步使用者實際替換過的圖片；編輯文字時不要讓頁面快照覆蓋後台的正確媒體網址。
    if (!el.hasAttribute('data-kau-media-dirty')) return;
    var path = el.getAttribute('data-kau-media') || el.dataset.kauStaticMedia;
    if (!path) return;
    var src = el.getAttribute('src') || (el.querySelector && el.querySelector('img') ? el.querySelector('img').src : '');
    if (VALUES_ICON_RE.test(path)) { if (src) syncMap[path] = src; return; }
    if (ITEM_RE.test(path)) return;
    if (src) syncMap[path] = src;
  });
  document.querySelectorAll('[data-kau-link],[data-kau-static-link]').forEach(function(el){
    if (isInEditor(el)) return;
    var path = el.getAttribute('data-kau-link') || el.dataset.kauStaticLink;
    if (!path) return;
    if (ITEM_RE.test(path)) return;
    var href = el.getAttribute('href');
    if (href) syncMap[path + '_url'] = href;
  });
  syncGlobalFromDom(syncMap);

  var html = cleanForSave();
  var fd = new FormData();
  fd.append('action', 'kau_site_save');
  fd.append('nonce', cfg.nonce);
  fd.append('pageKey', cfg.pageKey);
  fd.append('html', html);
  fd.append('syncMap', JSON.stringify(syncMap));
  fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function(r){ return r.text().then(function(t){ try { return JSON.parse(t); } catch(e){ throw new Error('伺服器回應錯誤: ' + t.substring(0,200)); } }); })
    .then(function(data){
      if (!data || !data.success) throw new Error((data && data.data && data.data.message) || JSON.stringify(data));
      dirty = false;
      var synced = (data.data && data.data.synced) ? ' · 同步 ' + data.data.synced + ' 項' : '';
      status('✅ 已儲存（' + (data.data && data.data.size ? Math.round(data.data.size/1024) + 'KB' : '') + synced + '）');
      btn.disabled = false;
    })
    .catch(function(err){
      alert('儲存失敗：\n' + (err.message || '未知錯誤'));
      status('✗ 儲存失敗');
      btn.disabled = false;
    });
}

window.addEventListener('beforeunload', function(e){
  if (dirty) { e.preventDefault(); e.returnValue = ''; }
});

// ─── 左側區塊清單 sidebar ─────────────────────────────────────
var sidebarFieldMap = {};
var BLOCK_LABELS = {
  'home.nav':'首頁 · 導覽列','home.hero':'首頁 · 主視覺 (Hero)','home.intro':'首頁 · 理念 (Philosophy)',
  'home.collection':'首頁 · Collection','home.feature':'首頁 · Signature','home.values':'首頁 · 價值',
  'home.cta':'首頁 · Contact CTA','home.footer':'首頁 · 頁尾',
  'about.hero':'關於 · 主視覺','about.statement':'關於 · Philosophy','about.craft':'關於 · Our Craft',
  'about.overview':'關於 · 会社概要','about.history':'關於 · 沿革','about.access':'關於 · Access','about.footer':'關於 · 頁尾',
  'products.hero':'製品 · 主視覺','products.list':'製品 · 商品列表','products.banner':'製品 · Banner',
  'products.cta':'製品 · 法人 CTA','products.footer':'製品 · 頁尾',
  'news.hero':'最新情報 · 主視覺','news.list':'最新情報 · 列表','news.footer':'最新情報 · 頁尾'
};
function blockLabelFor(id){ return BLOCK_LABELS[id] || id; }
var SIDEBAR_STRUCTURED_BLOCKS = {
  'home.collection': '商品/卡片列表請到對應後台管理，這裡只負責定位區塊。',
  'home.values': '價值項目是重複列表，新增、刪除與排序請到首頁後台管理。',
  'about.overview': '会社概要是重複項目列表，新增、刪除與排序請到会社概要後台管理。',
  'about.history': '沿革是重複項目列表，新增、刪除與排序請到会社概要後台管理。',
  'products.list': '商品列表請到商品管理編輯，這裡只負責定位區塊。',
  'news.list': '最新情報列表請到最新情報後台管理，這裡只負責定位區塊。'
};
function structuredBlockNote(id){ return SIDEBAR_STRUCTURED_BLOCKS[id] || ''; }
function skipSidebarField(el){
  if (!el || !el.closest) return false;
  var ownPath = el.getAttribute('data-kau-edit') || el.dataset.kauStaticPath || '';
  if (el.querySelector('[data-kau-edit]') && (!ownPath || /^(home\.(hero|cta)\.title)$/.test(ownPath))) return true;
  if (!ownPath && el.querySelector('a,button')) return true;
  if (el.classList && el.classList.contains('hero-pan')) return true;
  if (/^📷+$/.test((el.textContent || '').replace(/\s+/g,''))) return true;
  if (el.closest('[data-kau-list], [data-kau-item], .ln')) return true;
  if (el.closest('.footer-col')) return true;
  if (el.classList && el.classList.contains('footer-bottom')) return true;
  return false;
}
function isMultilineText(el){
  if (el && el.classList && el.classList.contains('footer-addr')) return true;
  var t = (el.textContent||'').trim();
  if (t.length > 60) return true;
  if (/\n/.test(t)) return true;
  if (/^(P|TEXTAREA)$/i.test(el.tagName) && t.length > 40) return true;
  return false;
}
function fieldLabelFor(el){
  var path = el.getAttribute && (el.getAttribute('data-kau-edit') || el.dataset.kauStaticPath || '');
  var niceLabels = {
    'home.hero.eyebrow': '主視覺小標',
    'home.hero.line_1': '主標題白字',
    'home.hero.accent': '主標題金色強調字',
    'home.hero.suffix': '主標題後綴',
    'home.hero.subtitle': '主視覺說明文字',
    'home.hero.sub': '主視覺說明文字',
    'home.hero.primary_label': '主要按鈕文字',
    'home.hero.secondary_label': '次要按鈕文字',
    'home.hero.scroll_label': '捲動提示文字',
    'home.cta.line_1': 'CTA 標題白字',
    'home.cta.accent': 'CTA 金色強調字',
    'home.cta.suffix': 'CTA 標題後綴'
  };
  var tag = (el.tagName||'').toUpperCase();
  var cls = (el.className||'').split(/\s+/).filter(function(c){ return c && c.indexOf('kau-')!==0; })[0] || '';
  var text = (el.textContent||'').replace(/\s+/g,' ').trim();
  var hint = text.length > 28 ? text.slice(0,28)+'…' : text;
  if (path && niceLabels[path]) return { tag: niceLabels[path], hint: hint };
  return { tag: tag + (cls ? ' .'+cls : ''), hint: hint };
}
function sidebarTextValue(el){
  if (el && el.classList && el.classList.contains('footer-addr')) {
    return splitVisualLines(el).join("\n");
  }
  return (el.textContent||'').trim();
}
function blockFields(blockEl){
  var blockId = blockEl.getAttribute('data-kau-block') || '';
  if (structuredBlockNote(blockId)) return [];
  var fields = [];
  $$('.kau-edit-text', blockEl).forEach(function(el){
    if (isInEditor(el)) return;
    if (skipSidebarField(el)) return;
    fields.push({ kind:'text', el: el });
  });
  $$('image-slot[id], img.kau-edit-img, img[data-kau-media], image-slot[data-kau-media]', blockEl).forEach(function(el){
    if (isInEditor(el)) return;
    if (skipSidebarField(el)) return;
    if (!isEditableImageTarget(el)) return;
    fields.push({ kind:'media', el: el });
  });
  return fields;
}
function highlightBlock(blockEl, on){
  if (!blockEl) return;
  blockEl.classList.toggle('kau-ve-block-highlight', !!on);
}
function buildSidebarField(blockId, idx, field){
  var wrap = document.createElement('div');
  wrap.className = 'kau-ve-sb-field';
  var key = blockId + ':' + idx;

  if (field.kind === 'text'){
    var meta = fieldLabelFor(field.el);
    var label = document.createElement('div');
    label.className = 'kau-ve-sb-field-label';
    label.innerHTML = '<span class="kau-ve-sb-field-tag"></span><span class="kau-ve-sb-field-hint"></span>';
    label.querySelector('.kau-ve-sb-field-tag').textContent = meta.tag;
    label.querySelector('.kau-ve-sb-field-hint').textContent = meta.hint || '(空白)';
    wrap.appendChild(label);

    var hasChildElement = false;
    for (var i = 0; i < field.el.children.length; i++){
      if (field.el.children[i].nodeType === 1){ hasChildElement = true; break; }
    }

    var multiline = isMultilineText(field.el);
    var input = document.createElement(multiline ? 'textarea' : 'input');
    input.className = multiline ? 'kau-ve-sb-field-textarea' : 'kau-ve-sb-field-input';
    if (!multiline) input.type = 'text';
    input.value = sidebarTextValue(field.el);

    if (field.el.classList && field.el.classList.contains('footer-addr')) {
      input.addEventListener('input', function(){
        field.el.replaceChildren();
        String(input.value || '').split(/\r?\n/).forEach(function(line, lineIndex){
          if (lineIndex) field.el.appendChild(document.createElement('br'));
          field.el.appendChild(document.createTextNode(line));
        });
        wrap.classList.add('is-synced');
        markDirty();
      });
    } else if (hasChildElement){
      input.readOnly = true;
      input.title = '此區塊含樣式標籤，請在右側畫面直接編輯';
      input.style.opacity = '.7';
      input.style.cursor = 'pointer';
      input.addEventListener('focus', function(){ try{ field.el.focus(); }catch(e){} });
    } else {
      input.addEventListener('input', function(){
        field.el.textContent = input.value;
        wrap.classList.add('is-synced');
        markDirty();
      });
    }
    input.addEventListener('focus', function(){
      highlightBlock(field.el.closest('[data-kau-block]'), true);
      try{ field.el.scrollIntoView({behavior:'smooth', block:'center'}); }catch(e){}
    });
    input.addEventListener('blur', function(){
      highlightBlock(field.el.closest('[data-kau-block]'), false);
    });
    wrap.appendChild(input);
    sidebarFieldMap[key] = { input: input, el: field.el, wrap: wrap };
    field.el.dataset.kauVeSbKey = key;
  } else if (field.kind === 'media'){
    var lbl = document.createElement('div');
    lbl.className = 'kau-ve-sb-field-label';
    lbl.innerHTML = '<span class="kau-ve-sb-field-tag">IMG</span><span class="kau-ve-sb-field-hint"></span>';
    lbl.querySelector('.kau-ve-sb-field-hint').textContent = imageTargetLabel(field.el, idx);
    wrap.appendChild(lbl);
    var box = document.createElement('div');
    box.className = 'kau-ve-sb-field-image';
    var src = imageTargetSrc(field.el);
    box.innerHTML = '<div class="thumb"></div><div class="name"></div><span style="font-size:11px;color:#d4a574">換圖</span>';
    if (src) box.querySelector('.thumb').style.backgroundImage = 'url("'+src.replace(/"/g,'\\"')+'")';
    box.querySelector('.name').textContent = src ? src.split('/').pop() : '(尚無圖片)';
    box.addEventListener('click', function(){ pickImage(field.el); });
    wrap.appendChild(box);
  }
  return wrap;
}
function buildSidebar(){
  $$('#kau-ve-sidebar').forEach(function(s){ s.remove(); });
  sidebarFieldMap = {};

  var blocks = $$('[data-kau-block]').filter(function(b){
    return !b.closest('#kau-ve-sidebar') && !isInEditor(b);
  });
  var sb = document.createElement('aside');
  sb.id = 'kau-ve-sidebar';

  var head = document.createElement('div');
  head.className = 'kau-ve-sb-head';
  head.innerHTML = '<div class="kau-ve-sb-logo">K</div>' +
    '<div class="kau-ve-sb-title">本頁區塊</div>' +
    '<button type="button" class="kau-ve-sb-toggle" id="kau-ve-sb-toggle" title="收合/展開">‹</button>';
  sb.appendChild(head);

  var body = document.createElement('div');
  body.className = 'kau-ve-sb-body';

  var pageKey = (cfg && cfg.pageKey) || '';
  var sectionLabel = document.createElement('div');
  sectionLabel.className = 'kau-ve-sb-section-label';
  sectionLabel.textContent = (pageKey ? pageKey.toUpperCase()+' · ' : '') + '視覺區塊';
  body.appendChild(sectionLabel);

  if (blocks.length === 0){
    var empty = document.createElement('div');
    empty.className = 'kau-ve-sb-empty';
    empty.textContent = '這個頁面沒有偵測到區塊標記。';
    body.appendChild(empty);
  } else {
    blocks.forEach(function(blockEl){
      var id = blockEl.getAttribute('data-kau-block') || '';
      var label = blockLabelFor(id);
      var note = structuredBlockNote(id);
      var fields = blockFields(blockEl);

      var item = document.createElement('div');
      item.className = 'kau-ve-sb-block';
      item.setAttribute('data-block-id', id);

      var hd = document.createElement('div');
      hd.className = 'kau-ve-sb-block-head';
      hd.innerHTML = '<span class="kau-ve-sb-block-caret">▶</span>' +
        '<span class="kau-ve-sb-block-name"></span>' +
        '<span class="kau-ve-sb-block-count">'+(note ? '管理' : fields.length)+'</span>';
      hd.querySelector('.kau-ve-sb-block-name').textContent = label;
      item.appendChild(hd);

      var fieldsDiv = document.createElement('div');
      fieldsDiv.className = 'kau-ve-sb-fields';
      if (note) {
        var noteEl = document.createElement('div');
        noteEl.className = 'kau-ve-sb-note';
        noteEl.textContent = note;
        fieldsDiv.appendChild(noteEl);
      } else {
        fields.forEach(function(f, i){
          fieldsDiv.appendChild(buildSidebarField(id, i, f));
        });
      }
      item.appendChild(fieldsDiv);

      hd.addEventListener('mouseenter', function(){ highlightBlock(blockEl, true); item.classList.add('is-hover'); });
      hd.addEventListener('mouseleave', function(){ highlightBlock(blockEl, false); item.classList.remove('is-hover'); });
      hd.addEventListener('click', function(){
        var wasOpen = item.classList.contains('is-open');
        $$('.kau-ve-sb-block.is-open').forEach(function(b){ b.classList.remove('is-open'); });
        $$('.kau-ve-sb-block.is-active').forEach(function(b){ b.classList.remove('is-active'); });
        if (!wasOpen){
          item.classList.add('is-open');
          item.classList.add('is-active');
          try{ blockEl.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){ blockEl.scrollIntoView(); }
        }
      });
      body.appendChild(item);
    });
  }

  sb.appendChild(body);
  document.body.appendChild(sb);
  document.body.classList.add('kau-ve-has-sidebar');

  function applySidebarLayout(){
    var collapsed = document.body.classList.contains('kau-ve-sidebar-collapsed');
    var offset = collapsed ? '44px' : '300px';
    document.body.style.setProperty('padding-left', '0px', 'important');
    applyPreviewLayout(offset);
    var toolbar = document.getElementById('kau-site-toolbar');
    if (toolbar) toolbar.style.setProperty('left', offset, 'important');
  }
  applySidebarLayout();

  $('#kau-ve-sb-toggle').addEventListener('click', function(){
    var collapsed = document.body.classList.toggle('kau-ve-sidebar-collapsed');
    document.body.classList.toggle('kau-ve-has-sidebar', !collapsed);
    this.textContent = collapsed ? '›' : '‹';
    applySidebarLayout();
  });

  // Right → Left sync：DOM input 事件鏡射回 sidebar input
  $$('.kau-edit-text').forEach(function(el){
    el.addEventListener('input', function(){
      var key = el.dataset.kauVeSbKey;
      if (!key || !sidebarFieldMap[key]) return;
      var entry = sidebarFieldMap[key];
      var nextVal = (el.textContent||'');
      if (entry.input.value !== nextVal){ entry.input.value = nextVal; }
      entry.wrap.classList.add('is-synced');
    });
  });
}

function boot(){
  buildToolbar();
  makeEditable();
  buildSidebar();
  // cms-content.js 非同步重畫部分區塊（footer 等）後，新節點沒被 makeEditable 處理 → 用 MutationObserver 補上
  // 注意：只跑 makeEditable，不要重建 sidebar（重建會把使用者剛點開的 is-open 狀態清掉）
  var pending = false;
  var observer = new MutationObserver(function(mutations){
    // 忽略 sidebar 自己引起的 mutation（避免無限迴圈 + 不必要的 makeEditable）
    var meaningful = false;
    for (var i = 0; i < mutations.length; i++){
      var t = mutations[i].target;
      if (t && t.closest && t.closest('#kau-ve-sidebar')) continue;
      meaningful = true; break;
    }
    if (!meaningful) return;
    if (pending) return;
    pending = true;
    setTimeout(function(){ pending = false; makeEditable(); }, 120);
  });
  observer.observe(document.body, { childList: true, subtree: true });
  // 兜底：cms-content 載入後強制再跑一次，並重建 sidebar。
  // 動態列表（沿革 / 会社概要 / 商品列表等）會在 cms-content render 後才標上 data-kau-list，
  // sidebar 必須用最新 DOM 才不會把每一筆年份、日期、卡片文字都列成可編輯欄位。
  setTimeout(function(){ makeEditable(); buildSidebar(); }, 1500);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();
})();
JS;
}

// ─── AJAX 端點 ─────────────────────────────────────────────────────────────

add_action('wp_ajax_kau_site_save', function() {
    check_ajax_referer('kau_site_save', 'nonce');
    if (!kau_site_can_edit()) wp_send_json_error(['message' => '無權限']);
    $key = sanitize_key((string) ($_POST['pageKey'] ?? ''));
    if (!in_array($key, ['home','about','products','news'], true)) {
        wp_send_json_error(['message' => '無效頁面']);
    }
    $html = wp_unslash((string) ($_POST['html'] ?? ''));
    if (strlen($html) < 1000) wp_send_json_error(['message' => 'HTML 太短']);
    kau_site_save_page($key, $html);

    // 雙向同步：把視覺編輯器收集到的 data-kau-edit/media/link map 寫回 kau_site_data_v2
    $sync_synced = 0;
    $sync_raw = wp_unslash((string) ($_POST['syncMap'] ?? ''));
    if ($sync_raw !== '') {
        $sync = json_decode($sync_raw, true);
        if (is_array($sync)) {
            $data = kau_site_get_data();
            foreach ($sync as $path => $value) {
                if (!is_string($path) || $path === '') continue;
                if (kau_site_set_path($data, $path, $value)) $sync_synced++;
            }
            kau_site_save_data($data);
        }
    }
    wp_send_json_success(['saved' => $key, 'size' => strlen($html), 'synced' => $sync_synced]);
});

// 用點分隔路徑寫值進巢狀陣列。只更新已存在的 leaf；不存在則建立新 key
// 外科手術式清理：用 regex 把 WP / wp.media / 重複的 kau-cms-* / kau-site-toolbar 等垃圾剔除
// 但保留 user 編輯的 textContent、style 屬性、區塊結構
function kau_site_strip_wp_pollution(string $html): string {
    // 1. wp.media underscore 模板 <script type="text/html" id="tmpl-*">
    $html = preg_replace('#<script\s+type="text/html"\s+id="tmpl-[^"]*"[^>]*>.*?</script>#is', '', $html);
    // 2. wp.media 整段 modal markup（visitor mode 不該有）
    // 實際 markup 是 <div id="__wp-uploader-id-N" class="supports-drag-drop"> 包
    // <div id="wp-media-modal" tabindex="0" class="media-modal wp-core-ui" role="dialog">，
    // 屬性順序不固定 → 開頭 tag 只認 token、內容用遞迴 regex 吃到對稱的 </div>
    $html = preg_replace(
        '#<div\b[^>]*(?:__wp-uploader-id|supports-drag-drop|media-modal)[^>]*>'
        . '(?<kau_mm>(?:[^<]++|<(?!/?div\b)|<div\b[^>]*>(?&kau_mm)</div>)*+)'
        . '</div>#i',
        '', $html
    ) ?? $html;
    // 3. WP / Jetpack / wpcomsh 注入的 <link>/<style>/<script>
    $patterns = [
        // <link> 引用 WP 資源
        '#<link[^>]*(?:href|src)=["\'][^"\']*(?:wp-includes|wp-content/mu-plugins|wpcom|jetpack|admin-bar|media-views|wpcomsh|page-optimize|/_static/)[^"\']*["\'][^>]*/?>#i',
        // <link> rel="dns-prefetch|preload|preconnect" 指向 WP/stats
        '#<link\s+rel=["\'](?:dns-prefetch|preload|preconnect)["\'][^>]*href=["\'][^"\']*(?:s\.w\.org|s0\.wp\.com|c0\.wp\.com|stats\.wp\.com|wp-includes|wpcom)[^"\']*["\'][^>]*/?>#i',
        // <style id="admin-bar*|wpcom*|jetpack*|media-views*|all-css-*">
        '#<style[^>]*id=["\'](?:admin-bar|wpcom-|jetpack-|media-views|all-css-|akismet-|gutenberg-|page-optimize|wp-block-library)[^"\']*["\'][^>]*>.*?</style>#is',
        // <script src="...wp-includes...|jetpack..."
        '#<script[^>]*src=["\'][^"\']*(?:wp-includes|wp-content/mu-plugins|wpcom|jetpack|wpcomsh|/_static/)[^"\']*["\'][^>]*></script>#i',
        // <script src> 指向 WP.com 登入狀態注入的統計/小工具（曾被視覺編輯器存檔烤進 DB，會無限累積）
        '#<script[^>]*src=["\'](?:https?:)?//[^"\']*(?:stats\.wp\.com|widgets\.wp\.com|gravatar\.com)[^"\']*["\'][^>]*>\s*</script>#i',
        // 帶 id 的 WP.com 內聯 script（help center / gravatar hovercards 等）
        '#<script[^>]*id=["\'](?:utils-js|help-center|grofiles|wpgroho|hovercards)[^"\']*["\'][^>]*>[\s\S]*?</script>#i',
        // <link> 指向 widgets.wp.com / gravatar 的樣式
        '#<link[^>]*href=["\'][^"\']*(?:widgets\.wp\.com|gravatar\.com)[^"\']*["\'][^>]*/?>#i',
        // 瀏覽器擴充功能注入的樣式（data:text/css 或 chrome-extension URL）
        '#<link[^>]*href=["\'](?:data:text/css|chrome-extension:)[^>]*>#i',
        '#<(?:link|style)[^>]*id=["\']stickynotecss["\'][^>]*(?:/>|>[\s\S]*?</style>|>)#i',
        // 瀏覽器擴充功能注入的浮動按鈕（曾被存檔烤進 DB，訪客會看到左下角奇怪 icon）
        '#<a[^>]*id=["\']bottomBar["\'][^>]*>[\s\S]*?</a>#i',
        '#<img[^>]*src=["\']chrome-extension:[^"\']*["\'][^>]*/?>#i',
        // WP 無障礙播報用的空 div（screen-reader 殘留）
        '#<div[^>]*id=["\']a11y-speak-[^"\']*["\'][^>]*>\s*</div>#i',
        // 編輯器自己留下的節點（萬一）
        '#<div\s+id="kau-site-toolbar"[\s\S]*?</div>#i',
        '#<div\s+id="kau-site-link-panel"[\s\S]*?</div>#i',
        '#<div\s+id="kau-site-image-panel"[\s\S]*?</div>#i',
        '#<div\s+id="kau-text-toolbar"[\s\S]*?</div>#i',
        '#<style\s+id="kau-site-editor-css"[\s\S]*?</style>#i',
        // 我們自己的 kau-cms-* 注入也清掉（每次 inject_runtime 會重加）
        '#<(?:link|style|script|meta)[^>]*id=["\']kau-cms-[^"\']*["\'][^>]*(?:/>|>(?:[\s\S]*?</(?:link|style|script)>))#i',
    ];
    foreach ($patterns as $p) {
        $new = preg_replace($p, '', $html);
        if ($new !== null) $html = $new;
    }
    // 4. 移除 inline style 含 html { margin-top: 32px } 之類 admin-bar 殘留
    $html = preg_replace('#<style[^>]*>[^<]*html\s*\{\s*margin-top[^}]*\}[^<]*</style>#i', '', $html);
    // 5. 修復壞連結：視覺編輯器曾把站內連結存成 http://products.html 這種格式（瀏覽器會當成網域）
    $html = preg_replace('#href=(["\'])https?://(home|about|products|news)\.html/?\1#i', 'href=$1/$2.html$1', $html);
    return $html;
}

function kau_site_set_path(array &$arr, string $path, $value): bool {
    $aliases = kau_site_path_aliases();
    if (isset($aliases[$path])) {
        // 舊 key 同時清掉，資料裡不留影子值，下次讀取端不會再拿到分岔的舊內容
        kau_site_unset_path($arr, $path);
        return kau_site_set_path($arr, $aliases[$path], $value);
    }

    if (preg_match('/^home\.values\.item(\d+)\.(title|desc|icon)$/', $path, $m)) {
        $index = max(0, ((int) $m[1]) - 1);
        $field = $m[2] === 'desc' ? 'description' : ($m[2] === 'icon' ? 'icon' : 'title');
        if (!isset($arr['home']) || !is_array($arr['home'])) $arr['home'] = [];
        if (!isset($arr['home']['values']) || !is_array($arr['home']['values'])) $arr['home']['values'] = [];
        if (!isset($arr['home']['values']['items']) || !is_array($arr['home']['values']['items'])) $arr['home']['values']['items'] = [];
        if (!isset($arr['home']['values']['items'][$index]) || !is_array($arr['home']['values']['items'][$index])) $arr['home']['values']['items'][$index] = [];
        $arr['home']['values']['items'][$index][$field] = is_string($value) ? sanitize_textarea_field($value) : $value;
        if ($field === 'icon') $arr['home']['values']['items'][$index]['image'] = is_string($value) ? sanitize_textarea_field($value) : $value;
        return true;
    }

    $keys = explode('.', $path);
    $cur = &$arr;
    $last = count($keys) - 1;
    foreach ($keys as $i => $k) {
        if ($i === $last) {
            $cur[$k] = is_string($value) ? sanitize_textarea_field($value) : $value;
            return true;
        }
        if (!isset($cur[$k]) || !is_array($cur[$k])) $cur[$k] = [];
        $cur = &$cur[$k];
    }
    return false;
}

add_action('wp_ajax_kau_site_upload', function() {
    check_ajax_referer('kau_site_save', 'nonce');
    if (!kau_site_can_edit()) wp_send_json_error(['message' => '無權限']);
    if (empty($_FILES['file'])) wp_send_json_error(['message' => '未收到檔案']);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $aid = media_handle_upload('file', 0);
    if (is_wp_error($aid)) wp_send_json_error(['message' => $aid->get_error_message()]);
    $url = wp_get_attachment_url($aid);
    wp_send_json_success(['url' => $url, 'id' => $aid]);
});

// ─── Site Data (site.json 等價物)──────────────────────────────────────────

function kau_site_get_data(): array {
    $data = get_option(KAU_SITE_DATA_OPT, []);
    $data = is_array($data) ? $data : [];
    // 商品/新聞資料裡殘留的舊 GitHub Pages 圖片路徑 → 對應到 WordPress 媒體庫同名檔
    // （影響 /data API 的前端商品卡、SEO JSON-LD、og:image；找不到同名檔才維持原值）
    array_walk_recursive($data, function(&$v, $k) {
        if (!is_string($v) || $v === '') return;
        if (preg_match('#^(?:https://kau-jp\.github\.io/)?/?media/([^\s"\']+)$#u', $v, $m)) {
            $v = kau_site_media_library_url($m[1]);
            return;
        }
        // 站內連結正規化：視覺編輯器曾把 /products.html 存成 http://products.html（瀏覽器會當成網域）。
        // 頁面 HTML 在 strip_wp_pollution 已修，資料層沒修的話前台腳本又會把壞網址寫回 href。
        if (substr((string) $k, -4) === '_url' || $k === 'url') {
            $fixed = kau_site_sanitize_link_url($v);
            if ($fixed !== '') $v = $fixed;
        }
    });
    return $data;
}

function kau_site_save_data(array $data): void {
    update_option(KAU_SITE_DATA_OPT, $data, false);
    update_option(KAU_SITE_DATA_OPT . '_updated', time(), false);
}

function kau_site_get_data_updated(): int {
    return (int) get_option(KAU_SITE_DATA_OPT . '_updated', 0);
}

// ─── Path 別名（寫入端與讀取端唯一的對照表）────────────────────────────────
// HTML 裡的 data-kau-edit / data-kau-link path 跟後台資料的正式 key 名稱不同。
// 存檔時把值寫進正式 key、前台卻照原始 path 去讀，就會讀到沒被更新的舊 key，
// 使用者剛存的文字在載入後被舊值蓋回去。所有轉換一律走這張表。
function kau_site_path_aliases(): array {
    return [
        'home.hero.sub'             => 'home.hero.subtitle',
        'home.hero.scroll'          => 'home.hero.scroll_label',
        'home.hero.bg'              => 'home.hero.image',
        'home.hero.cta1.label'      => 'home.hero.primary_label',
        'home.hero.cta2.label'      => 'home.hero.secondary_label',
        'home.hero.cta1_url'        => 'home.hero.primary_url',
        'home.hero.cta2_url'        => 'home.hero.secondary_url',
        'home.intro.title'          => 'home.philosophy.title',
        'home.intro.eyebrow'        => 'home.philosophy.eyebrow',
        'home.collection.title'     => 'home.showcase.title',
        'home.collection.eyebrow'   => 'home.showcase.eyebrow',
        'home.collection.all.label' => 'home.showcase.view_all_label',
        'home.collection.all_url'   => 'home.showcase.view_all_url',
        'home.feature.desc'         => 'home.feature.description',
        'home.cta.desc'             => 'home.cta.description',
        'home.cta.btn1.label'       => 'home.cta.primary_label',
        'home.cta.btn2.label'       => 'home.cta.secondary_label',
        'home.cta.btn1_url'         => 'home.cta.primary_url',
        'home.cta.btn2_url'         => 'home.cta.secondary_url',
    ];
}

// 合併時「舊 key 的值才是對的」的例外。
// home.hero.bg：視覺編輯器換的圖寫進 bg 並且是首頁實際顯示的那張；後台「主視覺圖片 URL」寫的
// image 從來沒生效過。合併時若讓 image 勝出，首頁大圖會無聲換掉，所以這一組以 bg 為準。
function kau_site_legacy_wins_paths(): array {
    return ['home.hero.bg' => true];
}

// 合併標題（line_1 + accent + suffix 拼成一個 h1/h2）與整段頁尾文字：
// 視覺編輯器拆不出欄位所以存檔時不同步，前台也就不能拿資料回寫，否則會蓋掉編輯結果。
function kau_site_sync_skip_paths(): array {
    return ['home.hero.title', 'home.cta.title', 'home.footer.addr', 'home.footer.brand'];
}

function kau_site_get_path_value(array $arr, string $path) {
    $cur = $arr;
    foreach (explode('.', $path) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
        $cur = $cur[$k];
    }
    return $cur;
}

function kau_site_unset_path(array &$arr, string $path): bool {
    $keys = explode('.', $path);
    $last = array_pop($keys);
    $cur = &$arr;
    foreach ($keys as $k) {
        if (!isset($cur[$k]) || !is_array($cur[$k])) return false;
        $cur = &$cur[$k];
    }
    if (!array_key_exists($last, $cur)) return false;
    unset($cur[$last]);
    return true;
}

// 一次性清掉資料裡的舊 key 影子值（正式 key 已有值就刪舊的，沒有就搬過去）。
// 不清的話，任何還照舊 key 讀的消費端（例如瀏覽器快取的舊 cms-content.js）會把舊文字撈回來。
function kau_site_migrate_path_aliases(): void {
    if (get_option('kau_site_aliases_migrated') === KAU_SITE_VERSION) return;
    $data = get_option(KAU_SITE_DATA_OPT, []);
    if (is_array($data) && $data) {
        $changed = false;
        $legacy_wins = kau_site_legacy_wins_paths();
        foreach (kau_site_path_aliases() as $legacy => $canonical) {
            $legacy_value = kau_site_get_path_value($data, $legacy);
            if ($legacy_value === null) continue;
            $canonical_value = kau_site_get_path_value($data, $canonical);
            if ($canonical_value === null || $canonical_value === '' || isset($legacy_wins[$legacy])) {
                kau_site_set_path($data, $canonical, $legacy_value);
            }
            kau_site_unset_path($data, $legacy);
            $changed = true;
        }
        if ($changed) kau_site_save_data($data);
    }
    update_option('kau_site_aliases_migrated', KAU_SITE_VERSION, false);
}
add_action('admin_init', 'kau_site_migrate_path_aliases');
register_activation_hook(__FILE__, function() {
    delete_option('kau_site_aliases_migrated');
    kau_site_migrate_path_aliases();
});

function kau_site_import_data_from_file(bool $force = false): bool {
    if (!$force && !empty(kau_site_get_data())) return false;
    foreach ((array) glob(WP_PLUGIN_DIR . '/kau-original-site-editor*/static/content/site.json') as $f) {
        $json = (string) @file_get_contents($f);
        $data = json_decode($json, true);
        if (is_array($data)) {
            kau_site_save_data($data);
            return true;
        }
    }
    return false;
}

register_activation_hook(__FILE__, function() {
    kau_site_import_data_from_file(false);
});


// ─── 商品 CRUD ─────────────────────────────────────────────────────────────

function kau_site_get_products(): array {
    $data = kau_site_get_data();
    $items = $data['products']['items'] ?? [];
    if (!is_array($items)) return [];
    foreach ($items as $i => $item) {
        if (empty($item['id'])) $items[$i]['id'] = 'p' . ($i + 1);
    }
    return array_values($items);
}

function kau_site_save_products(array $products): void {
    $data = kau_site_get_data();
    if (!isset($data['products']) || !is_array($data['products'])) $data['products'] = [];
    $data['products']['items'] = array_values($products);
    kau_site_save_data($data);
}

function kau_site_default_product_categories(): array {
    return [
        ['code' => 'office', 'label' => 'Office', 'label_ja' => 'オフィスチェア'],
        ['code' => 'study',  'label' => 'Study', 'label_ja' => '学習チェア'],
        ['code' => 'exec',   'label' => 'Executive', 'label_ja' => 'エグゼクティブ'],
        ['code' => 'acc',    'label' => 'Accessory', 'label_ja' => 'アクセサリー'],
    ];
}

function kau_site_normalize_product_category(array $cat): ?array {
    $code = sanitize_key((string) ($cat['code'] ?? ''));
    if ($code === '' || $code === 'all') return null;
    $label = sanitize_text_field((string) ($cat['label'] ?? ''));
    $label_ja = sanitize_text_field((string) ($cat['label_ja'] ?? ''));
    $defaults = [];
    foreach (kau_site_default_product_categories() as $default_cat) {
        $defaults[$default_cat['code']] = $default_cat;
    }
    if ($label === '') {
        $raw = sanitize_text_field((string) ($cat['admin_label'] ?? ''));
        if ($raw !== '' && preg_match('/^([^（(]+)/u', $raw, $m)) $label = trim($m[1]);
    }
    if (isset($defaults[$code])) {
        if ($label !== '' && !preg_match('/[A-Za-z]/', $label) && $label_ja === '') $label_ja = $label;
        if ($label === '' || !preg_match('/[A-Za-z]/', $label)) $label = $defaults[$code]['label'];
        if ($label_ja === '') $label_ja = $defaults[$code]['label_ja'];
    }
    if ($label === '') $label = ucfirst($code);
    return ['code' => $code, 'label' => $label, 'label_ja' => $label_ja];
}

function kau_site_product_category_records(): array {
    $data = kau_site_get_data();
    $raw = $data['products']['categories'] ?? [];
    $records = [];
    if (is_array($raw)) {
        foreach ($raw as $cat) {
            if (!is_array($cat)) continue;
            $normalized = kau_site_normalize_product_category($cat);
            if ($normalized) $records[$normalized['code']] = $normalized;
        }
    }
    if (!$records) {
        foreach (kau_site_default_product_categories() as $cat) {
            $records[$cat['code']] = $cat;
        }
    }
    return array_values($records);
}

function kau_site_product_categories(): array {
    $out = [];
    foreach (kau_site_product_category_records() as $cat) {
        $out[$cat['code']] = $cat['label'] . ($cat['label_ja'] !== '' ? ' (' . $cat['label_ja'] . ')' : '');
    }
    return $out;
}

function kau_site_product_category_labels(): array {
    $out = [];
    foreach (kau_site_product_category_records() as $cat) {
        $out[$cat['code']] = $cat['label'];
    }
    return $out;
}

function kau_site_save_product_category_records(array $records): void {
    $clean = [];
    foreach ($records as $cat) {
        if (!is_array($cat)) continue;
        $normalized = kau_site_normalize_product_category($cat);
        if ($normalized) $clean[$normalized['code']] = $normalized;
    }
    if (!$clean) {
        foreach (kau_site_default_product_categories() as $cat) $clean[$cat['code']] = $cat;
    }
    $data = kau_site_get_data();
    if (!isset($data['products']) || !is_array($data['products'])) $data['products'] = [];
    $data['products']['categories'] = array_merge(
        [['code' => 'all', 'label' => 'All', 'label_ja' => '']],
        array_values($clean)
    );
    kau_site_save_data($data);
}

function kau_site_handle_product_category_admin_post(array $products): void {
    if (!isset($_POST['kau_pa'])) return;
    $action = sanitize_key((string) $_POST['kau_pa']);
    if ($action !== 'save_cat' && $action !== 'delete_cat') return;
    check_admin_referer('kau_site_products');

    if ($action === 'save_cat') {
        $old_code = sanitize_key((string) ($_POST['old_code'] ?? ''));
        $code = sanitize_key((string) ($_POST['cat_code'] ?? ''));
        $label = sanitize_text_field((string) ($_POST['cat_label'] ?? ''));
        $label_ja = sanitize_text_field((string) ($_POST['cat_label_ja'] ?? ''));
        if ($code === '' && $label !== '') $code = sanitize_key(strtolower($label));
        if ($code === '' || $code === 'all' || $label === '') {
            $_GET['cat_error'] = '分類代碼與英文名稱都必填，代碼不能用 all。';
            return;
        }
        $records = kau_site_product_category_records();
        $next = [];
        $updated = false;
        foreach ($records as $cat) {
            if ($old_code !== '' && $cat['code'] === $old_code) {
                $next[] = ['code' => $code, 'label' => $label, 'label_ja' => $label_ja];
                $updated = true;
            } elseif ($cat['code'] !== $code) {
                $next[] = $cat;
            }
        }
        if (!$updated) $next[] = ['code' => $code, 'label' => $label, 'label_ja' => $label_ja];
        kau_site_save_product_category_records($next);
        if ($old_code !== '') {
            foreach ($products as &$prod) {
                if (($prod['category_code'] ?? '') === $old_code || ($prod['category_code'] ?? '') === $code) {
                    $prod['category_code'] = $code;
                    $prod['category_label'] = $label;
                }
            }
            unset($prod);
            kau_site_save_products($products);
        }
        $_GET['cat_saved'] = '1';
        return;
    }

    $code = sanitize_key((string) ($_POST['cat_code'] ?? ''));
    $in_use = false;
    foreach ($products as $prod) {
        if (($prod['category_code'] ?? '') === $code) { $in_use = true; break; }
    }
    if ($in_use) {
        $_GET['cat_error'] = '這個分類還有商品在使用，請先把商品改到其他分類再刪除。';
        return;
    }
    $next = array_values(array_filter(kau_site_product_category_records(), fn($cat) => $cat['code'] !== $code));
    kau_site_save_product_category_records($next);
    $_GET['cat_deleted'] = '1';
}

function kau_site_sanitize_product(array $p): array {
    $cats = kau_site_product_categories();
    $code = sanitize_key((string) ($p['category_code'] ?? 'office'));
    if (!isset($cats[$code])) $code = array_key_first($cats) ?: 'office';
    $label_map = kau_site_product_category_labels();
    $gallery_raw = $p['gallery'] ?? [];
    if (is_string($gallery_raw)) {
        $gallery_raw = preg_split('/[\r\n,]+/', $gallery_raw);
    }
    $gallery = [];
    if (is_array($gallery_raw)) {
        foreach ($gallery_raw as $url) {
            $url = esc_url_raw(trim((string) $url));
            if ($url !== '' && !in_array($url, $gallery, true)) $gallery[] = $url;
        }
    }
    return [
        'id'             => sanitize_key((string) ($p['id'] ?? 'p' . time())),
        'name'           => sanitize_text_field((string) ($p['name'] ?? '')),
        'category_code'  => $code,
        'category_label' => $label_map[$code] ?? sanitize_text_field((string) ($p['category_label'] ?? 'Office')),
        'description'    => sanitize_text_field((string) ($p['description'] ?? '')),
        'detail'         => sanitize_textarea_field((string) ($p['detail'] ?? '')),
        'features'       => sanitize_text_field((string) ($p['features'] ?? '')),
        'width'          => sanitize_text_field((string) ($p['width'] ?? '')),
        'depth'          => sanitize_text_field((string) ($p['depth'] ?? '')),
        'height'         => sanitize_text_field((string) ($p['height'] ?? '')),
        'seat_height'    => sanitize_text_field((string) ($p['seat_height'] ?? '')),
        'weight'         => sanitize_text_field((string) ($p['weight'] ?? '')),
        'colors'         => sanitize_text_field((string) ($p['colors'] ?? '')),
        'material'       => sanitize_text_field((string) ($p['material'] ?? '')),
        'specs'          => sanitize_textarea_field((string) ($p['specs'] ?? '')),
        'price'          => sanitize_text_field((string) ($p['price'] ?? '')),
        'image'          => esc_url_raw((string) ($p['image'] ?? '')),
        'gallery'        => $gallery,
        'amazon_url'     => esc_url_raw((string) ($p['amazon_url'] ?? '#')),
        'rakuten_url'    => esc_url_raw((string) ($p['rakuten_url'] ?? '#')),
        'featured'       => !empty($p['featured']),
    ];
}

// ─── 新聞 CRUD ─────────────────────────────────────────────────────────────

function kau_site_get_news(): array {
    $data = kau_site_get_data();
    $items = $data['news']['items'] ?? [];
    if (!is_array($items)) return [];
    foreach ($items as $i => $item) {
        if (empty($item['id'])) $items[$i]['id'] = 'n' . ($i + 1);
    }
    return array_values($items);
}

function kau_site_save_news(array $news, ?array $featured = null): void {
    $data = kau_site_get_data();
    if (!isset($data['news']) || !is_array($data['news'])) $data['news'] = [];
    $data['news']['items'] = array_values($news);
    if ($featured !== null) $data['news']['featured'] = $featured;
    kau_site_save_data($data);
}

function kau_site_news_categories(): array {
    return ['product' => '製品', 'event' => '展示会', 'info' => 'お知らせ'];
}

function kau_site_sanitize_news_item(array $n): array {
    $cats = kau_site_news_categories();
    $code = sanitize_key((string) ($n['category_code'] ?? 'info'));
    return [
        'id'            => sanitize_key((string) ($n['id'] ?? 'n' . time())),
        'date'          => sanitize_text_field((string) ($n['date'] ?? '')),
        'category_code' => $code,
        'category'      => $cats[$code] ?? sanitize_text_field((string) ($n['category'] ?? '')),
        'title'         => sanitize_text_field((string) ($n['title'] ?? '')),
        'summary'       => sanitize_textarea_field((string) ($n['summary'] ?? '')),
        'url'           => esc_url_raw((string) ($n['url'] ?? '#')),
    ];
}

// ─── REST API（給 PowerShell 用 Application Password 推入 HTML）─────────────

add_action('rest_api_init', function() {
    register_rest_route('kau-site/v1', '/import', [
        'methods' => 'POST',
        'permission_callback' => function() { return current_user_can('edit_theme_options'); },
        'callback' => function($req) {
            $key = sanitize_key((string) $req->get_param('key'));
            $html = (string) $req->get_param('html');
            if (!in_array($key, ['home','about','products','news'], true)) {
                return new WP_Error('bad_key', 'Invalid key', ['status' => 400]);
            }
            if (strlen($html) < 1000) {
                return new WP_Error('too_short', 'HTML too short', ['status' => 400]);
            }
            kau_site_save_page($key, $html);
            return ['saved' => $key, 'size' => strlen($html)];
        },
    ]);

    // 上傳 asset 檔案到外掛資料夾
    register_rest_route('kau-site/v1', '/asset', [
        'methods' => 'POST',
        'permission_callback' => function() { return current_user_can('edit_theme_options'); },
        'callback' => function($req) {
            $name = sanitize_file_name((string) $req->get_param('name'));
            $b64 = (string) $req->get_param('data');
            $sub = sanitize_key((string) ($req->get_param('sub') ?: 'assets'));
            if ($name === '' || $b64 === '') return new WP_Error('bad_params', 'missing', ['status' => 400]);
            $dir = plugin_dir_path(__FILE__) . $sub;
            if (!is_dir($dir)) wp_mkdir_p($dir);
            $bytes = base64_decode($b64, true);
            if ($bytes === false) return new WP_Error('bad_b64', 'invalid base64', ['status' => 400]);
            $path = $dir . '/' . $name;
            $r = @file_put_contents($path, $bytes);
            if ($r === false) return new WP_Error('write_failed', 'cannot write to ' . $path, ['status' => 500]);
            return ['saved' => $path, 'size' => strlen($bytes)];
        },
    ]);

    // 外科手術式清理：只剔除 WP 注入垃圾，保留 user 編輯
    register_rest_route('kau-site/v1', '/clean', [
        'methods' => 'POST',
        'permission_callback' => function() { return current_user_can('edit_theme_options'); },
        'callback' => function($req) {
            $report = [];
            foreach (['home','about','products','news'] as $key) {
                $page = kau_site_get_page($key);
                if (empty($page['html'])) { $report[$key] = 'skip (空)'; continue; }
                $before = strlen($page['html']);
                $cleaned = kau_site_strip_wp_pollution($page['html']);
                $after = strlen($cleaned);
                if ($cleaned !== $page['html']) {
                    kau_site_save_page($key, $cleaned);
                    $report[$key] = $before . ' → ' . $after . ' bytes (-' . ($before-$after) . ')';
                } else {
                    $report[$key] = 'already clean (' . $before . ' bytes)';
                }
            }
            return ['cleaned' => $report];
        },
    ]);

    // 公開讀取：給 cms-content.js 抓資料
    register_rest_route('kau-site/v1', '/data', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function() {
            $data = kau_site_get_data();
            if (empty($data)) {
                kau_site_import_data_from_file(false);
                $data = kau_site_get_data();
            }
            if (!isset($data['products']) || !is_array($data['products'])) $data['products'] = [];
            $data['products']['categories'] = array_merge(
                [['code' => 'all', 'label' => 'All', 'label_ja' => '']],
                kau_site_product_category_records()
            );
            // 商品被勾選為「首頁精選」者 → 動態覆寫 home.showcase.items
            $featured_prods = [];
            foreach (($data['products']['items'] ?? []) as $prod) {
                if (!empty($prod['featured'])) {
                    $featured_prods[] = [
                        'category'    => $prod['category_label'] ?? $prod['category_code'] ?? '',
                        'name'        => $prod['name'] ?? '',
                        'price'       => $prod['price'] ?? '',
                        'description' => $prod['description'] ?? '',
                        'image'       => $prod['image'] ?? '',
                    ];
                }
            }
            if (!empty($featured_prods)) {
                if (!isset($data['home']) || !is_array($data['home'])) $data['home'] = [];
                if (!isset($data['home']['showcase']) || !is_array($data['home']['showcase'])) $data['home']['showcase'] = [];
                $data['home']['showcase']['items'] = $featured_prods;
            }
            // 把已發佈的 WP 文章自動合進 news.items（依日期新到舊排序）
            $wp_posts = get_posts(['numberposts' => 50, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']);
            $post_items = [];
            foreach ($wp_posts as $post) {
                $cats = get_the_category($post->ID);
                $cat_label = $cats && !empty($cats[0]->name) ? $cats[0]->name : 'お知らせ';
                $post_items[] = [
                    'id'            => 'wp' . $post->ID,
                    'date'          => get_the_date('Y.m.d', $post),
                    'category_code' => 'wp',
                    'category'      => $cat_label,
                    'title'         => get_the_title($post),
                    'url'           => get_permalink($post),
                    'summary'       => wp_trim_words(get_the_excerpt($post) ?: $post->post_content, 50),
                    'source'        => 'wp_post',
                ];
            }
            if ($post_items) {
                if (!isset($data['news']) || !is_array($data['news'])) $data['news'] = [];
                $existing_news = is_array($data['news']['items'] ?? null) ? $data['news']['items'] : [];
                // 合併 + 依日期排序（新到舊）
                $merged = array_merge($post_items, $existing_news);
                usort($merged, function($a, $b) {
                    $da = strtotime(str_replace(['.', '/'], '-', $a['date'] ?? ''));
                    $db = strtotime(str_replace(['.', '/'], '-', $b['date'] ?? ''));
                    return ($db ?: 0) <=> ($da ?: 0);
                });
                $data['news']['items'] = $merged;
            }
            return rest_ensure_response($data);
        },
    ]);

    // 一次性上傳整份 site data（給 PowerShell 推用）
    register_rest_route('kau-site/v1', '/data', [
        'methods' => 'POST',
        'permission_callback' => function() { return current_user_can('edit_theme_options'); },
        'callback' => function($req) {
            $json = (string) $req->get_param('data');
            $data = json_decode($json, true);
            if (!is_array($data)) return new WP_Error('bad_json', 'Invalid JSON', ['status' => 400]);
            kau_site_save_data($data);
            return ['saved' => true, 'keys' => array_keys($data)];
        },
    ]);
});

// ─── 後台選單 ─────────────────────────────────────────────────────────────

add_action('admin_menu', function() {
    // position 2 → 把 KAU Site 放到「控制台」正下方（置頂）
    add_menu_page('KAU Site', 'KAU Site', 'edit_theme_options', 'kau-site', 'kau_site_admin_page', 'dashicons-admin-site-alt3', 2);
    add_submenu_page('kau-site', '頁面內容', '頁面內容', 'edit_theme_options', 'kau-site', 'kau_site_admin_page');
    add_submenu_page('kau-site', '商品管理', '商品管理', 'edit_theme_options', 'kau-site-products', 'kau_site_products_admin');
    add_submenu_page('kau-site', '最新情報', '最新情報', 'edit_theme_options', 'kau-site-news', 'kau_site_news_admin');
    add_submenu_page('kau-site', '会社概要', '會社概要', 'edit_theme_options', 'kau-site-about', 'kau_site_about_admin');
    add_submenu_page('kau-site', '首頁', '首頁', 'edit_theme_options', 'kau-site-home', 'kau_site_home_admin');
    add_submenu_page('kau-site', '全域設定', '全域設定', 'edit_theme_options', 'kau-site-global', 'kau_site_global_admin');
    add_submenu_page('kau-site', '圖片壓縮', '圖片壓縮', 'edit_theme_options', 'kau-site-images', 'kau_site_images_admin');
});

function kau_site_home_admin(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');
    if (isset($_POST['kau_home_action'])) {
        check_admin_referer('kau_site_home');
        $data = kau_site_get_data();
        if (!isset($data['home']) || !is_array($data['home'])) $data['home'] = [];
        $home = $data['home'];

        $hero = (array) ($_POST['hero'] ?? []);
        $home['hero'] = array_merge(is_array($home['hero'] ?? null) ? $home['hero'] : [], [
            'eyebrow' => sanitize_text_field((string) ($hero['eyebrow'] ?? '')),
            'line_1' => sanitize_text_field((string) ($hero['line_1'] ?? '')),
            'accent' => sanitize_text_field((string) ($hero['accent'] ?? '')),
            'suffix' => sanitize_text_field((string) ($hero['suffix'] ?? '')),
            'subtitle' => sanitize_textarea_field((string) ($hero['subtitle'] ?? '')),
            'image' => esc_url_raw((string) ($hero['image'] ?? '')),
            'primary_label' => sanitize_text_field((string) ($hero['primary_label'] ?? '')),
            'primary_url' => esc_url_raw((string) ($hero['primary_url'] ?? '#')),
            'secondary_label' => sanitize_text_field((string) ($hero['secondary_label'] ?? '')),
            'secondary_url' => esc_url_raw((string) ($hero['secondary_url'] ?? '#')),
        ]);

        $home['philosophy'] = [
            'eyebrow' => sanitize_text_field((string) ($_POST['philosophy_eyebrow'] ?? '')),
            'title' => sanitize_textarea_field((string) ($_POST['philosophy_title'] ?? '')),
        ];
        $home['feature'] = array_merge(is_array($home['feature'] ?? null) ? $home['feature'] : [], [
            'eyebrow' => sanitize_text_field((string) ($_POST['feature_eyebrow'] ?? '')),
            'title' => sanitize_text_field((string) ($_POST['feature_title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($_POST['feature_description'] ?? '')),
            'image' => esc_url_raw((string) ($_POST['feature_image'] ?? '')),
        ]);
        $home['feature']['desc'] = $home['feature']['description'];
        $home['feature']['stats'] = [];
        foreach ((array) ($_POST['feature_stats'] ?? []) as $s) {
            $label = sanitize_text_field((string) ($s['label'] ?? ''));
            if ($label === '' && empty($s['value'])) continue;
            $home['feature']['stats'][] = [
                'value' => sanitize_text_field((string) ($s['value'] ?? '')),
                'suffix' => sanitize_text_field((string) ($s['suffix'] ?? '')),
                'label' => $label,
            ];
        }
        $home['values'] = ['items' => []];
        foreach ((array) ($_POST['values'] ?? []) as $v) {
            $title = sanitize_text_field((string) ($v['title'] ?? ''));
            if ($title === '') continue;
            $home['values']['items'][] = [
                'title' => $title,
                'description' => sanitize_textarea_field((string) ($v['description'] ?? '')),
                'icon' => esc_url_raw((string) ($v['icon'] ?? '')),
            ];
        }
        $cta = (array) ($_POST['cta'] ?? []);
        $home['cta'] = [
            'eyebrow' => sanitize_text_field((string) ($cta['eyebrow'] ?? '')),
            'line_1' => sanitize_text_field((string) ($cta['line_1'] ?? '')),
            'accent' => sanitize_text_field((string) ($cta['accent'] ?? '')),
            'suffix' => sanitize_text_field((string) ($cta['suffix'] ?? '')),
            'description' => sanitize_textarea_field((string) ($cta['description'] ?? '')),
            'image' => esc_url_raw((string) ($cta['image'] ?? '')),
            'primary_label' => sanitize_text_field((string) ($cta['primary_label'] ?? '')),
            'primary_url' => esc_url_raw((string) ($cta['primary_url'] ?? '#')),
            'secondary_label' => sanitize_text_field((string) ($cta['secondary_label'] ?? '')),
            'secondary_url' => esc_url_raw((string) ($cta['secondary_url'] ?? '#')),
        ];
        $data['home'] = $home;
        kau_site_save_data($data);
        $_GET['saved'] = '1';
    }

    $data = kau_site_get_data();
    $home = is_array($data['home'] ?? null) ? $data['home'] : [];
    $hero = (array) ($home['hero'] ?? []);
    $philo = (array) ($home['philosophy'] ?? []);
    $feature = (array) ($home['feature'] ?? []);
    $stats = (array) ($feature['stats'] ?? []);
    $values = (array) ($home['values']['items'] ?? []);
    $cta = (array) ($home['cta'] ?? []);
    kau_site_admin_accordion_styles();
    ?>
    <div class="wrap kau-about-wrap">
      <h1>首頁 管理</h1>
      <p>編輯後立即生效於 <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank">首頁</a>。</p>
      <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p>已儲存。</p></div><?php endif; ?>
      <form method="post">
        <?php wp_nonce_field('kau_site_home'); ?>
        <input type="hidden" name="kau_home_action" value="save">

        <details open>
          <summary><?php echo kau_site_admin_thumb('hero'); ?><span class="kau-label">主視覺 Hero</span></summary>
          <div>
            <p><label><strong>Eyebrow（小標）</strong><br><input class="regular-text" name="hero[eyebrow]" value="<?php echo esc_attr($hero['eyebrow'] ?? ''); ?>"></label></p>
            <p><label><strong>標題 Line 1</strong><br><input class="regular-text" name="hero[line_1]" value="<?php echo esc_attr($hero['line_1'] ?? ''); ?>"></label></p>
            <p><label><strong>強調文字（金色）</strong><br><input class="regular-text" name="hero[accent]" value="<?php echo esc_attr($hero['accent'] ?? ''); ?>"></label></p>
            <p><label><strong>後綴文字</strong><br><input class="regular-text" name="hero[suffix]" value="<?php echo esc_attr($hero['suffix'] ?? ''); ?>"></label></p>
            <p><label><strong>副標題</strong><br><textarea class="large-text" rows="3" name="hero[subtitle]"><?php echo esc_textarea($hero['subtitle'] ?? ''); ?></textarea></label></p>
            <p><label><strong>主視覺圖片 URL</strong><br><input class="regular-text kau-img-url" name="hero[image]" value="<?php echo esc_attr($hero['image'] ?? ''); ?>"> <button class="button kau-pick-img" type="button">媒體庫</button></label></p>
            <p><label><strong>主要按鈕文字</strong><br><input class="regular-text" name="hero[primary_label]" value="<?php echo esc_attr($hero['primary_label'] ?? ''); ?>"></label></p>
            <p><label><strong>主要按鈕 URL</strong><br><input class="regular-text" name="hero[primary_url]" value="<?php echo esc_attr($hero['primary_url'] ?? '#'); ?>"></label></p>
            <p><label><strong>次要按鈕文字</strong><br><input class="regular-text" name="hero[secondary_label]" value="<?php echo esc_attr($hero['secondary_label'] ?? ''); ?>"></label></p>
            <p><label><strong>次要按鈕 URL</strong><br><input class="regular-text" name="hero[secondary_url]" value="<?php echo esc_attr($hero['secondary_url'] ?? '#'); ?>"></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('philosophy'); ?><span class="kau-label">品牌理念 Philosophy</span></summary>
          <div>
            <p><label><strong>Eyebrow</strong><br><input class="regular-text" name="philosophy_eyebrow" value="<?php echo esc_attr($philo['eyebrow'] ?? ''); ?>"></label></p>
            <p><label><strong>理念文字</strong><br><textarea class="large-text" rows="3" name="philosophy_title"><?php echo esc_textarea($philo['title'] ?? ''); ?></textarea></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('feature'); ?><span class="kau-label">特色功能 Feature（Signature）</span></summary>
          <div>
            <p><label><strong>Eyebrow</strong><br><input class="regular-text" name="feature_eyebrow" value="<?php echo esc_attr($feature['eyebrow'] ?? ''); ?>"></label></p>
            <p><label><strong>標題</strong><br><input class="regular-text" name="feature_title" value="<?php echo esc_attr($feature['title'] ?? ''); ?>"></label></p>
            <p><label><strong>說明</strong><br><textarea class="large-text" rows="3" name="feature_description"><?php echo esc_textarea($feature['description'] ?? ''); ?></textarea></label></p>
            <p><label><strong>圖片 URL</strong><br><input class="regular-text kau-img-url" name="feature_image" value="<?php echo esc_attr($feature['image'] ?? ''); ?>"> <button class="button kau-pick-img" type="button">媒體庫</button></label></p>
            <p style="margin-top:14px"><strong>數據統計</strong></p>
            <div class="row-list" id="kau-feature-stats">
              <?php foreach ($stats as $i => $s): ?>
                <div class="row-item">
                  <span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span>
                  <div class="row-grid">
                    <label><strong>數值</strong><input class="regular-text" name="feature_stats[<?php echo $i; ?>][value]" value="<?php echo esc_attr($s['value'] ?? ''); ?>"></label>
                    <label><strong>單位</strong><input class="regular-text" name="feature_stats[<?php echo $i; ?>][suffix]" value="<?php echo esc_attr($s['suffix'] ?? ''); ?>"></label>
                    <label><strong>說明</strong><input class="regular-text" name="feature_stats[<?php echo $i; ?>][label]" value="<?php echo esc_attr($s['label'] ?? ''); ?>"></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="button add-btn" data-add="feature-stats">＋ 新增統計項</button>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('values'); ?><span class="kau-label">品牌價值 Values</span></summary>
          <div>
            <div class="row-list" id="kau-values">
              <?php foreach ($values as $i => $v): ?>
                <div class="row-item">
                  <span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span>
                  <div class="row-grid">
                    <label><strong>圖示 Icon URL（SVG / PNG）</strong><span style="display:flex;gap:6px;align-items:center"><?php if (!empty($v['icon'])): ?><img src="<?php echo esc_url($v['icon']); ?>" style="width:32px;height:32px;object-fit:contain;background:#fff;border:1px solid #e2ddd7;border-radius:4px;padding:3px"><?php endif; ?><input class="regular-text kau-img-url" name="values[<?php echo $i; ?>][icon]" value="<?php echo esc_attr($v['icon'] ?? ''); ?>"><button class="button kau-pick-img" type="button">媒體庫</button></span></label>
                    <label><strong>標題</strong><input class="regular-text" name="values[<?php echo $i; ?>][title]" value="<?php echo esc_attr($v['title'] ?? ''); ?>"></label>
                    <label><strong>說明</strong><textarea class="large-text" rows="2" name="values[<?php echo $i; ?>][description]"><?php echo esc_textarea($v['description'] ?? ''); ?></textarea></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="button add-btn" data-add="values">＋ 新增價值項</button>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('cta'); ?><span class="kau-label">行動呼籲 CTA</span></summary>
          <div>
            <p><label><strong>Eyebrow</strong><br><input class="regular-text" name="cta[eyebrow]" value="<?php echo esc_attr($cta['eyebrow'] ?? ''); ?>"></label></p>
            <p><label><strong>標題 Line 1</strong><br><input class="regular-text" name="cta[line_1]" value="<?php echo esc_attr($cta['line_1'] ?? ''); ?>"></label></p>
            <p><label><strong>強調文字</strong><br><input class="regular-text" name="cta[accent]" value="<?php echo esc_attr($cta['accent'] ?? ''); ?>"></label></p>
            <p><label><strong>後綴</strong><br><input class="regular-text" name="cta[suffix]" value="<?php echo esc_attr($cta['suffix'] ?? ''); ?>"></label></p>
            <p><label><strong>說明</strong><br><textarea class="large-text" rows="3" name="cta[description]"><?php echo esc_textarea($cta['description'] ?? ''); ?></textarea></label></p>
            <p><label><strong>背景圖 URL</strong><br><input class="regular-text kau-img-url" name="cta[image]" value="<?php echo esc_attr($cta['image'] ?? ''); ?>"> <button class="button kau-pick-img" type="button">媒體庫</button></label></p>
            <p><label><strong>主要按鈕文字</strong><br><input class="regular-text" name="cta[primary_label]" value="<?php echo esc_attr($cta['primary_label'] ?? ''); ?>"></label></p>
            <p><label><strong>主要按鈕 URL</strong><br><input class="regular-text" name="cta[primary_url]" value="<?php echo esc_attr($cta['primary_url'] ?? '#'); ?>"></label></p>
            <p><label><strong>次要按鈕文字</strong><br><input class="regular-text" name="cta[secondary_label]" value="<?php echo esc_attr($cta['secondary_label'] ?? ''); ?>"></label></p>
            <p><label><strong>次要按鈕 URL</strong><br><input class="regular-text" name="cta[secondary_url]" value="<?php echo esc_attr($cta['secondary_url'] ?? '#'); ?>"></label></p>
          </div>
        </details>

        <?php kau_site_admin_savebar(); ?>
      </form>
    </div>
    <?php kau_site_admin_accordion_js(['feature-stats' => ['value','suffix','label'], 'values' => ['icon:img','title','description:textarea']]); ?>
    <?php
}

function kau_site_global_admin(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');
    if (isset($_POST['kau_global_action'])) {
        check_admin_referer('kau_site_global');
        $data = kau_site_get_data();
        if (!isset($data['global']) || !is_array($data['global'])) $data['global'] = [];
        $g = $data['global'];
        foreach (['company_name','postal_code','phone','address_line_1','address_line_2','address_line_3','email','hours','access','contact_url','amazon_url','rakuten_url'] as $f) {
            $g[$f] = sanitize_text_field((string) ($_POST['g'][$f] ?? ''));
        }
        $g['note'] = sanitize_textarea_field((string) ($_POST['g']['note'] ?? ''));
        if (!isset($g['navigation']) || !is_array($g['navigation'])) $g['navigation'] = [];
        $nav = (array) ($_POST['nav'] ?? []);
        foreach (['home_label','home_en','about_label','about_en','products_label','products_en','news_label','news_en','shop_label','contact_label'] as $f) {
            $g['navigation'][$f] = sanitize_text_field((string) ($nav[$f] ?? ''));
        }
        if (!isset($g['shop']) || !is_array($g['shop'])) $g['shop'] = [];
        $shop = (array) ($_POST['shop'] ?? []);
        foreach (['amazon_label','rakuten_label','coming_soon_label','coming_soon_title'] as $f) {
            $g['shop'][$f] = sanitize_text_field((string) ($shop[$f] ?? ''));
        }
        $g['shop']['coming_soon_description'] = sanitize_textarea_field((string) ($shop['coming_soon_description'] ?? ''));
        if (!isset($g['footer']) || !is_array($g['footer'])) $g['footer'] = [];
        $footer = (array) ($_POST['footer'] ?? []);
        $g['footer']['copyright'] = sanitize_text_field((string) ($footer['copyright'] ?? ''));
        $g['footer']['suffix'] = sanitize_text_field((string) ($footer['suffix'] ?? ''));
        foreach (['products','company','support'] as $col) {
            $g['footer'][$col . '_title'] = sanitize_text_field((string) ($footer[$col . '_title'] ?? ''));
            $g['footer'][$col . '_links'] = [];
            foreach ((array) ($footer[$col . '_links'] ?? []) as $l) {
                $label = sanitize_text_field((string) ($l['label'] ?? ''));
                if ($label === '') continue;
                $g['footer'][$col . '_links'][] = [
                    'label' => $label,
                    'url' => kau_site_sanitize_link_url((string) ($l['url'] ?? '#')),
                ];
            }
        }
        $data['global'] = $g;
        kau_site_save_data($data);
        $_GET['saved'] = '1';
    }
    $data = kau_site_get_data();
    $g = is_array($data['global'] ?? null) ? $data['global'] : [];
    $nav = (array) ($g['navigation'] ?? []);
    $shop = (array) ($g['shop'] ?? []);
    $footer = (array) ($g['footer'] ?? []);
    kau_site_admin_accordion_styles();
    ?>
    <div class="wrap kau-about-wrap">
      <h1>全域設定</h1>
      <p>導覽列 / 頁腳 / 公司資訊（所有頁面共用）。</p>
      <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p>已儲存。</p></div><?php endif; ?>
      <form method="post">
        <?php wp_nonce_field('kau_site_global'); ?>
        <input type="hidden" name="kau_global_action" value="save">

        <details open>
          <summary><?php echo kau_site_admin_thumb('company'); ?><span class="kau-label">公司基本資料</span></summary>
          <div>
            <?php
            $fields = [
                'company_name'=>'公司名稱', 'postal_code'=>'郵遞區號', 'phone'=>'電話',
                'address_line_1'=>'地址 1', 'address_line_2'=>'地址 2', 'address_line_3'=>'地址 3（選填）',
                'email'=>'E-mail',
                'hours'=>'營業時間', 'access'=>'交通方式'
            ];
            foreach ($fields as $f => $label): ?>
              <p><label><strong><?php echo esc_html($label); ?></strong><br><input class="regular-text" name="g[<?php echo $f; ?>]" value="<?php echo esc_attr($g[$f] ?? ''); ?>"></label></p>
            <?php endforeach; ?>
            <p><label><strong>補充說明（footer 地址下方加註）</strong><br><textarea class="large-text" rows="3" name="g[note]" placeholder="例：※当オフィスは事務所のみとなっており、実店舗での販売・展示は行っておりません。"><?php echo esc_textarea($g['note'] ?? ''); ?></textarea><span style="color:#646970;font-size:11.5px">可換行；每行會自動成為 footer 地址塊的一行</span></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('external'); ?><span class="kau-label">外部連結</span></summary>
          <div>
            <p><label><strong>聯絡頁面 URL</strong><br><input class="regular-text" name="g[contact_url]" value="<?php echo esc_attr($g['contact_url'] ?? ''); ?>"></label></p>
            <p><label><strong>Amazon URL</strong><br><input class="regular-text" name="g[amazon_url]" value="<?php echo esc_attr($g['amazon_url'] ?? ''); ?>"></label></p>
            <p><label><strong>樂天 URL</strong><br><input class="regular-text" name="g[rakuten_url]" value="<?php echo esc_attr($g['rakuten_url'] ?? ''); ?>"></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('shop'); ?><span class="kau-label">購物 / Coming Soon 文案</span></summary>
          <div>
            <p><label><strong>Amazon 按鈕文字</strong><br><input class="regular-text" name="shop[amazon_label]" value="<?php echo esc_attr($shop['amazon_label'] ?? ''); ?>"></label></p>
            <p><label><strong>樂天按鈕文字</strong><br><input class="regular-text" name="shop[rakuten_label]" value="<?php echo esc_attr($shop['rakuten_label'] ?? ''); ?>"></label></p>
            <p><label><strong>Coming Soon 小標</strong><br><input class="regular-text" name="shop[coming_soon_label]" value="<?php echo esc_attr($shop['coming_soon_label'] ?? ''); ?>"></label></p>
            <p><label><strong>Coming Soon 標題</strong><br><input class="regular-text" name="shop[coming_soon_title]" value="<?php echo esc_attr($shop['coming_soon_title'] ?? ''); ?>"></label></p>
            <p><label><strong>Coming Soon 說明</strong><br><textarea class="large-text" rows="3" name="shop[coming_soon_description]"><?php echo esc_textarea($shop['coming_soon_description'] ?? ''); ?></textarea></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('nav'); ?><span class="kau-label">導覽列 Navigation</span></summary>
          <div>
            <?php
            $navFields = [
                'home_label'=>'首頁（日文）', 'home_en'=>'首頁（英文）',
                'about_label'=>'會社概要（日文）', 'about_en'=>'會社概要（英文）',
                'products_label'=>'製品情報（日文）', 'products_en'=>'製品情報（英文）',
                'news_label'=>'最新情報（日文）', 'news_en'=>'最新情報（英文）',
                'shop_label'=>'購物按鈕文字', 'contact_label'=>'聯絡按鈕文字'
            ];
            foreach ($navFields as $f => $label): ?>
              <p><label><strong><?php echo esc_html($label); ?></strong><br><input class="regular-text" name="nav[<?php echo $f; ?>]" value="<?php echo esc_attr($nav[$f] ?? ''); ?>"></label></p>
            <?php endforeach; ?>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('footer'); ?><span class="kau-label">頁腳 Footer</span></summary>
          <div>
            <p><label><strong>版權聲明</strong><br><input class="regular-text" name="footer[copyright]" value="<?php echo esc_attr($footer['copyright'] ?? ''); ?>"></label></p>
            <p><label><strong>後綴文字</strong><br><input class="regular-text" name="footer[suffix]" value="<?php echo esc_attr($footer['suffix'] ?? ''); ?>"></label></p>
            <?php foreach (['products'=>'Products', 'company'=>'Company', 'support'=>'Support'] as $col => $title): ?>
              <hr style="margin:18px 0">
              <p><strong><?php echo esc_html($title); ?> 欄</strong></p>
              <p><label>欄標題<br><input class="regular-text" name="footer[<?php echo $col; ?>_title]" value="<?php echo esc_attr($footer[$col . '_title'] ?? ''); ?>"></label></p>
              <div class="row-list" id="kau-footer-<?php echo $col; ?>">
                <?php foreach ((array) ($footer[$col . '_links'] ?? []) as $i => $link): ?>
                  <div class="row-item">
                    <span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span>
                    <div class="row-grid">
                      <label><strong>文字</strong><input class="regular-text" name="footer[<?php echo $col; ?>_links][<?php echo $i; ?>][label]" value="<?php echo esc_attr($link['label'] ?? ''); ?>"></label>
                      <label><strong>URL</strong><input class="regular-text" name="footer[<?php echo $col; ?>_links][<?php echo $i; ?>][url]" value="<?php echo esc_attr($link['url'] ?? '#'); ?>"></label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="button add-btn" data-add="footer-<?php echo $col; ?>" data-name="footer[<?php echo $col; ?>_links]">＋ 新增連結</button>
            <?php endforeach; ?>
          </div>
        </details>

        <?php kau_site_admin_savebar(); ?>
      </form>
    </div>
    <?php
    kau_site_admin_accordion_js([
        'footer-products' => ['label','url'],
        'footer-company' => ['label','url'],
        'footer-support' => ['label','url'],
    ]);
    ?>
    <?php
}

// 共用：accordion / row-item / add/remove 樣式（含縮圖示意）
function kau_site_admin_accordion_styles(): void {
    ?>
    <style>
      .kau-about-wrap { max-width: 980px; }
      .kau-about-wrap details { background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; margin-bottom: 14px; }
      .kau-about-wrap details summary { padding: 12px 18px; cursor: pointer; font-weight: 600; font-size: 14px; color: #001b3d; list-style: none; user-select: none; display: flex; align-items: center; gap: 14px; }
      .kau-about-wrap details summary::before { content: '▸'; color: #d4a574; transition: transform .15s; display: inline-block; }
      .kau-about-wrap details[open] summary::before { content: '▾'; }
      .kau-about-wrap details summary:hover { background: #f6f7f7; }
      .kau-about-wrap details > div { padding: 16px 18px 18px; border-top: 1px solid #e2ddd7; }
      .kau-about-wrap .row-list { display: flex; flex-direction: column; gap: 8px; }
      .kau-about-wrap .row-item { background: #fafaf8; border: 1px solid #e2ddd7; border-radius: 6px; padding: 12px; position: relative; }
      .kau-about-wrap .row-item .row-grid { display: grid; gap: 8px; }
      .kau-about-wrap .row-item .rm { position: absolute; top: 8px; right: 8px; }
      .kau-about-wrap .add-btn { margin-top: 8px; border-style: dashed; }
      /* section 縮圖示意 — 強制鎖死 110x60，防止 SVG 膨脹 */
      .kau-about-wrap .kau-thumb { display: inline-block !important; width: 110px !important; height: 60px !important; max-width: 110px !important; max-height: 60px !important; border-radius: 4px; flex-shrink: 0 !important; background: #fafaf8; border: 1px solid #e2ddd7; overflow: hidden !important; vertical-align: middle; }
      .kau-about-wrap .kau-thumb svg { display: block !important; width: 110px !important; height: 60px !important; }
      .kau-about-wrap details summary .kau-label { flex: 1; }
      .kau-about-wrap p label { display: block; }
      .kau-about-wrap p label strong { display: inline-block; margin-bottom: 4px; }
      /* 列項目：標籤固定欄寬，欄位對齊（server 端與 JS 新增列共用同一 markup） */
      .kau-about-wrap .row-item .row-grid label { display: grid; grid-template-columns: 110px 1fr; align-items: center; gap: 10px; margin: 0; }
      .kau-about-wrap .row-item .row-grid label:has(textarea) { align-items: start; }
      .kau-about-wrap .row-item .row-grid label strong { margin: 0; font-weight: 600; font-size: 12.5px; color: #1d2327; }
      .kau-about-wrap .row-item .row-grid input.regular-text,
      .kau-about-wrap .row-item .row-grid textarea.large-text { width: 100%; max-width: 460px; }
      /* sticky 儲存列 */
      .kau-savebar { position: sticky; bottom: 0; z-index: 60; display: flex; align-items: center; gap: 10px; margin-top: 18px; padding: 12px 16px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 -4px 14px rgba(0,0,0,.07); }
      .kau-savebar .kau-savebar-spacer { flex: 1; }
      .kau-savebar .kau-dirty-hint { visibility: hidden; color: #996800; font-size: 12.5px; display: inline-flex; align-items: center; gap: 6px; }
      .kau-savebar .kau-dirty-hint::before { content: '●'; color: #dba617; font-size: 10px; }
    </style>
    <?php
}

// sticky 儲存列：儲存按鈕永遠看得到＋未儲存變更提示＋展開/收合全部。放在 <form> 內、</form> 前。
function kau_site_admin_savebar(): void {
    ?>
    <div class="kau-savebar">
      <button type="button" class="button" data-acc="open">全部展開</button>
      <button type="button" class="button" data-acc="close">全部收合</button>
      <span class="kau-savebar-spacer"></span>
      <span class="kau-dirty-hint">有尚未儲存的變更</span>
      <button class="button button-primary button-hero" type="submit">💾 儲存全部</button>
    </div>
    <script>
    (function(){
      var bar = document.querySelector('.kau-savebar'); if (!bar) return;
      var form = bar.closest('form'); if (!form) return;
      var hint = bar.querySelector('.kau-dirty-hint');
      var dirty = false;
      function mark(){ if (dirty) return; dirty = true; hint.style.visibility = 'visible'; }
      form.addEventListener('input', mark);
      form.addEventListener('change', mark);
      form.addEventListener('click', function(e){ if (e.target.closest('.kau-rm, [data-add]')) mark(); });
      function guard(e){ if (dirty) { e.preventDefault(); e.returnValue = ''; } }
      window.addEventListener('beforeunload', guard);
      form.addEventListener('submit', function(){ dirty = false; window.removeEventListener('beforeunload', guard); });
      bar.querySelectorAll('[data-acc]').forEach(function(b){
        b.addEventListener('click', function(){
          document.querySelectorAll('.kau-about-wrap details').forEach(function(d){ d.open = (b.dataset.acc === 'open'); });
        });
      });
    })();
    </script>
    <?php
}

// 縮圖 SVG：每個 section 對應一個簡略示意圖
function kau_site_admin_thumb(string $kind): string {
    $svgs = [
        'hero' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#001b3d"/><text x="55" y="22" text-anchor="middle" fill="#fff" font-size="9" font-weight="700">標題</text><text x="55" y="34" text-anchor="middle" fill="#d4a574" font-size="9" font-weight="700">強調文字</text><rect x="20" y="44" width="28" height="10" fill="#d4a574" rx="2"/><rect x="62" y="44" width="28" height="10" fill="none" stroke="#fff" rx="2"/></svg>',
        'philosophy' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#f5f3ef"/><text x="55" y="18" text-anchor="middle" fill="#d4a574" font-size="6">PHILOSOPHY</text><line x1="20" y1="32" x2="90" y2="32" stroke="#001b3d" stroke-width="0.5"/><line x1="20" y1="40" x2="90" y2="40" stroke="#001b3d" stroke-width="0.5"/><line x1="20" y1="48" x2="70" y2="48" stroke="#001b3d" stroke-width="0.5"/></svg>',
        'feature' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="55" height="60" fill="#e2ddd7"/><circle cx="27" cy="30" r="14" fill="#fff"/><rect x="60" y="10" width="45" height="8" fill="#001b3d"/><line x1="60" y1="24" x2="105" y2="24" stroke="#001b3d" stroke-width="0.5"/><line x1="60" y1="30" x2="100" y2="30" stroke="#001b3d" stroke-width="0.5"/><rect x="60" y="40" width="20" height="14" fill="#001b3d"/><rect x="84" y="40" width="20" height="14" fill="#001b3d"/></svg>',
        'values' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#001b3d"/><g fill="#fff"><circle cx="22" cy="22" r="5"/><line x1="13" y1="35" x2="31" y2="35" stroke="#fff" stroke-width="1"/><line x1="13" y1="42" x2="31" y2="42" stroke="#fff" stroke-width="0.5"/><circle cx="55" cy="22" r="5"/><line x1="46" y1="35" x2="64" y2="35" stroke="#fff" stroke-width="1"/><line x1="46" y1="42" x2="64" y2="42" stroke="#fff" stroke-width="0.5"/><circle cx="88" cy="22" r="5"/><line x1="79" y1="35" x2="97" y2="35" stroke="#fff" stroke-width="1"/><line x1="79" y1="42" x2="97" y2="42" stroke="#fff" stroke-width="0.5"/></g></svg>',
        'cta' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#001b3d"/><stop offset="1" stop-color="#0d3a6e"/></linearGradient></defs><rect width="110" height="60" fill="url(#g)"/><text x="55" y="18" text-anchor="middle" fill="#d4a574" font-size="5">CTA</text><text x="55" y="30" text-anchor="middle" fill="#fff" font-size="9" font-weight="700">行動呼籲</text><rect x="20" y="40" width="28" height="10" fill="#d4a574" rx="2"/><rect x="62" y="40" width="28" height="10" fill="none" stroke="#fff" rx="2"/></svg>',
        // about
        'about-hero' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#f5f3ef"/><text x="10" y="34" fill="#001b3d" font-size="13" font-weight="700">会社概要</text></svg>',
        'statement' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#001b3d"/><line x1="15" y1="22" x2="95" y2="22" stroke="#fff" stroke-width="0.6"/><line x1="15" y1="30" x2="95" y2="30" stroke="#fff" stroke-width="0.6"/><line x1="15" y1="38" x2="75" y2="38" stroke="#d4a574" stroke-width="0.8"/></svg>',
        'principles' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#fff"/><g><circle cx="20" cy="14" r="3" fill="#0d3a6e"/><text x="27" y="17" fill="#0d3a6e" font-size="7" font-weight="700">Work</text><line x1="20" y1="22" x2="90" y2="22" stroke="#001b3d" stroke-width="0.4"/><circle cx="20" cy="32" r="3" fill="#0d3a6e"/><text x="27" y="35" fill="#0d3a6e" font-size="7" font-weight="700">Learn</text><line x1="20" y1="40" x2="90" y2="40" stroke="#001b3d" stroke-width="0.4"/><circle cx="20" cy="50" r="3" fill="#0d3a6e"/><text x="27" y="53" fill="#0d3a6e" font-size="7" font-weight="700">Relax</text></g></svg>',
        'craft' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="55" height="60" fill="#e2ddd7"/><rect x="14" y="14" width="27" height="32" fill="#fff" stroke="#001b3d" stroke-width="0.5"/><line x1="60" y1="14" x2="100" y2="14" stroke="#001b3d" stroke-width="0.6"/><line x1="60" y1="22" x2="100" y2="22" stroke="#001b3d" stroke-width="0.4"/><line x1="60" y1="28" x2="100" y2="28" stroke="#001b3d" stroke-width="0.4"/><line x1="60" y1="34" x2="90" y2="34" stroke="#001b3d" stroke-width="0.4"/></svg>',
        'profile' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#fff"/><g stroke="#e2ddd7" stroke-width="0.5"><line x1="0" y1="20" x2="110" y2="20"/><line x1="0" y1="35" x2="110" y2="35"/><line x1="0" y1="50" x2="110" y2="50"/></g><g fill="#001b3d" font-size="5"><text x="8" y="14">會社名</text><text x="8" y="29">設立</text><text x="8" y="44">代表者</text></g><g fill="#646970" font-size="4.5"><text x="50" y="14">禾宇株式会社</text><text x="50" y="29">2026/5/10</text><text x="50" y="44">WU YING CHEN</text></g></svg>',
        'history' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#001b3d"/><line x1="22" y1="10" x2="22" y2="55" stroke="#d4a574" stroke-width="0.6"/><circle cx="22" cy="20" r="2.5" fill="#d4a574"/><circle cx="22" cy="38" r="2.5" fill="#d4a574"/><g fill="#fff" font-size="6" font-weight="700"><text x="32" y="22">2026</text><text x="32" y="40">2025</text></g></svg>',
        'access' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#e2ddd7"/><rect x="14" y="10" width="82" height="36" fill="#fff" stroke="#001b3d" stroke-width="0.5"/><circle cx="55" cy="28" r="4" fill="#d4a574"/><rect x="38" y="50" width="34" height="7" fill="#001b3d" rx="2"/></svg>',
        // global
        'company' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#f5f3ef"/><rect x="14" y="14" width="82" height="32" fill="#fff" stroke="#001b3d" stroke-width="0.5"/><g fill="#646970" font-size="4.5"><text x="18" y="22">公司名</text><text x="18" y="30">電話</text><text x="18" y="38">地址</text></g></svg>',
        'external' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#fff"/><g><rect x="14" y="14" width="38" height="14" fill="#ff9900" rx="2"/><text x="33" y="24" text-anchor="middle" fill="#fff" font-size="6" font-weight="700">Amazon</text><rect x="58" y="14" width="38" height="14" fill="#bf0000" rx="2"/><text x="77" y="24" text-anchor="middle" fill="#fff" font-size="6" font-weight="700">樂天</text><rect x="14" y="36" width="82" height="14" fill="#001b3d" rx="2"/><text x="55" y="46" text-anchor="middle" fill="#fff" font-size="6" font-weight="700">聯絡</text></g></svg>',
        'shop' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#fff"/><rect x="13" y="13" width="84" height="34" fill="#f5f3ef" stroke="#001b3d" stroke-width="0.5"/><circle cx="36" cy="30" r="9" fill="#ff9900"/><circle cx="73" cy="30" r="9" fill="#bf0000"/><text x="36" y="33" text-anchor="middle" fill="#fff" font-size="8" font-weight="700">A</text><text x="73" y="33" text-anchor="middle" fill="#fff" font-size="8" font-weight="700">R</text></svg>',
        'nav' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#fff"/><rect x="0" y="0" width="110" height="14" fill="#001b3d"/><g fill="#d4a574" font-size="3.5" font-weight="700"><text x="6" y="9">KAU</text></g><g fill="#fff" font-size="3.5"><text x="32" y="9">ホーム</text><text x="50" y="9">会社</text><text x="63" y="9">製品</text><text x="78" y="9">最新</text></g><rect x="95" y="3" width="12" height="8" fill="#d4a574" rx="1"/></svg>',
        'footer' => '<svg width="110" height="60" viewBox="0 0 110 60" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"><rect width="110" height="60" fill="#001b3d"/><g fill="#d4a574" font-size="4" font-weight="700"><text x="8" y="14">KAU</text></g><g fill="#fff" font-size="3.5"><text x="34" y="14">Products</text><text x="34" y="22">·</text><text x="60" y="14">Company</text><text x="60" y="22">·</text><text x="86" y="14">Support</text><text x="86" y="22">·</text></g><line x1="5" y1="46" x2="105" y2="46" stroke="#d4a574" stroke-width="0.3"/><text x="55" y="55" text-anchor="middle" fill="#fff" font-size="3.5">© 2026 KAU</text></svg>',
    ];
    $svg = $svgs[$kind] ?? '<svg viewBox="0 0 110 60"><rect width="110" height="60" fill="#e2ddd7"/></svg>';
    return '<span class="kau-thumb">' . $svg . '</span>';
}

// 共用：媒體庫挑圖 + 列項目刪除 + 新增（templates 由 caller 給）
function kau_site_admin_accordion_js(array $templates = []): void {
    $tpl_json = wp_json_encode($templates);
    ?>
    <script>
    (function(){
      // 用 event delegation，新增的 row 也可以開媒體庫
      document.addEventListener('click', function(e){
        if (!(e.target.classList && e.target.classList.contains('kau-pick-img'))) return;
        if (!window.wp || !wp.media) return;
        var btn = e.target;
        var p = btn.parentElement || btn.closest('label');
        var input = p && p.querySelector('.kau-img-url');
        if (!input) return;
        var frame = wp.media({ title: '選擇圖片', multiple: false, library: { type: 'image' } });
        frame.on('select', function(){ input.value = frame.state().get('selection').first().toJSON().url || ''; });
        frame.open();
      });
      document.addEventListener('click', function(e){
        if (e.target.classList && e.target.classList.contains('kau-rm')) {
          e.preventDefault();
          if (!confirm('刪掉這列？')) return;
          var row = e.target.closest('.row-item'); if (row) row.remove();
        }
      });
      var fieldDefs = <?php echo $tpl_json ?: '{}'; ?>;
      document.querySelectorAll('[data-add]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var key = btn.dataset.add;
          var listId = 'kau-' + key;
          var list = document.getElementById(listId);
          if (!list) return;
          var fields = fieldDefs[key];
          if (!fields) return;
          var nameBase = btn.dataset.name || key.replace(/-/g, '_');
          var newIdx = list.children.length;
          var div = document.createElement('div');
          div.className = 'row-item';
          var inner = '<span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span><div class="row-grid">';
          fields.forEach(function(f){
            var name = f, type = 'input';
            if (f.indexOf(':') > 0) { var parts = f.split(':'); name = parts[0]; type = parts[1]; }
            if (type === 'textarea') {
              inner += '<label><strong>' + name + '</strong><textarea class="large-text" rows="2" name="' + nameBase + '[' + newIdx + '][' + name + ']"></textarea></label>';
            } else if (type === 'img') {
              inner += '<label><strong>' + name + ' Icon URL（SVG / PNG）</strong><span style="display:flex;gap:6px;align-items:center"><input class="regular-text kau-img-url" name="' + nameBase + '[' + newIdx + '][' + name + ']"><button class="button kau-pick-img" type="button">媒體庫</button></span></label>';
            } else {
              inner += '<label><strong>' + name + '</strong><input class="regular-text" name="' + nameBase + '[' + newIdx + '][' + name + ']"></label>';
            }
          });
          inner += '</div>';
          div.innerHTML = inner;
          list.appendChild(div);
        });
      });
    })();
    </script>
    <?php
}

function kau_site_about_admin(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');
    if (isset($_POST['kau_about_action'])) {
        check_admin_referer('kau_site_about');
        $data = kau_site_get_data();
        if (!isset($data['about']) || !is_array($data['about'])) $data['about'] = [];
        $about = $data['about'];

        $hero = (array) ($_POST['hero'] ?? []);
        $about['hero'] = [
            'title' => sanitize_text_field((string) ($hero['title'] ?? '')),
            'subtitle' => sanitize_text_field((string) ($hero['subtitle'] ?? '')),
        ];
        $about['statement'] = [
            'text' => sanitize_textarea_field((string) ($_POST['statement_text'] ?? '')),
        ];
        $about['craft'] = [
            'eyebrow' => sanitize_text_field((string) ($_POST['craft_eyebrow'] ?? '')),
            'title' => sanitize_text_field((string) ($_POST['craft_title'] ?? '')),
            'description_1' => sanitize_textarea_field((string) ($_POST['craft_description_1'] ?? '')),
            'description_2' => sanitize_textarea_field((string) ($_POST['craft_description_2'] ?? '')),
            'image' => esc_url_raw((string) ($_POST['craft_image'] ?? '')),
        ];
        $about['access'] = [
            'eyebrow' => sanitize_text_field((string) ($_POST['access_eyebrow'] ?? '')),
            'title' => sanitize_text_field((string) ($_POST['access_title'] ?? '')),
            'place_name' => sanitize_text_field((string) ($_POST['access_place_name'] ?? '')),
            'button_label' => sanitize_text_field((string) ($_POST['access_button_label'] ?? '')),
            'map_image' => esc_url_raw((string) ($_POST['access_map_image'] ?? '')),
        ];
        $about['principles'] = ['items' => []];
        foreach ((array) ($_POST['principles'] ?? []) as $p) {
            $title = sanitize_text_field((string) ($p['title'] ?? ''));
            if ($title === '') continue;
            $about['principles']['items'][] = [
                'label' => sanitize_text_field((string) ($p['label'] ?? '')),
                'title' => $title,
                'description' => sanitize_textarea_field((string) ($p['description'] ?? '')),
            ];
        }
        $about['profile'] = [
            'eyebrow' => sanitize_text_field((string) ($_POST['profile_eyebrow'] ?? '')),
            'title' => sanitize_text_field((string) ($_POST['profile_title'] ?? '')),
            'items' => [],
        ];
        foreach ((array) ($_POST['profile'] ?? []) as $p) {
            $label = sanitize_text_field((string) ($p['label'] ?? ''));
            if ($label === '') continue;
            $about['profile']['items'][] = [
                'label' => $label,
                'sublabel' => sanitize_text_field((string) ($p['sublabel'] ?? '')),
                'value' => sanitize_text_field((string) ($p['value'] ?? '')),
            ];
        }
        $about['history'] = [
            'eyebrow' => sanitize_text_field((string) ($_POST['history_eyebrow'] ?? '')),
            'title' => sanitize_text_field((string) ($_POST['history_title'] ?? '')),
            'items' => [],
        ];
        foreach ((array) ($_POST['history'] ?? []) as $h) {
            $year = sanitize_text_field((string) ($h['year'] ?? ''));
            $title = sanitize_text_field((string) ($h['title'] ?? ''));
            if ($year === '' && $title === '') continue;
            $about['history']['items'][] = [
                'year' => $year,
                'title' => $title,
                'description' => sanitize_text_field((string) ($h['description'] ?? '')),
            ];
        }
        $data['about'] = $about;
        $statement_text = (string) ($about['statement']['text'] ?? '');
        if ($statement_text !== '') {
            if (!isset($data['home']) || !is_array($data['home'])) $data['home'] = [];
            if (!isset($data['home']['intro']) || !is_array($data['home']['intro'])) $data['home']['intro'] = [];
            if (!isset($data['home']['philosophy']) || !is_array($data['home']['philosophy'])) $data['home']['philosophy'] = [];
            $data['home']['intro']['eyebrow'] = $data['home']['intro']['eyebrow'] ?? 'Philosophy';
            $data['home']['intro']['title'] = $statement_text;
            $data['home']['philosophy']['eyebrow'] = $data['home']['philosophy']['eyebrow'] ?? 'Philosophy';
            $data['home']['philosophy']['title'] = $statement_text;
        }
        kau_site_save_data($data);
        $_GET['saved'] = '1';
    }

    $data = kau_site_get_data();
    $about = is_array($data['about'] ?? null) ? $data['about'] : [];
    $hero = (array) ($about['hero'] ?? []);
    $statement = (string) ($about['statement']['text'] ?? '');
    $craft = (array) ($about['craft'] ?? []);
    $principles = (array) ($about['principles']['items'] ?? []);
    $profile_eyebrow = (string) ($about['profile']['eyebrow'] ?? '');
    $profile_title = (string) ($about['profile']['title'] ?? '');
    $profile = (array) ($about['profile']['items'] ?? []);
    $history_eyebrow = (string) ($about['history']['eyebrow'] ?? '');
    $history_title = (string) ($about['history']['title'] ?? '');
    $history = (array) ($about['history']['items'] ?? []);
    $access = (array) ($about['access'] ?? []);
    ?>
    <?php kau_site_admin_accordion_styles(); ?>
    <div class="wrap kau-about-wrap">
      <h1>會社概要 管理</h1>
      <p>編輯後立即生效於 <a href="<?php echo esc_url(home_url('/about.html')); ?>" target="_blank">about.html</a> 頁面。</p>
      <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p>已儲存。</p></div><?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('kau_site_about'); ?>
        <input type="hidden" name="kau_about_action" value="save">

        <details open>
          <summary><?php echo kau_site_admin_thumb('about-hero'); ?><span class="kau-label">頁首 Hero（標題 / 副標題）</span></summary>
          <div>
            <p><label><strong>標題</strong><br><input class="regular-text" name="hero[title]" value="<?php echo esc_attr($hero['title'] ?? ''); ?>"></label></p>
            <p><label><strong>副標題</strong><br><input class="regular-text" name="hero[subtitle]" value="<?php echo esc_attr($hero['subtitle'] ?? ''); ?>"></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('statement'); ?><span class="kau-label">品牌宣言 Statement</span></summary>
          <div>
            <p><label><strong>宣言文字</strong><br><textarea class="large-text" rows="4" name="statement_text"><?php echo esc_textarea($statement); ?></textarea></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('principles'); ?><span class="kau-label">品牌原則 Principles (Work / Learn / Relax)</span></summary>
          <div>
            <div class="row-list" id="kau-principles">
              <?php foreach ($principles as $i => $p): ?>
                <div class="row-item">
                  <span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span>
                  <div class="row-grid">
                    <label><strong>標籤</strong><input class="regular-text" name="principles[<?php echo $i; ?>][label]" value="<?php echo esc_attr($p['label'] ?? ''); ?>" placeholder="例：Work"></label>
                    <label><strong>標題</strong><input class="regular-text" name="principles[<?php echo $i; ?>][title]" value="<?php echo esc_attr($p['title'] ?? ''); ?>"></label>
                    <label><strong>說明</strong><textarea class="large-text" rows="2" name="principles[<?php echo $i; ?>][description]"><?php echo esc_textarea($p['description'] ?? ''); ?></textarea></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="button add-btn" data-add="principles">＋ 新增原則</button>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('craft'); ?><span class="kau-label">製造強項 Craft</span></summary>
          <div>
            <p><label><strong>Eyebrow（小標）</strong><br><input class="regular-text" name="craft_eyebrow" value="<?php echo esc_attr($craft['eyebrow'] ?? ''); ?>"></label></p>
            <p><label><strong>標題</strong><br><input class="regular-text" name="craft_title" value="<?php echo esc_attr($craft['title'] ?? ''); ?>"></label></p>
            <p><label><strong>說明 段落 1</strong><br><textarea class="large-text" rows="5" name="craft_description_1"><?php echo esc_textarea($craft['description_1'] ?? ''); ?></textarea></label></p>
            <p><label><strong>說明 段落 2</strong><br><textarea class="large-text" rows="5" name="craft_description_2"><?php echo esc_textarea($craft['description_2'] ?? ''); ?></textarea></label></p>
            <p><label><strong>圖片 URL</strong><br><input class="regular-text kau-img-url" name="craft_image" value="<?php echo esc_attr($craft['image'] ?? ''); ?>"> <button class="button kau-pick-img" type="button">媒體庫</button></label></p>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('profile'); ?><span class="kau-label">公司概要 Profile（會社名 / 設立 / 代表者 等列表）</span></summary>
          <div>
            <p><label><strong>Eyebrow</strong><br><input class="regular-text" name="profile_eyebrow" value="<?php echo esc_attr($profile_eyebrow); ?>"></label></p>
            <p><label><strong>標題</strong><br><input class="regular-text" name="profile_title" value="<?php echo esc_attr($profile_title); ?>"></label></p>
            <div class="row-list" id="kau-profile">
              <?php foreach ($profile as $i => $p): ?>
                <div class="row-item">
                  <span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span>
                  <div class="row-grid">
                    <label><strong>項目（日文）</strong><input class="regular-text" name="profile[<?php echo $i; ?>][label]" value="<?php echo esc_attr($p['label'] ?? ''); ?>" placeholder="例：会社名"></label>
                    <label><strong>項目（英文）</strong><input class="regular-text" name="profile[<?php echo $i; ?>][sublabel]" value="<?php echo esc_attr($p['sublabel'] ?? ''); ?>" placeholder="例：Company"></label>
                    <label><strong>內容</strong><input class="regular-text" name="profile[<?php echo $i; ?>][value]" value="<?php echo esc_attr($p['value'] ?? ''); ?>"></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="button add-btn" data-add="profile">＋ 新增項目</button>
          </div>
        </details>

        <details open>
          <summary><?php echo kau_site_admin_thumb('history'); ?><span class="kau-label">沿革 History</span></summary>
          <div>
            <p><label><strong>Eyebrow</strong><br><input class="regular-text" name="history_eyebrow" value="<?php echo esc_attr($history_eyebrow); ?>"></label></p>
            <p><label><strong>標題</strong><br><input class="regular-text" name="history_title" value="<?php echo esc_attr($history_title); ?>"></label></p>
            <div class="row-list" id="kau-history">
              <?php foreach ($history as $i => $h): ?>
                <div class="row-item">
                  <span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span>
                  <div class="row-grid">
                    <label><strong>年份</strong><input class="regular-text" name="history[<?php echo $i; ?>][year]" value="<?php echo esc_attr($h['year'] ?? ''); ?>" placeholder="例：2026"></label>
                    <label><strong>事件標題</strong><input class="regular-text" name="history[<?php echo $i; ?>][title]" value="<?php echo esc_attr($h['title'] ?? ''); ?>"></label>
                    <label><strong>日期/說明</strong><input class="regular-text" name="history[<?php echo $i; ?>][description]" value="<?php echo esc_attr($h['description'] ?? ''); ?>" placeholder="例：2026/5/10"></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="button add-btn" data-add="history">＋ 新增沿革</button>
          </div>
        </details>

        <details>
          <summary><?php echo kau_site_admin_thumb('access'); ?><span class="kau-label">交通・聯絡 Access</span></summary>
          <div>
            <p><label><strong>Eyebrow</strong><br><input class="regular-text" name="access_eyebrow" value="<?php echo esc_attr($access['eyebrow'] ?? ''); ?>"></label></p>
            <p><label><strong>標題</strong><br><input class="regular-text" name="access_title" value="<?php echo esc_attr($access['title'] ?? ''); ?>"></label></p>
            <p><label><strong>地點名稱</strong><br><input class="regular-text" name="access_place_name" value="<?php echo esc_attr($access['place_name'] ?? ''); ?>"></label></p>
            <p><label><strong>按鈕文字</strong><br><input class="regular-text" name="access_button_label" value="<?php echo esc_attr($access['button_label'] ?? ''); ?>"></label></p>
            <p><label><strong>地圖圖片 URL</strong><br><input class="regular-text kau-img-url" name="access_map_image" value="<?php echo esc_attr($access['map_image'] ?? ''); ?>"> <button class="button kau-pick-img" type="button">媒體庫</button></label></p>
          </div>
        </details>

        <?php kau_site_admin_savebar(); ?>
      </form>
    </div>
    <script>
    (function(){
      // 媒體庫挑圖（craft / access 兩個按鈕共用）
      document.querySelectorAll('.kau-pick-img').forEach(function(btn){
        btn.addEventListener('click', function(){
          if (!window.wp || !wp.media) return;
          var input = btn.previousElementSibling;
          while (input && !input.classList.contains('kau-img-url')) input = input.previousElementSibling;
          if (!input) return;
          var frame = wp.media({ title: '選擇圖片', multiple: false, library: { type: 'image' } });
          frame.on('select', function(){ input.value = frame.state().get('selection').first().toJSON().url || ''; });
          frame.open();
        });
      });
      // 刪列
      document.addEventListener('click', function(e){
        if (e.target.classList && e.target.classList.contains('kau-rm')) {
          e.preventDefault();
          if (!confirm('刪掉這列？')) return;
          var row = e.target.closest('.row-item'); if (row) row.remove();
        }
      });
      // 新增列
      var templates = {
        principles: '<div class="row-item"><span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span><div class="row-grid"><label><strong>標籤</strong><input class="regular-text" name="principles[__I__][label]" placeholder="例：Work"></label><label><strong>標題</strong><input class="regular-text" name="principles[__I__][title]"></label><label><strong>說明</strong><textarea class="large-text" rows="2" name="principles[__I__][description]"></textarea></label></div></div>',
        profile: '<div class="row-item"><span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span><div class="row-grid"><label><strong>項目（日文）</strong><input class="regular-text" name="profile[__I__][label]"></label><label><strong>項目（英文）</strong><input class="regular-text" name="profile[__I__][sublabel]"></label><label><strong>內容</strong><input class="regular-text" name="profile[__I__][value]"></label></div></div>',
        history: '<div class="row-item"><span class="rm"><button type="button" class="button button-small kau-rm">✕</button></span><div class="row-grid"><label><strong>年份</strong><input class="regular-text" name="history[__I__][year]"></label><label><strong>事件標題</strong><input class="regular-text" name="history[__I__][title]"></label><label><strong>日期/說明</strong><input class="regular-text" name="history[__I__][description]"></label></div></div>',
      };
      document.querySelectorAll('[data-add]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var key = btn.dataset.add; var list = document.getElementById('kau-' + key);
          var newIdx = list.children.length;
          var html = templates[key].replace(/__I__/g, newIdx);
          var tmp = document.createElement('div'); tmp.innerHTML = html;
          list.appendChild(tmp.firstChild);
        });
      });
    })();
    </script>
    <?php
}

function kau_site_images_admin(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');
    $nonce = wp_create_nonce('kau_site_save');
    ?>
    <div class="wrap">
        <h1>圖片壓縮工具</h1>
        <p style="color:#646970">瀏覽器本地壓縮 → 一鍵推進 WordPress 媒體庫。檔案不會經第三方伺服器。</p>

        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-top:14px">
            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
                <label style="display:flex;flex-direction:column;font-size:12px"><strong style="margin-bottom:4px">輸出格式</strong>
                    <select id="kau-img-fmt" style="min-width:130px"><option value="webp" selected>WebP（推薦）</option><option value="jpeg">JPEG</option><option value="keep">維持原格式</option></select>
                </label>
                <label style="display:flex;flex-direction:column;font-size:12px"><strong style="margin-bottom:4px">品質 (1-100)</strong>
                    <input id="kau-img-quality" type="number" value="82" min="1" max="100" style="width:90px">
                </label>
                <label style="display:flex;flex-direction:column;font-size:12px"><strong style="margin-bottom:4px">長邊上限 (px)</strong>
                    <input id="kau-img-maxsize" type="number" value="1600" min="200" max="4000" style="width:110px">
                </label>
                <label style="display:flex;flex-direction:column;font-size:12px"><strong style="margin-bottom:4px">目標大小上限 (KB)<br><span style="font-weight:400;color:#646970">留空=不限制</span></strong>
                    <input id="kau-img-target" type="number" value="1024" min="0" max="10000" placeholder="例: 1024 (1MB)" style="width:130px">
                </label>
                <label style="display:flex;flex-direction:column;font-size:12px;align-items:flex-start"><strong style="margin-bottom:4px">壓縮後</strong>
                    <span style="display:inline-flex;gap:6px;align-items:center"><input id="kau-img-autoup" type="checkbox" checked> 自動推進媒體庫</span>
                </label>
            </div>

            <div id="kau-img-drop" style="border:2px dashed #d4a574;border-radius:8px;padding:50px 20px;text-align:center;background:#fafaf8;cursor:pointer;transition:.2s">
                <div style="font-size:36px;margin-bottom:6px">📁</div>
                <div style="font-weight:600;font-size:14px;color:#001b3d">拖放圖片到這裡或點擊選擇</div>
                <div style="color:#646970;font-size:12px;margin-top:4px">支援 JPG / PNG / WebP / GIF（單張），一次最多 20 張</div>
                <input id="kau-img-file" type="file" accept="image/*" multiple style="display:none">
            </div>

            <div id="kau-img-results" style="margin-top:18px"></div>
        </div>
    </div>

    <style>
    .kau-img-row{display:grid;grid-template-columns:64px 1fr 110px 110px 110px 120px;gap:12px;align-items:center;padding:10px 12px;border:1px solid #e2ddd7;border-radius:6px;background:#fff;margin-bottom:6px;font-size:12.5px}
    .kau-img-row img{width:64px;height:64px;object-fit:cover;background:#f6f7f7;border-radius:4px}
    .kau-img-row .nm{font-weight:600;color:#001b3d;word-break:break-all}
    .kau-img-row .sz{color:#646970;font-family:monospace}
    .kau-img-row .saved{color:#16a34a;font-weight:600}
    .kau-img-row .err{color:#dc2626}
    .kau-img-row .status{font-size:11.5px}
    .kau-img-row.processing{background:#fffbeb}
    .kau-img-row.done{background:#f0fdf4}
    .kau-img-row.error{background:#fef2f2}
    #kau-img-drop.drag{background:#fff7ed;border-color:#001b3d}
    .kau-img-row .copy{padding:3px 8px;border:1px solid #ccd0d4;border-radius:4px;background:#fff;font-size:11px;cursor:pointer}
    </style>

    <script>
    (function(){
      const drop = document.getElementById('kau-img-drop');
      const fileInput = document.getElementById('kau-img-file');
      const results = document.getElementById('kau-img-results');
      const NONCE = <?php echo wp_json_encode($nonce); ?>;
      const AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

      drop.addEventListener('click', e => { if (e.target.tagName !== 'INPUT') fileInput.click(); });
      ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
      ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
      drop.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
      fileInput.addEventListener('change', e => handleFiles(e.target.files));

      function fmtBytes(b){ if(b<1024) return b+' B'; if(b<1048576) return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(2)+' MB'; }

      async function handleFiles(files){
        const list = Array.from(files).filter(f => /^image\//.test(f.type)).slice(0, 20);
        if (!list.length) return;
        for (const file of list) {
          const row = makeRow(file);
          results.appendChild(row);
          try {
            row.classList.add('processing');
            row.querySelector('.status').textContent = '壓縮中…';
            const out = await compress(file);
            const ratio = (1 - out.blob.size / file.size) * 100;
            row.querySelector('.kau-after').innerHTML = fmtBytes(out.blob.size) + '<br><span style="color:#646970;font-size:10.5px">Q' + out.finalQuality + ' · ' + out.finalDims + '</span>';
            row.querySelector('.kau-saved').textContent = ratio > 0 ? '−' + ratio.toFixed(0) + '%' : '+' + (-ratio).toFixed(0) + '%';
            row.querySelector('.kau-thumb').src = out.preview;
            if (out.targetBytes && !out.hitTarget) {
              row.querySelector('.kau-saved').innerHTML += '<br><span style="color:#dc2626;font-size:10.5px">未達目標</span>';
            }

            if (document.getElementById('kau-img-autoup').checked) {
              row.querySelector('.status').textContent = '上傳中…';
              const { url, id } = await upload(out.blob, out.name);
              row.classList.remove('processing'); row.classList.add('done');
              row.querySelector('.status').innerHTML = '✓ 已進媒體庫 <a href="' + url + '" target="_blank">查看</a> <button class="copy" data-url="' + url + '">複製URL</button>';
            } else {
              const dl = document.createElement('a');
              dl.href = URL.createObjectURL(out.blob);
              dl.download = out.name;
              dl.textContent = '⬇ 下載';
              dl.className = 'copy';
              row.classList.remove('processing'); row.classList.add('done');
              row.querySelector('.status').innerHTML = '';
              row.querySelector('.status').appendChild(dl);
            }
          } catch (err) {
            row.classList.remove('processing'); row.classList.add('error');
            row.querySelector('.status').innerHTML = '<span class="err">✗ ' + (err.message || err) + '</span>';
          }
        }
      }

      results.addEventListener('click', e => {
        if (e.target.classList.contains('copy') && e.target.dataset.url) {
          navigator.clipboard.writeText(e.target.dataset.url);
          e.target.textContent = '已複製';
          setTimeout(() => e.target.textContent = '複製URL', 1200);
        }
      });

      function makeRow(file){
        const row = document.createElement('div');
        row.className = 'kau-img-row';
        row.innerHTML = '<img class="kau-thumb" alt="">'
          + '<div><div class="nm">' + file.name + '</div></div>'
          + '<div class="sz">原 ' + fmtBytes(file.size) + '</div>'
          + '<div class="sz">後 <span class="kau-after">…</span></div>'
          + '<div class="saved kau-saved">…</div>'
          + '<div class="status">等待</div>';
        return row;
      }

      function toBlob(canvas, mime, q){ return new Promise(r => canvas.toBlob(r, mime, q)); }

      function compress(file){
        return new Promise(async (resolve, reject) => {
          const fmt = document.getElementById('kau-img-fmt').value;
          let q = Math.min(100, Math.max(1, parseInt(document.getElementById('kau-img-quality').value||'82'))) / 100;
          const maxSize = Math.min(4000, Math.max(200, parseInt(document.getElementById('kau-img-maxsize').value||'1600')));
          const targetKB = parseInt(document.getElementById('kau-img-target').value || '0');
          const targetBytes = targetKB > 0 ? targetKB * 1024 : 0;
          const img = new Image();
          img.onload = async () => {
            let w = img.naturalWidth, h = img.naturalHeight;
            if (Math.max(w, h) > maxSize) {
              const scale = maxSize / Math.max(w, h);
              w = Math.round(w * scale); h = Math.round(h * scale);
            }
            const mime = fmt === 'keep' ? (file.type || 'image/jpeg') : 'image/' + fmt;
            const ext  = mime.split('/')[1] || 'jpg';

            // 迭代壓縮：先試使用者設定品質，太大就降到符合 target
            const renderAt = async (W, H, qq) => {
              const cv = document.createElement('canvas');
              cv.width = W; cv.height = H;
              cv.getContext('2d').drawImage(img, 0, 0, W, H);
              return { cv, blob: await toBlob(cv, mime, qq) };
            };

            let { cv, blob } = await renderAt(w, h, q);
            if (!blob) return reject(new Error('壓縮失敗'));

            if (targetBytes > 0 && blob.size > targetBytes) {
              // 1) 先降品質：每次 -8，下限 0.3
              while (blob.size > targetBytes && q > 0.30) {
                q = Math.max(0.30, q - 0.08);
                const r = await renderAt(w, h, q);
                cv = r.cv; blob = r.blob;
              }
              // 2) 還是過大 → 縮邊長：每次 -15%，下限 400px
              while (blob.size > targetBytes && Math.max(w, h) > 400) {
                const scale = 0.85;
                w = Math.round(w * scale); h = Math.round(h * scale);
                const r = await renderAt(w, h, q);
                cv = r.cv; blob = r.blob;
              }
            }

            const base = file.name.replace(/\.[^.]+$/, '');
            resolve({
              blob,
              name: base + '.' + ext,
              preview: cv.toDataURL(mime, 0.6),
              finalQuality: Math.round(q * 100),
              finalDims: w + '×' + h,
              hitTarget: targetBytes ? (blob.size <= targetBytes) : true,
              targetBytes,
            });
          };
          img.onerror = () => reject(new Error('讀取圖片失敗'));
          img.src = URL.createObjectURL(file);
        });
      }

      async function upload(blob, name){
        const fd = new FormData();
        fd.append('action', 'kau_site_upload');
        fd.append('nonce', NONCE);
        fd.append('file', new File([blob], name, { type: blob.type }));
        const r = await fetch(AJAX, { method:'POST', credentials:'same-origin', body:fd });
        const data = await r.json();
        if (!data.success) throw new Error((data.data && data.data.message) || '上傳失敗');
        return { url: data.data.url, id: data.data.id };
      }
    })();
    </script>
    <?php
}

// ─── 後台美化：KAU 品牌色側邊條 ────────────────────────────────────────────
add_action('admin_head', function() {
    ?>
    <style>
    /* === KAU 後台主題（品牌色 #001b3d 深藍 + #d4a574 金） === */
    :root { --kau-navy:#001b3d; --kau-navy-2:#0d3a6e; --kau-gold:#d4a574; --kau-paper:#f5f3ef; }

    /* 左側選單整體：深藍底 + 金色 hover/active */
    #adminmenuback, #adminmenuwrap, #adminmenu, #adminmenu .wp-submenu {
        background: var(--kau-navy) !important;
    }
    #adminmenu li.menu-top, #adminmenu a.menu-top { background: transparent !important; }
    #adminmenu div.wp-menu-name { font: 600 13.5px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; letter-spacing:.02em; padding: 9px 12px 9px 4px; }
    #adminmenu div.wp-menu-image::before { color: rgba(255,255,255,.75) !important; opacity:1 !important; transition: color .15s; }
    #adminmenu li:hover div.wp-menu-image::before,
    #adminmenu li.opensub div.wp-menu-image::before,
    #adminmenu li.current div.wp-menu-image::before,
    #adminmenu li.wp-has-current-submenu div.wp-menu-image::before { color: var(--kau-gold) !important; }

    /* 一般項目文字白 */
    #adminmenu a { color: rgba(255,255,255,.78) !important; }
    #adminmenu li.menu-top:hover, #adminmenu li.opensub > a.menu-top,
    #adminmenu li > a.menu-top:focus { background: rgba(255,255,255,.06) !important; color:#fff !important; }
    #adminmenu li.menu-top:hover a, #adminmenu li.opensub > a.menu-top { color:#fff !important; }

    /* 當前頁面：金色右側強調條 */
    #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,
    #adminmenu li.current a.menu-top,
    #adminmenu li.wp-menu-open a.menu-top {
        background: rgba(212,165,116,.14) !important;
        color:#fff !important;
        box-shadow: inset -3px 0 0 var(--kau-gold);
    }

    /* 子選單：稍深藍 */
    #adminmenu .wp-submenu, #adminmenu .wp-has-current-submenu .wp-submenu, .folded #adminmenu .wp-submenu {
        background: #00132b !important;
        border-left: 1px solid rgba(212,165,116,.2);
    }
    #adminmenu .wp-submenu a { color: rgba(255,255,255,.62) !important; padding: 7px 12px !important; font-size: 12.5px !important; }
    #adminmenu .wp-submenu a:hover, #adminmenu .wp-submenu a:focus { color: var(--kau-gold) !important; background: transparent !important; }
    #adminmenu .wp-submenu li.current a, #adminmenu .wp-submenu a.current { color: var(--kau-gold) !important; font-weight: 600; }

    /* 分隔線換成金邊細條 */
    #adminmenu li.wp-menu-separator { background: transparent !important; height: 8px !important; margin: 4px 0 !important; border-bottom: 1px solid rgba(212,165,116,.18); }

    /* 摺疊按鈕 */
    #collapse-menu { color: rgba(255,255,255,.55) !important; }
    #collapse-menu:hover { color: var(--kau-gold) !important; }
    #collapse-button div::after { color: inherit !important; }

    /* 內容區：暖色紙感背景 + KAU Site 頁面標題加金色底線 */
    body.wp-admin { background: var(--kau-paper) !important; }
    .wrap h1 { color: var(--kau-navy); border-bottom: 2px solid var(--kau-gold); padding-bottom: 8px; display:inline-block; }
    .button-primary { background: var(--kau-navy) !important; border-color: var(--kau-navy) !important; box-shadow: none !important; }
    .button-primary:hover, .button-primary:focus { background: var(--kau-navy-2) !important; border-color: var(--kau-navy-2) !important; }

    /* 隱藏 WP.com 上方那條「免費試用自訂電子郵件」廣告（精準鎖定 site-notices 那個 menu item，避免影響其他 admin 元件如 Gutenberg notice） */
    #toplevel_page_site-notices,
    #toplevel_page_site-notices .upsell_banner,
    .wpcom-banner-notice, .notice-wpcom-upsell,
    .wpcom-mu-wpcom-frame-toolbar-upsell { display: none !important; }

    /* Logo 區換 KAU lettermark */
    #wp-admin-bar-wp-logo > .ab-item .ab-icon::before { content: "KAU" !important; font: 800 12px/28px "Segoe UI", system-ui, sans-serif !important; color: var(--kau-gold) !important; letter-spacing:.08em; }
    #wp-admin-bar-wp-logo-default { display: none !important; }
    </style>
    <?php
});

// 媒體上傳 JS
add_action('admin_enqueue_scripts', function($hook) {
    $page = sanitize_key((string) ($_GET['page'] ?? ''));
    if (strpos($page, 'kau-site') === 0 && function_exists('wp_enqueue_media')) {
        wp_enqueue_media();
    }
});

// ─── 側邊條重新排序：常用置頂、廣告置底 ────────────────────────────────────
add_filter('custom_menu_order', '__return_true');
add_filter('menu_order', function($menu_order) {
    $top = [
        'index.php',                          // 控制台
        'kau-site',                           // KAU Site
        'edit.php',                           // 文章
        'upload.php',                         // 媒體
        'stats',                              // Stats
        'edit.php?post_type=page',            // 頁面
        'edit-comments.php',                  // 留言
    ];
    $bottom = [
        'https://wordpress.com/overview/kau-jp.com', // 主機服務
        'paid-upgrades.php',                         // 升級方案（廣告）
        'feedback',                                  // 意見反應
    ];

    $picked_top = []; $picked_bottom = []; $middle = [];
    foreach ($top as $s)    if (in_array($s, $menu_order, true)) $picked_top[] = $s;
    foreach ($bottom as $s) if (in_array($s, $menu_order, true)) $picked_bottom[] = $s;
    foreach ($menu_order as $s) {
        if (!in_array($s, $picked_top, true) && !in_array($s, $picked_bottom, true)) {
            $middle[] = $s;
        }
    }
    return array_merge($picked_top, $middle, $picked_bottom);
});

function kau_site_products_admin(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');

    if (isset($_POST['kau_pa'])) {
        check_admin_referer('kau_site_products');
        $action = sanitize_key((string) $_POST['kau_pa']);
        $products = kau_site_get_products();

        if ($action === 'save') {
            $id = sanitize_key((string) ($_POST['id'] ?? ''));
            if ($id === '') $id = 'p' . time();
            $next = kau_site_sanitize_product($_POST + ['id' => $id]);
            $found = false;
            foreach ($products as $i => $p) {
                if ($p['id'] === $id) { $products[$i] = $next; $found = true; break; }
            }
            if (!$found) $products[] = $next;
            kau_site_save_products($products);
            $_GET['saved'] = '1';
        }
        if ($action === 'delete') {
            $id = sanitize_key((string) ($_POST['id'] ?? ''));
            $products = array_values(array_filter($products, fn($p) => $p['id'] !== $id));
            kau_site_save_products($products);
            $_GET['deleted'] = '1';
        }
        // 列表上直接切換「首頁精選」：只翻這一個布林值，不跑整份 sanitize，避免洗掉其他欄位
        if ($action === 'toggle_featured') {
            $id = sanitize_key((string) ($_POST['id'] ?? ''));
            foreach ($products as $i => $prod) {
                if (($prod['id'] ?? '') === $id) {
                    $products[$i]['featured'] = empty($prod['featured']);
                    break;
                }
            }
            kau_site_save_products($products);
            $_GET['featured_saved'] = '1';
        }
        if ($action === 'save_cat') {
            $old_code = sanitize_key((string) ($_POST['old_code'] ?? ''));
            $code = sanitize_key((string) ($_POST['cat_code'] ?? ''));
            $label = sanitize_text_field((string) ($_POST['cat_label'] ?? ''));
            $label_ja = sanitize_text_field((string) ($_POST['cat_label_ja'] ?? ''));
            if ($code === '' && $label !== '') $code = sanitize_key(strtolower($label));
            if ($code === '' || $code === 'all' || $label === '') {
                $_GET['cat_error'] = '分類代碼與英文名稱都必填，代碼不能用 all。';
            } else {
                $records = kau_site_product_category_records();
                $next = [];
                $updated = false;
                foreach ($records as $cat) {
                    if ($old_code !== '' && $cat['code'] === $old_code) {
                        $next[] = ['code' => $code, 'label' => $label, 'label_ja' => $label_ja];
                        $updated = true;
                    } elseif ($cat['code'] !== $code) {
                        $next[] = $cat;
                    }
                }
                if (!$updated) $next[] = ['code' => $code, 'label' => $label, 'label_ja' => $label_ja];
                kau_site_save_product_category_records($next);
                if ($old_code !== '') {
                    foreach ($products as &$prod) {
                        if (($prod['category_code'] ?? '') === $old_code || ($prod['category_code'] ?? '') === $code) {
                            $prod['category_code'] = $code;
                            $prod['category_label'] = $label;
                        }
                    }
                    unset($prod);
                    kau_site_save_products($products);
                }
                $_GET['cat_saved'] = '1';
            }
        }
        if ($action === 'delete_cat') {
            $code = sanitize_key((string) ($_POST['cat_code'] ?? ''));
            $in_use = false;
            foreach ($products as $prod) {
                if (($prod['category_code'] ?? '') === $code) { $in_use = true; break; }
            }
            if ($in_use) {
                $_GET['cat_error'] = '這個分類還有商品在使用，請先把商品改到其他分類再刪除。';
            } else {
                $next = array_values(array_filter(kau_site_product_category_records(), fn($cat) => $cat['code'] !== $code));
                kau_site_save_product_category_records($next);
                $_GET['cat_deleted'] = '1';
            }
        }
    }

    $products = kau_site_get_products();
    $edit_id = sanitize_key((string) ($_GET['edit'] ?? ''));
    $editing = null;
    foreach ($products as $p) if ($p['id'] === $edit_id) { $editing = $p; break; }
    $is_new = isset($_GET['new']) || !$editing;
    $p = $editing ?: [
        'id'=>'', 'name'=>'', 'category_code'=>'office', 'description'=>'',
        'detail'=>'', 'features'=>'', 'price'=>'', 'image'=>'', 'gallery'=>[],
        'width'=>'', 'depth'=>'', 'height'=>'', 'seat_height'=>'', 'weight'=>'', 'colors'=>'', 'material'=>'', 'specs'=>'',
        'amazon_url'=>'#', 'rakuten_url'=>'#',
        'featured'=>false,
    ];
    $cats = kau_site_product_categories();
    $cat_records = kau_site_product_category_records();
    $cat_counts = [];
    foreach ($products as $prod) {
        $code = (string) ($prod['category_code'] ?? '');
        if ($code !== '') $cat_counts[$code] = ($cat_counts[$code] ?? 0) + 1;
    }
    $cat_panel_open = isset($_GET['cat_saved']) || isset($_GET['cat_deleted']) || isset($_GET['cat_error']);
    ?>
    <div class="wrap kau-pm">
        <div class="kau-pm-head">
            <h1 class="wp-heading-inline">商品管理</h1>
            <a class="page-title-action" href="<?php echo esc_url(add_query_arg(['page'=>'kau-site-products','new'=>'1'], admin_url('admin.php'))); ?>">新增商品</a>
            <p class="kau-pm-sub">編輯後立即生效於 Products 頁面與首頁「おすすめ」。</p>
        </div>
        <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p>商品已儲存。</p></div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p>商品已刪除。</p></div><?php endif; ?>
        <?php if (isset($_GET['featured_saved'])): ?><div class="notice notice-success"><p>首頁精選已更新。</p></div><?php endif; ?>
        <?php if (isset($_GET['cat_saved'])): ?><div class="notice notice-success"><p>分類已儲存。</p></div><?php endif; ?>
        <?php if (isset($_GET['cat_deleted'])): ?><div class="notice notice-success"><p>分類已刪除。</p></div><?php endif; ?>
        <?php if (isset($_GET['cat_error'])): ?><div class="notice notice-error"><p><?php echo esc_html((string) $_GET['cat_error']); ?></p></div><?php endif; ?>

        <details class="kau-card kau-cats" <?php echo $cat_panel_open ? 'open' : ''; ?>>
            <summary>
                <span class="kau-cats-label">分類設定</span>
                <span class="kau-muted"><?php echo esc_html((string) count($cat_records)); ?> 個分類，商品編輯時可直接選用</span>
            </summary>
            <div class="kau-cats-body">
                <p class="kau-muted kau-cats-note">分類只影響商品篩選與後台下拉選單。平常不用打開，需要新增或改名稱時在這裡處理就好。</p>
                <div style="display:grid;gap:10px">
                    <?php foreach ($cat_records as $cat): ?>
                    <div style="display:grid;grid-template-columns:90px minmax(130px,1fr) minmax(150px,1fr) 70px 58px 58px;gap:8px;align-items:center;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px">
                        <form method="post" style="display:contents">
                            <?php wp_nonce_field('kau_site_products'); ?>
                            <input type="hidden" name="kau_pa" value="save_cat">
                            <input type="hidden" name="old_code" value="<?php echo esc_attr($cat['code']); ?>">
                            <input class="regular-text" name="cat_code" value="<?php echo esc_attr($cat['code']); ?>" aria-label="分類代碼">
                            <input class="regular-text" name="cat_label" value="<?php echo esc_attr($cat['label']); ?>" aria-label="英文名稱">
                            <input class="regular-text" name="cat_label_ja" value="<?php echo esc_attr($cat['label_ja']); ?>" aria-label="日文名稱">
                            <span style="color:#646970"><?php echo esc_html((string) ($cat_counts[$cat['code']] ?? 0)); ?> 件</span>
                            <span>
                                <button class="button button-small" type="submit">儲存</button>
                            </span>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('確定刪除這個分類？')">
                            <?php wp_nonce_field('kau_site_products'); ?>
                            <input type="hidden" name="kau_pa" value="delete_cat">
                            <input type="hidden" name="cat_code" value="<?php echo esc_attr($cat['code']); ?>">
                            <button class="button button-small" type="submit">刪除</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" style="margin-top:14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px">
                    <?php wp_nonce_field('kau_site_products'); ?>
                    <input type="hidden" name="kau_pa" value="save_cat">
                    <input type="hidden" name="old_code" value="">
                    <h3 style="margin:0 0 10px">新增分類</h3>
                    <div style="display:grid;grid-template-columns:110px 1fr 1fr auto;gap:8px;align-items:end">
                        <label>分類代碼<br><input class="regular-text" name="cat_code" placeholder="office"></label>
                        <label>英文名稱<br><input class="regular-text" name="cat_label" placeholder="Office"></label>
                        <label>日文名稱<br><input class="regular-text" name="cat_label_ja" placeholder="オフィスチェア"></label>
                        <button class="button" type="submit">新增分類</button>
                    </div>
                </form>
            </div>
        </details>

        <div class="kau-pm-grid">
            <section class="kau-card kau-list">
                <header class="kau-list-hd">
                    <div class="kau-filters">
                        <label class="kau-search">
                            <span class="screen-reader-text">搜尋商品</span>
                            <input type="search" id="kau-pm-search" placeholder="搜尋名稱、描述或分類" autocomplete="off">
                        </label>
                        <div class="kau-chips" role="group" aria-label="篩選">
                            <button type="button" class="kau-chip is-on" data-filter="all">全部 <span><?php echo esc_html((string) count($products)); ?></span></button>
                            <button type="button" class="kau-chip" data-filter="featured">首頁精選 <span><?php echo esc_html((string) count(array_filter($products, fn($x) => !empty($x['featured'])))); ?></span></button>
                            <?php foreach ($cat_records as $cat): if (empty($cat_counts[$cat['code']])) continue; ?>
                            <button type="button" class="kau-chip" data-filter="cat:<?php echo esc_attr($cat['code']); ?>"><?php echo esc_html($cat['label']); ?> <span><?php echo esc_html((string) $cat_counts[$cat['code']]); ?></span></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </header>

                <?php if (!$products): ?>
                <div class="kau-empty">
                    <p class="kau-empty-title">還沒有任何商品</p>
                    <p class="kau-muted">新增第一件商品後，Products 頁面與首頁「おすすめ」就會跟著更新。</p>
                    <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page'=>'kau-site-products','new'=>'1'], admin_url('admin.php'))); ?>">新增商品</a>
                </div>
                <?php else: ?>
                <table class="kau-table">
                    <thead>
                        <tr>
                            <th scope="col" class="kau-col-img"><span class="screen-reader-text">圖片</span></th>
                            <th scope="col">商品</th>
                            <th scope="col" class="kau-col-cat">分類</th>
                            <th scope="col" class="kau-col-price">價格</th>
                            <th scope="col" class="kau-col-star">精選</th>
                            <th scope="col" class="kau-col-act"><span class="screen-reader-text">操作</span></th>
                        </tr>
                    </thead>
                    <tbody id="kau-pm-rows">
                    <?php foreach ($products as $item):
                        $raw_price = trim((string) ($item['price'] ?? ''));
                        $numeric   = str_replace([',', '，', ' '], '', $raw_price);
                        $price_txt = $raw_price === '' ? '' : (is_numeric($numeric) ? '¥' . number_format((float) $numeric) : $raw_price);
                        $cat_txt   = (string) ($item['category_label'] ?: $item['category_code']);
                        $tags      = array_slice(array_values(array_filter(array_map('trim', explode(',', (string) ($item['features'] ?? ''))))), 0, 3);
                        $gallery_n = count(array_filter((array) ($item['gallery'] ?? [])));
                        $is_edit   = $editing && $editing['id'] === $item['id'];
                        $featured  = !empty($item['featured']);
                    ?>
                    <tr class="kau-row<?php echo $is_edit ? ' is-editing' : ''; ?>"
                        <?php // strtolower 只動 ASCII，日文假名漢字原樣保留；不用 mbstring 免得環境沒裝 ?>
                        data-search="<?php echo esc_attr(strtolower($item['name'] . ' ' . $item['description'] . ' ' . $cat_txt . ' ' . (string) ($item['features'] ?? ''))); ?>"
                        data-cat="<?php echo esc_attr((string) $item['category_code']); ?>"
                        data-featured="<?php echo $featured ? '1' : '0'; ?>">
                        <td class="kau-col-img">
                            <?php if (!empty($item['image'])): ?>
                                <img class="kau-thumb" src="<?php echo esc_url($item['image']); ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="kau-thumb kau-thumb-empty" aria-hidden="true">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="kau-name" href="<?php echo esc_url(add_query_arg(['page'=>'kau-site-products','edit'=>$item['id']], admin_url('admin.php'))); ?>"><?php echo esc_html($item['name']); ?></a>
                            <?php if (!empty($item['description'])): ?><div class="kau-desc"><?php echo esc_html($item['description']); ?></div><?php endif; ?>
                            <?php if ($tags || $gallery_n): ?>
                            <div class="kau-tags">
                                <?php foreach ($tags as $tag): ?><span class="kau-tag"><?php echo esc_html($tag); ?></span><?php endforeach; ?>
                                <?php if ($gallery_n): ?><span class="kau-tag kau-tag-quiet"><?php echo esc_html((string) $gallery_n); ?> 張多圖</span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="kau-col-cat"><span class="kau-badge"><?php echo esc_html($cat_txt); ?></span></td>
                        <td class="kau-col-price"><?php if ($price_txt === ''): ?><span class="kau-muted" title="尚未設定價格">未設定</span><?php else: ?><span class="kau-price"><?php echo esc_html($price_txt); ?></span><?php endif; ?></td>
                        <td class="kau-col-star">
                            <form method="post">
                                <?php wp_nonce_field('kau_site_products'); ?>
                                <input type="hidden" name="kau_pa" value="toggle_featured">
                                <input type="hidden" name="id" value="<?php echo esc_attr($item['id']); ?>">
                                <button type="submit" class="kau-star<?php echo $featured ? ' is-on' : ''; ?>"
                                        title="<?php echo $featured ? '取消首頁精選' : '設為首頁精選'; ?>"
                                        aria-pressed="<?php echo $featured ? 'true' : 'false'; ?>">
                                    <span aria-hidden="true"><?php echo $featured ? '★' : '☆'; ?></span>
                                    <span class="screen-reader-text"><?php echo $featured ? '取消首頁精選' : '設為首頁精選'; ?></span>
                                </button>
                            </form>
                        </td>
                        <td class="kau-col-act">
                            <div class="kau-actions">
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page'=>'kau-site-products','edit'=>$item['id']], admin_url('admin.php'))); ?>">編輯</a>
                                <form method="post" onsubmit="return confirm('確定刪除「<?php echo esc_js($item['name']); ?>」？');">
                                    <?php wp_nonce_field('kau_site_products'); ?>
                                    <input type="hidden" name="kau_pa" value="delete">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($item['id']); ?>">
                                    <button class="kau-del" type="submit">刪除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="kau-noresult" id="kau-pm-noresult" hidden>找不到符合的商品，換個關鍵字試試。</p>
                <?php endif; ?>
            </section>

            <aside class="kau-card kau-product-form-panel kau-form">
                <header class="kau-form-hd">
                    <h2><?php echo $is_new ? '新增商品' : '編輯商品'; ?></h2>
                    <?php if (!$is_new): ?>
                    <a class="kau-form-cancel" href="<?php echo esc_url(add_query_arg(['page'=>'kau-site-products','new'=>'1'], admin_url('admin.php'))); ?>">改為新增</a>
                    <?php endif; ?>
                </header>
                <form method="post" class="kau-form-body">
                    <?php wp_nonce_field('kau_site_products'); ?>
                    <input type="hidden" name="kau_pa" value="save">
                    <input type="hidden" name="id" value="<?php echo esc_attr($p['id']); ?>">

                    <div class="kau-field">
                        <label for="kau-f-name">商品名稱</label>
                        <input id="kau-f-name" name="name" value="<?php echo esc_attr($p['name']); ?>" required>
                    </div>
                    <div class="kau-field-row">
                        <div class="kau-field">
                            <label for="kau-f-cat">分類</label>
                            <select id="kau-f-cat" name="category_code">
                                <?php foreach ($cats as $code => $label): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($p['category_code'], $code); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="kau-field">
                            <label for="kau-f-price">價格</label>
                            <div class="kau-input-affix">
                                <span aria-hidden="true">¥</span>
                                <input id="kau-f-price" name="price" value="<?php echo esc_attr($p['price']); ?>" placeholder="22000" inputmode="numeric">
                            </div>
                        </div>
                    </div>
                    <div class="kau-field">
                        <label for="kau-f-desc">短描述</label>
                        <input id="kau-f-desc" name="description" value="<?php echo esc_attr($p['description']); ?>" placeholder="列表卡片上的一行說明">
                    </div>
                    <div class="kau-field">
                        <label for="kau-f-detail">詳細資訊</label>
                        <textarea id="kau-f-detail" rows="7" name="detail" placeholder="尺寸、顏色、材質、保固、適用情境、補充說明都寫在這裡即可。"><?php echo esc_textarea((string) ($p['detail'] ?? '')); ?></textarea>
                        <p class="kau-hint">會顯示在商品詳細彈窗，換行會保留。</p>
                    </div>
                    <div class="kau-field">
                        <label for="kau-f-features">特色標籤</label>
                        <input id="kau-f-features" name="features" value="<?php echo esc_attr($p['features']); ?>" placeholder="例：高さ調整, メッシュ">
                        <p class="kau-hint">用逗號分隔，會變成卡片上的小標籤。</p>
                    </div>

                    <div class="kau-group">
                        <h3>圖片</h3>
                        <div class="kau-media">
                            <div class="kau-media-preview">
                                <img class="kau-img-preview" src="<?php echo esc_attr($p['image']); ?>" alt="" style="<?php echo $p['image'] ? '' : 'display:none;'; ?>">
                                <span class="kau-media-placeholder"<?php echo $p['image'] ? ' hidden' : ''; ?>>尚未選圖</span>
                            </div>
                            <div class="kau-media-fields">
                                <label for="kau-f-image">主圖 URL</label>
                                <input id="kau-f-image" class="kau-img-url" name="image" value="<?php echo esc_attr($p['image']); ?>">
                                <button class="button kau-pick-img" type="button">從媒體庫選擇</button>
                            </div>
                        </div>
                        <div class="kau-field">
                            <label for="kau-f-gallery">多圖（每行一張 URL）</label>
                            <textarea id="kau-f-gallery" class="kau-gallery-url" rows="3" name="gallery" placeholder="https://…"><?php echo esc_textarea(implode("\n", array_filter((array) ($p['gallery'] ?? [])))); ?></textarea>
                            <button class="button kau-pick-gallery" type="button">選擇多張圖片</button>
                        </div>
                    </div>

                    <div class="kau-group">
                        <h3>購買連結</h3>
                        <div class="kau-field">
                            <label for="kau-f-amazon">Amazon URL</label>
                            <input id="kau-f-amazon" name="amazon_url" value="<?php echo esc_attr($p['amazon_url']); ?>">
                        </div>
                        <div class="kau-field">
                            <label for="kau-f-rakuten">樂天 URL</label>
                            <input id="kau-f-rakuten" name="rakuten_url" value="<?php echo esc_attr($p['rakuten_url']); ?>">
                        </div>
                    </div>

                    <label class="kau-toggle">
                        <input type="checkbox" name="featured" value="1" <?php checked(!empty($p['featured'])); ?>>
                        <span>
                            <strong>顯示在首頁「精選商品」</strong>
                            <span class="kau-hint">勾選後會出現在首頁的 Showcase 區塊。</span>
                        </span>
                    </label>

                    <div class="kau-form-actions">
                        <button class="button button-primary" type="submit">儲存商品</button>
                        <?php if (!$is_new): ?><span class="kau-hint">正在編輯：<?php echo esc_html($p['name']); ?></span><?php endif; ?>
                    </div>
                </form>
            </aside>
        </div>
    </div>
    <style>
    /* 這個畫面住在 wp-admin 裡，所以沿用 WordPress 後台的字體與色票，
       只把版面、層級與互動狀態重做，不外掛第三方字型免得跟其他後台頁面打架。 */
    .kau-pm { --kau-line:#dcdcde; --kau-ink:#1d2327; --kau-muted:#646970; --kau-bg:#f6f7f7; --kau-accent:#2271b1; --kau-gold:#b8863b; max-width:1440px; }
    .kau-pm .kau-muted { color:var(--kau-muted); }
    .kau-pm-head { display:flex; align-items:center; flex-wrap:wrap; gap:0 12px; margin:0 0 16px; }
    .kau-pm-head h1 { margin:0; padding:0; font-size:24px; line-height:32px; }
    .kau-pm-sub { flex:1 0 100%; margin:4px 0 0; color:var(--kau-muted); font-size:13px; }
    .kau-pm .kau-card { background:#fff; border:1px solid var(--kau-line); border-radius:8px; }
    .kau-pm .notice { margin:0 0 16px; }

    /* 分類設定 */
    .kau-cats { margin:0 0 16px; max-width:1040px; overflow:hidden; }
    .kau-cats > summary { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 16px; cursor:pointer; font-size:14px; }
    .kau-cats > summary:hover { background:var(--kau-bg); }
    .kau-cats > summary:focus-visible { outline:2px solid var(--kau-accent); outline-offset:-2px; }
    .kau-cats-label { font-weight:600; color:var(--kau-ink); }
    .kau-cats-body { border-top:1px solid var(--kau-line); padding:16px; }
    .kau-cats-note { margin:0 0 12px; font-size:13px; }

    /* 版面 */
    .kau-pm-grid { display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:24px; align-items:start; }
    @media (max-width:1200px) { .kau-pm-grid { grid-template-columns:minmax(0,1fr); } }

    /* 列表 */
    .kau-list { overflow:hidden; }
    .kau-list-hd { padding:12px 16px; border-bottom:1px solid var(--kau-line); background:var(--kau-bg); }
    .kau-filters { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .kau-search input { width:240px; height:32px; border-radius:6px; }
    .kau-chips { display:flex; gap:8px; flex-wrap:wrap; }
    .kau-chip { display:inline-flex; align-items:center; gap:8px; height:32px; padding:0 12px; border:1px solid var(--kau-line); border-radius:999px; background:#fff; color:var(--kau-ink); font-size:13px; cursor:pointer; transition:background .15s ease, border-color .15s ease; }
    .kau-chip span { color:var(--kau-muted); font-size:12px; }
    .kau-chip:hover { background:var(--kau-bg); }
    .kau-chip:active { transform:translateY(1px); }
    .kau-chip:focus-visible { outline:2px solid var(--kau-accent); outline-offset:2px; }
    .kau-chip.is-on { background:var(--kau-ink); border-color:var(--kau-ink); color:#fff; }
    .kau-chip.is-on span { color:rgba(255,255,255,.7); }

    .kau-table { width:100%; border-collapse:collapse; }
    .kau-table th { text-align:left; font-size:12px; font-weight:600; color:var(--kau-muted); padding:10px 16px; border-bottom:1px solid var(--kau-line); }
    .kau-table td { padding:12px 16px; border-bottom:1px solid var(--kau-line); vertical-align:middle; }
    .kau-table tbody tr:last-child td { border-bottom:0; }
    .kau-row { transition:background .15s ease; }
    .kau-row:hover { background:var(--kau-bg); }
    .kau-row.is-editing { background:#f0f6fc; }
    .kau-col-img { width:72px; }
    .kau-col-cat { width:120px; }
    .kau-col-price { width:110px; }
    .kau-col-star { width:64px; }
    .kau-col-act { width:132px; }
    /* 短內容的三欄（badge / 價格 / 星號）標題與內容一起置中，才不會標題靠左、內容偏右 */
    .kau-table th.kau-col-cat, .kau-table td.kau-col-cat,
    .kau-table th.kau-col-price, .kau-table td.kau-col-price,
    .kau-table th.kau-col-star, .kau-table td.kau-col-star { text-align:center; }
    .kau-thumb { display:block; width:56px; height:56px; object-fit:contain; background:var(--kau-bg); border:1px solid var(--kau-line); border-radius:6px; }
    .kau-thumb-empty { display:flex; align-items:center; justify-content:center; color:#c3c4c7; font-size:16px; }
    .kau-name { display:inline-block; font-size:14px; font-weight:600; color:var(--kau-ink); text-decoration:none; }
    .kau-name:hover, .kau-name:focus { color:var(--kau-accent); text-decoration:underline; }
    .kau-desc { margin-top:2px; font-size:13px; color:var(--kau-muted); }
    .kau-tags { display:flex; gap:4px; flex-wrap:wrap; margin-top:8px; }
    .kau-tag { display:inline-block; padding:2px 8px; border:1px solid var(--kau-line); border-radius:999px; font-size:12px; line-height:16px; color:var(--kau-muted); background:#fff; }
    .kau-tag-quiet { background:var(--kau-bg); }
    .kau-badge { display:inline-block; padding:2px 10px; border:1px solid var(--kau-line); border-radius:999px; background:var(--kau-bg); font-size:12px; line-height:18px; color:var(--kau-ink); }
    .kau-price { font-size:14px; font-variant-numeric:tabular-nums; }
    .kau-star { width:32px; height:32px; padding:0; border:1px solid transparent; border-radius:6px; background:none; color:#c3c4c7; font-size:18px; line-height:1; cursor:pointer; transition:background .15s ease, color .15s ease; }
    .kau-star:hover { background:var(--kau-bg); color:var(--kau-gold); }
    .kau-star:active { transform:translateY(1px); }
    .kau-star:focus-visible { outline:2px solid var(--kau-accent); outline-offset:2px; }
    .kau-star.is-on { color:var(--kau-gold); }
    .kau-actions { display:flex; align-items:center; gap:8px; }
    .kau-actions form { display:inline; }
    .kau-del { border:0; background:none; padding:4px 2px; color:#b32d2e; font-size:13px; cursor:pointer; border-radius:4px; }
    .kau-del:hover { color:#8a2424; text-decoration:underline; }
    .kau-del:focus-visible { outline:2px solid var(--kau-accent); outline-offset:2px; }
    .kau-empty { padding:48px 24px; text-align:center; }
    .kau-empty-title { margin:0 0 4px; font-size:16px; font-weight:600; color:var(--kau-ink); }
    .kau-empty .button { margin-top:16px; }
    .kau-noresult { margin:0; padding:32px 16px; text-align:center; color:var(--kau-muted); }

    /* 表單 */
    .kau-form { position:sticky; top:32px; overflow:hidden; }
    @media (max-width:1200px) { .kau-form { position:static; } }
    .kau-form-hd { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 16px; border-bottom:1px solid var(--kau-line); background:var(--kau-bg); }
    .kau-form-hd h2 { margin:0; padding:0; font-size:16px; line-height:24px; }
    .kau-form-cancel { font-size:13px; text-decoration:none; }
    .kau-form-body { padding:16px; max-height:calc(100vh - 180px); overflow-y:auto; }
    .kau-field { margin:0 0 12px; }
    .kau-field:last-child { margin-bottom:0; }
    .kau-field label { display:block; margin-bottom:4px; font-size:13px; font-weight:600; color:var(--kau-ink); }
    .kau-form input[type=text], .kau-form input:not([type]), .kau-form input[type=search], .kau-form input[type=url], .kau-form select, .kau-form textarea { width:100%; box-sizing:border-box; border-radius:6px; }
    .kau-form textarea { line-height:1.6; }
    .kau-field-row { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1fr); gap:12px; }
    .kau-hint { margin:4px 0 0; font-size:12px; line-height:16px; color:var(--kau-muted); }
    .kau-input-affix { display:flex; align-items:center; border:1px solid #8c8f94; border-radius:6px; background:#fff; overflow:hidden; }
    .kau-input-affix:focus-within { border-color:var(--kau-accent); box-shadow:0 0 0 1px var(--kau-accent); }
    .kau-input-affix span { padding:0 8px; color:var(--kau-muted); font-size:13px; }
    .kau-input-affix input { border:0 !important; box-shadow:none !important; border-radius:0 !important; }
    .kau-group { margin:16px 0; padding:12px; border:1px solid var(--kau-line); border-radius:6px; background:var(--kau-bg); }
    .kau-group h3 { margin:0 0 12px; font-size:13px; font-weight:600; color:var(--kau-ink); }
    .kau-group .kau-field input, .kau-group .kau-field textarea { background:#fff; }
    .kau-media { display:flex; gap:12px; align-items:flex-start; margin-bottom:12px; }
    .kau-media-preview { flex:0 0 88px; width:88px; height:88px; display:flex; align-items:center; justify-content:center; border:1px solid var(--kau-line); border-radius:6px; background:#fff; overflow:hidden; }
    .kau-media-preview img { max-width:100%; max-height:100%; object-fit:contain; }
    .kau-media-placeholder { font-size:12px; color:var(--kau-muted); }
    .kau-media-fields { flex:1 1 auto; min-width:0; }
    .kau-media-fields label { display:block; margin-bottom:4px; font-size:13px; font-weight:600; }
    .kau-media-fields .button { margin-top:8px; }
    .kau-toggle { display:flex; gap:8px; align-items:flex-start; padding:12px; border:1px solid var(--kau-line); border-radius:6px; background:#fff; cursor:pointer; }
    .kau-toggle:hover { background:var(--kau-bg); }
    .kau-toggle input { margin:2px 0 0; }
    .kau-toggle strong { display:block; font-size:13px; }
    .kau-toggle .kau-hint { margin-top:2px; }
    .kau-form-actions { display:flex; align-items:center; gap:12px; margin-top:16px; padding-top:16px; border-top:1px solid var(--kau-line); }
    </style>
    <script>
    (function(){
      var search = document.getElementById('kau-pm-search');
      var rows = Array.prototype.slice.call(document.querySelectorAll('#kau-pm-rows .kau-row'));
      var chips = Array.prototype.slice.call(document.querySelectorAll('.kau-chip'));
      var noresult = document.getElementById('kau-pm-noresult');
      if (!rows.length) return;
      var filter = 'all';
      function apply(){
        var q = (search && search.value || '').trim().toLowerCase();
        var shown = 0;
        rows.forEach(function(row){
          var okText = !q || (row.dataset.search || '').indexOf(q) >= 0;
          var okFilter = filter === 'all'
            || (filter === 'featured' && row.dataset.featured === '1')
            || (filter.indexOf('cat:') === 0 && row.dataset.cat === filter.slice(4));
          var show = okText && okFilter;
          row.hidden = !show;
          if (show) shown++;
        });
        if (noresult) noresult.hidden = shown !== 0;
      }
      if (search) search.addEventListener('input', apply);
      chips.forEach(function(chip){
        chip.addEventListener('click', function(){
          chips.forEach(function(c){ c.classList.toggle('is-on', c === chip); });
          filter = chip.dataset.filter;
          apply();
        });
      });
    }());
    </script>
    <script>
    (function(){
      document.querySelectorAll('.kau-pick-img').forEach(function(btn){
        btn.addEventListener('click', function(){
          if (!window.wp || !wp.media) return;
          var box = btn.closest('form');
          var input = box.querySelector('.kau-img-url');
          var preview = box.querySelector('.kau-img-preview');
          var placeholder = box.querySelector('.kau-media-placeholder');
          var frame = wp.media({ title:'選擇商品圖片', multiple:false, library:{type:'image'} });
          frame.on('select', function(){
            var file = frame.state().get('selection').first().toJSON();
            input.value = file.url || '';
            if (preview) { preview.src = file.url || ''; preview.style.display = file.url ? '' : 'none'; }
            if (placeholder) placeholder.hidden = !!file.url;
          });
          frame.open();
        });
      });
      document.querySelectorAll('.kau-pick-gallery').forEach(function(btn){
        btn.addEventListener('click', function(){
          if (!window.wp || !wp.media) return;
          var box = btn.closest('form');
          var input = box.querySelector('.kau-gallery-url');
          var frame = wp.media({ title:'選擇商品多圖', multiple:true, library:{type:'image'} });
          frame.on('select', function(){
            var urls = [];
            frame.state().get('selection').each(function(file){
              var data = file.toJSON();
              if (data.url) urls.push(data.url);
            });
            input.value = urls.join("\n");
          });
          frame.open();
        });
      });
    }());
    </script>
    <?php
}

function kau_site_product_categories_admin(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');
    $products = kau_site_get_products();
    kau_site_handle_product_category_admin_post($products);
    $cat_records = kau_site_product_category_records();
    $counts = [];
    foreach ($products as $prod) {
        $code = (string) ($prod['category_code'] ?? '');
        if ($code !== '') $counts[$code] = ($counts[$code] ?? 0) + 1;
    }
    ?>
    <div class="wrap">
        <h1>商品分類</h1>
        <p>管理商品分類。英文名稱會顯示在前台篩選按鈕；日文名稱用於後台下拉選單輔助辨識。</p>
        <?php if (isset($_GET['cat_saved'])): ?><div class="notice notice-success"><p>分類已儲存。</p></div><?php endif; ?>
        <?php if (isset($_GET['cat_deleted'])): ?><div class="notice notice-success"><p>分類已刪除。</p></div><?php endif; ?>
        <?php if (isset($_GET['cat_error'])): ?><div class="notice notice-error"><p><?php echo esc_html((string) $_GET['cat_error']); ?></p></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:minmax(620px,1fr) 360px;gap:24px;align-items:start">
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;overflow:hidden">
                <table class="widefat striped">
                    <thead><tr><th>代碼</th><th>英文名稱</th><th>日文名稱</th><th style="width:90px">商品數</th><th style="width:160px">操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($cat_records as $cat): ?>
                    <tr>
                        <form method="post">
                            <?php wp_nonce_field('kau_site_products'); ?>
                            <input type="hidden" name="kau_pa" value="save_cat">
                            <input type="hidden" name="old_code" value="<?php echo esc_attr($cat['code']); ?>">
                            <td><input name="cat_code" value="<?php echo esc_attr($cat['code']); ?>" style="width:100%"></td>
                            <td><input name="cat_label" value="<?php echo esc_attr($cat['label']); ?>" style="width:100%"></td>
                            <td><input name="cat_label_ja" value="<?php echo esc_attr($cat['label_ja']); ?>" style="width:100%"></td>
                            <td><?php echo (int) ($counts[$cat['code']] ?? 0); ?></td>
                            <td>
                                <button class="button button-small" type="submit">儲存</button>
                        </form>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('kau_site_products'); ?>
                                    <input type="hidden" name="kau_pa" value="delete_cat">
                                    <input type="hidden" name="cat_code" value="<?php echo esc_attr($cat['code']); ?>">
                                    <button class="button button-small" type="submit">刪除</button>
                                </form>
                            </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:18px 20px">
                <h2 style="margin-top:0">新增分類</h2>
                <form method="post">
                    <?php wp_nonce_field('kau_site_products'); ?>
                    <input type="hidden" name="kau_pa" value="save_cat">
                    <input type="hidden" name="old_code" value="">
                    <p><label><strong>分類代碼</strong><br><input name="cat_code" class="regular-text" placeholder="例：gaming"></label></p>
                    <p><label><strong>英文名稱</strong><br><input name="cat_label" class="regular-text" placeholder="例：Gaming"></label></p>
                    <p><label><strong>日文名稱</strong><br><input name="cat_label_ja" class="regular-text" placeholder="例：ゲーミングチェア"></label></p>
                    <p><button class="button button-primary" type="submit">新增分類</button></p>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function kau_site_news_admin(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');

    if (isset($_POST['kau_na'])) {
        check_admin_referer('kau_site_news');
        $action = sanitize_key((string) $_POST['kau_na']);
        $news = kau_site_get_news();

        if ($action === 'save') {
            $id = sanitize_key((string) ($_POST['id'] ?? ''));
            if ($id === '') $id = 'n' . time();
            $next = kau_site_sanitize_news_item($_POST + ['id' => $id]);
            $found = false;
            foreach ($news as $i => $n) {
                if ($n['id'] === $id) { $news[$i] = $next; $found = true; break; }
            }
            if (!$found) $news[] = $next;
            kau_site_save_news($news);
            // 不能 redirect（headers 已送）→ 設旗標繼續渲染
            $_GET['saved'] = '1';
        }
        if ($action === 'delete') {
            $id = sanitize_key((string) ($_POST['id'] ?? ''));
            $news = array_values(array_filter($news, fn($n) => $n['id'] !== $id));
            kau_site_save_news($news);
            $_GET['deleted'] = '1';
        }
        if ($action === 'set_featured') {
            $id = sanitize_key((string) ($_POST['id'] ?? ''));
            $picked = null;
            foreach ($news as $n) if ($n['id'] === $id) { $picked = $n; break; }
            if ($picked) {
                $featured = [
                    'date' => $picked['date'] ?? '',
                    'category' => $picked['category'] ?? '',
                    'title' => $picked['title'] ?? '',
                    'summary' => $picked['summary'] ?? '',
                    'url' => $picked['url'] ?? '#',
                    'read_more_label' => '続きを読む',
                ];
                kau_site_save_news($news, $featured);
                $_GET['featured'] = '1';
            }
        }
    }

    $news = kau_site_get_news();
    $edit_id = sanitize_key((string) ($_GET['edit'] ?? ''));
    $editing = null;
    foreach ($news as $n) if ($n['id'] === $edit_id) { $editing = $n; break; }
    $is_new = isset($_GET['new']) || !$editing;
    $n = $editing ?: [
        'id'=>'', 'date'=>date('Y.m.d'), 'category_code'=>'info', 'title'=>'', 'summary'=>'', 'url'=>'#',
    ];
    $cats = kau_site_news_categories();
    $data = kau_site_get_data();
    $featured = $data['news']['featured'] ?? null;
    ?>
    <style>
      .kau-news-badge { display:inline-block; padding:2px 10px; border:1px solid #dcdcde; border-radius:999px; background:#f6f7f7; font-size:12px; line-height:18px; color:#1d2327; white-space:nowrap; }
      .kau-news-table tr.is-featured td { background: #fdf8ee !important; }
      .kau-news-table tr.is-editing td { background: #f0f6fc !important; }
      .kau-news-table .kau-star-on { color:#dba617; border-color:#dba617; background:#fdf8ee; cursor:default; }
      .kau-news-table td { vertical-align: middle; }
    </style>
    <div class="wrap">
        <h1>最新情報管理</h1>
        <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p>已儲存。</p></div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p>已刪除。</p></div><?php endif; ?>
        <?php if (isset($_GET['featured'])): ?><div class="notice notice-success"><p>已設為注目記事。</p></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:minmax(520px,1fr) 400px;gap:24px;align-items:start">
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;overflow:hidden">
                <table class="widefat striped kau-news-table">
                    <thead><tr><th>日付</th><th>タイトル</th><th>類別</th><th style="width:200px">操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($news as $item):
                        $is_featured = $featured && ($item['date'] ?? '') === ($featured['date'] ?? null) && ($item['title'] ?? '') === ($featured['title'] ?? null);
                        $is_editing = $edit_id !== '' && $item['id'] === $edit_id;
                        $row_class = trim(($is_featured ? 'is-featured ' : '') . ($is_editing ? 'is-editing' : ''));
                    ?>
                    <tr<?php echo $row_class ? ' class="' . esc_attr($row_class) . '"' : ''; ?>>
                        <td style="white-space:nowrap"><?php echo esc_html($item['date']); ?></td>
                        <td><strong><?php echo esc_html($item['title']); ?></strong><?php if ($is_editing): ?> <span style="color:#2271b1;font-size:11px">（編輯中）</span><?php endif; ?></td>
                        <td><span class="kau-news-badge"><?php echo esc_html($item['category']); ?></span></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page'=>'kau-site-news','edit'=>$item['id']], admin_url('admin.php'))); ?>">編輯</a>
                            <?php if ($is_featured): ?>
                            <button class="button button-small kau-star-on" type="button" title="目前注目記事" disabled>★</button>
                            <?php else: ?>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('kau_site_news'); ?>
                                <input type="hidden" name="kau_na" value="set_featured">
                                <input type="hidden" name="id" value="<?php echo esc_attr($item['id']); ?>">
                                <button class="button button-small" type="submit" title="設為注目記事（首頁與 News 頁頂部大卡片）">☆</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('確定刪除「<?php echo esc_js($item['title']); ?>」？')">
                                <?php wp_nonce_field('kau_site_news'); ?>
                                <input type="hidden" name="kau_na" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($item['id']); ?>">
                                <button class="button button-small" type="submit">刪除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($featured): ?>
                <p style="margin:10px 16px;color:#646970;font-size:12px">★ 底色列為目前注目記事：<?php echo esc_html($featured['date'] . ' ' . $featured['title']); ?>（顯示於 News 頁頂部大卡片）</p>
                <?php endif; ?>
                <?php
                $wp_news_posts = get_posts(['numberposts' => 20, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']);
                if ($wp_news_posts): ?>
                <div style="background:#f6f7f7;padding:14px 16px;border-top:1px solid #ccd0d4">
                    <h3 style="margin:0 0 6px;font-size:13px;color:#001b3d">
                        📰 已發佈的 WP 文章（自動同步到前台 news.html，共 <?php echo count($wp_news_posts); ?> 篇）
                    </h3>
                    <p style="color:#646970;font-size:11.5px;margin:0 0 10px">這些文章不需手動建立記事就會出現在前台。要從前台移除請到「文章」改為草稿/刪除。</p>
                    <table class="widefat" style="background:#fff">
                        <thead><tr><th style="width:90px">日付</th><th>タイトル</th><th>類別</th><th style="width:120px">操作</th></tr></thead>
                        <tbody>
                        <?php foreach ($wp_news_posts as $wp_p):
                            $wp_cats = get_the_category($wp_p->ID);
                            $wp_cat = $wp_cats ? $wp_cats[0]->name : 'お知らせ';
                        ?>
                            <tr>
                                <td style="white-space:nowrap"><?php echo esc_html(get_the_date('Y.m.d', $wp_p)); ?></td>
                                <td><strong><?php echo esc_html(get_the_title($wp_p)); ?></strong></td>
                                <td><span class="kau-news-badge"><?php echo esc_html($wp_cat); ?></span></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(get_edit_post_link($wp_p->ID)); ?>">編輯</a>
                                    <a class="button button-small" target="_blank" href="<?php echo esc_url(get_permalink($wp_p)); ?>">查看</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:18px 20px;position:sticky;top:32px">
                <h2 style="margin-top:0"><?php echo $is_new ? '新增記事' : '編輯記事'; ?></h2>
                <?php if (!$is_new): ?>
                <p style="margin:0 0 12px"><a href="<?php echo esc_url(add_query_arg(['page'=>'kau-site-news','new'=>'1'], admin_url('admin.php'))); ?>">← 取消編輯，改為新增記事</a></p>
                <?php endif; ?>
                <form method="post">
                    <?php wp_nonce_field('kau_site_news'); ?>
                    <input type="hidden" name="kau_na" value="save">
                    <input type="hidden" name="id" value="<?php echo esc_attr($n['id']); ?>">
                    <p><label><strong>日付</strong><br><input class="regular-text" name="date" value="<?php echo esc_attr($n['date']); ?>" required></label></p>
                    <p><label><strong>類別</strong><br>
                        <select name="category_code">
                            <?php foreach ($cats as $code => $label): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($n['category_code'], $code); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                    <p><label><strong>標題</strong><br><input class="regular-text" name="title" value="<?php echo esc_attr($n['title']); ?>" required></label></p>
                    <p><label><strong>摘要（注目記事顯示）</strong><br><textarea class="large-text" rows="3" name="summary"><?php echo esc_textarea($n['summary'] ?? ''); ?></textarea></label></p>
                    <p><label><strong>URL</strong><br><input class="regular-text kau-news-url" name="url" value="<?php echo esc_attr($n['url']); ?>" placeholder="https://... 或 # 留空"></label></p>
                    <?php
                    $posts_list = get_posts(['numberposts' => 100, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']);
                    if ($posts_list):
                    ?>
                    <p style="background:#f6f7f7;padding:10px;border-radius:4px;margin:0">
                        <label><strong>連到 WordPress 文章</strong><br>
                            <select class="kau-news-post-picker" style="width:100%;margin-top:4px">
                                <option value="">— 選一篇文章自動帶入 URL —</option>
                                <?php foreach ($posts_list as $post_item): ?>
                                    <option value="<?php echo esc_url(get_permalink($post_item)); ?>"><?php echo esc_html(mb_strimwidth($post_item->post_title, 0, 50, '…')); ?> <?php echo esc_html(get_the_date('Y.m.d', $post_item)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <span style="color:#646970;font-size:11.5px">選了之後上方 URL 會自動填，標題/摘要可手動再調</span>
                    </p>
                    <script>
                    (function(){
                      const sel=document.querySelector('.kau-news-post-picker');
                      const input=document.querySelector('.kau-news-url');
                      if (sel && input) sel.addEventListener('change', e=>{ if (e.target.value) input.value=e.target.value; });
                    })();
                    </script>
                    <?php endif; ?>
                    <p><button class="button button-primary button-hero" type="submit">儲存</button></p>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function kau_site_admin_page(): void {
    if (!current_user_can('edit_theme_options')) wp_die('No permission.');

    if (isset($_POST['kau_site_action'])) {
        check_admin_referer('kau_site_admin');
        $action = sanitize_key((string) $_POST['kau_site_action']);
        if ($action === 'reimport') {
            $report = kau_site_import_from_files(true);
            echo '<div class="notice notice-success"><p>已重新匯入：' . esc_html(wp_json_encode($report, JSON_UNESCAPED_UNICODE)) . '</p></div>';
        }
    }

    $pages = kau_site_get_pages();
    $map = kau_site_pages_map();
    $unique_keys = [];
    foreach ($map as $info) $unique_keys[$info['key']] = $info['title'];
    ?>
    <div class="wrap">
        <h1>KAU Site 內容管理</h1>
        <p>內容直接存在 WordPress 資料庫，編輯後立即生效，無需重新上傳外掛。</p>

        <table class="widefat striped" style="max-width:900px;margin-top:20px">
            <thead><tr><th>頁面</th><th>狀態</th><th>最後修改</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($unique_keys as $key => $title):
                $page = $pages[$key] ?? ['html'=>'','updated'=>0];
                $exists = !empty($page['html']);
                $size = $exists ? size_format(strlen($page['html'])) : '—';
                $updated = !empty($page['updated']) ? date('Y-m-d H:i', (int)$page['updated']) : '—';
                $view_url = home_url($key === 'home' ? '/' : '/' . $key . '/');
                $edit_url = add_query_arg('kau_edit', '1', $view_url);
            ?>
            <tr>
                <td><strong><?php echo esc_html($title); ?></strong><br><code><?php echo esc_html($view_url); ?></code></td>
                <td><?php echo $exists ? '✓ ' . esc_html($size) : '<span style="color:#a00">未匯入</span>'; ?></td>
                <td><?php echo esc_html($updated); ?></td>
                <td>
                    <a class="button" href="<?php echo esc_url($view_url); ?>" target="_blank">檢視</a>
                    <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>" target="_blank">編輯</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 style="margin-top:40px">維護工具</h2>
        <form method="post" onsubmit="return confirm('將會用外掛資料夾內的 HTML 覆蓋資料庫，確定？');">
            <?php wp_nonce_field('kau_site_admin'); ?>
            <input type="hidden" name="kau_site_action" value="reimport">
            <button class="button" type="submit">從靜態檔重新匯入（覆蓋資料庫）</button>
        </form>

        <h2 style="margin-top:40px">說明</h2>
        <ol>
            <li>啟用本外掛時，會自動從 <code>kau-original-site-editor</code> 外掛資料夾匯入 HTML 到資料庫。</li>
            <li>之後所有編輯都直接修改資料庫，<strong>不需要再上傳 zip</strong>。</li>
            <li>原本的 <code>kau-original-site-editor</code> 外掛可繼續保留（提供 assets/fonts/css 資源），但其 HTML 不再被使用。</li>
            <li>編輯模式下：點文字直接改、點圖片選新圖、點連結後按「編輯連結」可改網址。完成後按「儲存」。</li>
        </ol>
    </div>
    <?php
}
