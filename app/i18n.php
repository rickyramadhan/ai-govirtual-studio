<?php

declare(strict_types=1);

/**
 * Bahasa antarmuka Studio: English (default) dan Bahasa Indonesia.
 *
 * Teks dalam kode tetap ditulis dalam Bahasa Indonesia (sumber). Saat bahasa English dipilih,
 * setiap teks yang tampil (isi elemen dan atribut placeholder/aria-label/title/alt) dicocokkan
 * dengan kamus app/i18n/en.php dan diganti. Teks yang tidak ada di kamus tetap tampil apa adanya,
 * jadi kamus bisa dilengkapi bertahap tanpa mengubah kode halaman.
 *
 * Pilihan bahasa disimpan di cookie 'studio_lang' dan bisa diganti lewat ?lang=en atau ?lang=id.
 */

const STUDIO_LANGS = ['en' => 'English', 'id' => 'Bahasa Indonesia'];
const STUDIO_LANG_DEFAULT = 'en';

function current_lang(): string
{
    static $lang = null;
    if ($lang !== null) return $lang;
    $req = (string)($_GET['lang'] ?? '');
    if (isset(STUDIO_LANGS[$req])) {
        $lang = $req;
        if (!headers_sent()) setcookie('studio_lang', $req, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'samesite' => 'Lax']);
        return $lang;
    }
    $cookie = (string)($_COOKIE['studio_lang'] ?? '');
    $lang = isset(STUDIO_LANGS[$cookie]) ? $cookie : STUDIO_LANG_DEFAULT;
    return $lang;
}

/** Kamus teks Indonesia → English (dimuat sekali). */
function i18n_dict(): array
{
    static $dict = null;
    if ($dict === null) {
        // en.php: kamus utama. en_extra.php: terjemahan tambahan (halaman & pesan yang ditambahkan belakangan).
        $dict = [];
        foreach (['en.php', 'en_extra.php', 'en_js.php'] as $file) {
            $path = __DIR__ . '/i18n/' . $file;
            if (is_file($path)) $dict += (array)require $path;
        }
    }
    return $dict;
}

/** Terjemahkan satu potongan teks (sudah di-trim). Mengembalikan teks asli bila tidak ada padanan. */
function i18n_text(string $text): string
{
    if (current_lang() !== 'en') return $text;
    $d = i18n_dict();
    return $d[$text] ?? $text;
}

/** Terjemahkan HTML: isi teks elemen dan atribut tertentu. Tag dan atribut lain tidak disentuh. */
function i18n_html(string $html): string
{
    if (current_lang() !== 'en') return $html;
    $d = i18n_dict();
    if (!$d) return $html;
    // isi teks: di antara '>' dan '<' (kecuali konten script/style yang sudah ditangani di tempatnya)
    $html = (string)preg_replace_callback('/>([^<>]+)</u', function (array $m) use ($d): string {
        $raw = $m[1]; $t = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($t === '' || !isset($d[$t])) return $m[0];
        $lead = substr($raw, 0, strspn($raw, " \t\r\n"));
        $trail = substr($raw, strlen($raw) - strspn(strrev($raw), " \t\r\n"));
        return '>' . $lead . htmlspecialchars($d[$t], ENT_QUOTES, 'UTF-8') . $trail . '<';
    }, $html);
    // atribut ramah-pengguna
    $html = (string)preg_replace_callback('/\b(placeholder|aria-label|title|alt)="([^"]*)"/u', function (array $m) use ($d): string {
        $t = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($t === '' || !isset($d[$t])) return $m[0];
        return $m[1] . '="' . htmlspecialchars($d[$t], ENT_QUOTES, 'UTF-8') . '"';
    }, $html);
    return $html;
}

/**
 * Teks dengan angka/nilai dinamis. Kunci memakai placeholder {nama}, mis. '{n} kota · {t} lokasi'.
 */
function i18n_fmt(string $key, array $vars = []): string
{
    $out = i18n_text($key);
    foreach ($vars as $k => $v) $out = str_replace('{' . $k . '}', (string)$v, $out);
    return $out;
}

/** Kamus untuk JavaScript (hanya bahasa English). Dipasang sekali per halaman. */
function i18n_js_tag(): string
{
    if (current_lang() !== 'en') return '';
    // Pesan confirm()/alert() dari JavaScript ikut diterjemahkan lewat kamus yang sama (cocok persis).
    return '<script>window.__T=' . json_encode(i18n_dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';(function(){var c=window.confirm,a=window.alert,t=function(m){return (window.__T&&window.__T[m])||m;};window.confirm=function(m){return c.call(window,t(m));};window.alert=function(m){return a.call(window,t(m));};})();</script>';
}

/** Tautan ganti bahasa yang mempertahankan halaman dan parameter yang sedang dibuka. */
function lang_switch_html(): string
{
    $cur = current_lang();
    $params = $_GET; unset($params['lang']);
    $out = '<span class="lang-switch" role="group" aria-label="Language">';
    foreach (STUDIO_LANGS as $code => $label) {
        $q = http_build_query($params + ['lang' => $code]);
        $out .= '<a class="lang-opt' . ($cur === $code ? ' is-on' : '') . '" href="?' . h($q) . '" hreflang="' . $code . '"' . ($cur === $code ? ' aria-current="true"' : '') . '>' . ($code === 'en' ? 'EN' : 'ID') . '</a>';
    }
    return $out . '</span>';
}
