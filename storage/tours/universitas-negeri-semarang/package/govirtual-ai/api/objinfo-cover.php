<?php
/* Proxy to resolve the cover image of an objinfo2 object page.
   The object iframe is cross-origin, so the browser cannot read its DOM.
   This endpoint fetches the desktop template (tp=d, which includes the cover
   image) server-side and returns the absolute image URL. PHP 7.1+ compatible. */

header('Content-Type: application/json; charset=utf-8');

$info = isset($_GET['info']) ? preg_replace('/[^0-9]/', '', $_GET['info']) : '';
$lang = isset($_GET['lang']) ? preg_replace('/[^a-zA-Z]/', '', $_GET['lang']) : 'id';
if ($lang === '') { $lang = 'id'; }
if ($info === '') { echo json_encode(array('image' => null)); exit; }

$origin = 'https://webview-govirtual.id';
$src = $origin . '/semarang-v2/objinfo2.php?info=' . $info . '&lang=' . $lang . '&vd=1&tp=d';

/* Short-lived file cache to avoid hammering the external site. */
$cacheDir = __DIR__ . '/_cover_cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
$cacheFile = $cacheDir . '/' . $info . '-' . $lang . '.json';
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') { echo $cached; exit; }
}

$html = '';
if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(array(
        'http' => array('timeout' => 10, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)
    ));
    $html = @file_get_contents($src, false, $ctx);
} elseif (function_exists('curl_init')) {
    $ch = curl_init($src);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_SSL_VERIFYPEER => false
    ));
    $html = @curl_exec($ch);
    @curl_close($ch);
}

$image = null;
if ($html !== false && $html !== '') {
    if (preg_match('/<img[^>]+src="([^"]+)"/i', $html, $m)) {
        $u = trim($m[1]);
        if (preg_match('#^https?://#i', $u)) {
            $image = $u;
        } elseif ($u !== '' && $u[0] === '/') {
            $image = $origin . $u;
        } elseif ($u !== '') {
            $image = $origin . '/semarang-v2/' . ltrim($u, '/');
        }
    }
}

$out = json_encode(array('image' => $image));
@file_put_contents($cacheFile, $out);
echo $out;
?>