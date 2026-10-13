<?php
/* Proxy to resolve the cover image of an objinfo2 object page.
   The object iframe is cross-origin, so the browser cannot read its DOM.
   This endpoint fetches the desktop template (tp=d, which includes the cover
   image) server-side and returns the absolute image URL. PHP 7.1+ compatible. */

header('Content-Type: application/json; charset=utf-8');

$info = isset($_GET['info']) ? preg_replace('/[^0-9,]/', '', $_GET['info']) : '';
$lang = isset($_GET['lang']) ? preg_replace('/[^a-zA-Z]/', '', $_GET['lang']) : 'id';
if ($lang === '') { $lang = 'id'; }

$origin = 'https://webview-govirtual.id';

/* Short-lived file cache to avoid hammering the external site. */
$cacheDir = __DIR__ . '/_cover_cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }

if (function_exists('objcover_resolve') === false) {
    function objcover_resolve($id, $lang, $cacheDir, $origin) {
        $cacheFile = $cacheDir . '/' . $id . '-' . $lang . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                $dc = json_decode($cached, true);
                if (is_array($dc)) return array_key_exists('image', $dc) ? $dc['image'] : null;
            }
        }
        $src = $origin . '/semarang-v2/objinfo2.php?info=' . $id . '&lang=' . $lang . '&vd=1&tp=d';
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
        return $image;
    }
}

// Bulk mode: info=991,993,992 (max 40) -> {images:{991:url,...}}.
// Used by the frontend to preload all object covers in ONE request.
if (strpos($info, ',') !== false) {
    $ids = array_values(array_unique(array_filter(explode(',', $info), 'strlen')));
    $ids = array_slice($ids, 0, 40);
    $images = array();
    foreach ($ids as $id) { $images[$id] = objcover_resolve($id, $lang, $cacheDir, $origin); }
    echo json_encode(array('images' => $images));
    exit;
}

$info = preg_replace('/[^0-9]/', '', $info);
if ($info === '') { echo json_encode(array('image' => null)); exit; }
echo json_encode(array('image' => objcover_resolve($info, $lang, $cacheDir, $origin)));
?>