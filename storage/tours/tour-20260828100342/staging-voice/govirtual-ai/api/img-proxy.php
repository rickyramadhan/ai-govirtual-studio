<?php
/* Proxy: fetch external image, resize to 200px max, cache as WebP, serve.
   Only accepts images from webview-govirtual.id origin. */
$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '') { http_response_code(400); header('Content-Type: text/plain'); echo 'missing url'; exit; }

// Security: only allow webview-govirtual.id images
$parts = parse_url($url);
$host = strtolower((string)($parts['host'] ?? ''));
if ($host !== 'webview-govirtual.id') { http_response_code(403); header('Content-Type: text/plain'); echo 'domain not allowed'; exit; }

$cacheDir = __DIR__ . '/_cover_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
$hash = md5($url);
$cacheFile = $cacheDir . '/' . $hash . '.webp';

// Serve from cache if fresh (< 24h)
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=86400');
    readfile($cacheFile);
    exit;
}

// Fetch the image
$imgData = null;
if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0'], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $imgData = @file_get_contents($url, false, $ctx);
} elseif (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER => false]);
    $imgData = @curl_exec($ch);
    curl_close($ch);
}
if (!$imgData) { http_response_code(502); header('Content-Type: text/plain'); echo 'fetch failed'; exit; }

// Resize with GD when available. Some shared hosts disable GD/WebP; serve the
// verified source bytes instead of returning HTTP 500 in that case.
if (!function_exists('imagecreatefromstring')) {
    $type = function_exists('finfo_open') ? @finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $imgData) : '';
    if (!is_string($type) || strpos($type, 'image/') !== 0) $type = 'image/webp';
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400');
    echo $imgData;
    exit;
}

$src = @imagecreatefromstring($imgData);
if (!$src) { http_response_code(502); header('Content-Type: text/plain'); echo 'invalid image'; exit; }

$ow = (int)imagesx($src);
$oh = (int)imagesy($src);
$maxW = 200;
if ($ow > $maxW) {
    $nh = (int)round($oh * $maxW / $ow);
    $dst = imagecreatetruecolor($maxW, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $nh, $ow, $oh);
    imagedestroy($src);
    $src = $dst;
}
// Output WebP when supported; otherwise write and serve PNG with one correct header.
if (function_exists('imagewebp') && @imagewebp($src, $cacheFile, 80)) {
    header('Content-Type: image/webp');
    readfile($cacheFile);
} else {
    $pngFile = $cacheDir . '/' . $hash . '.png';
    if (!@imagepng($src, $pngFile, 9)) {
        imagedestroy($src);
        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'image encode failed';
        exit;
    }
    header('Content-Type: image/png');
    readfile($pngFile);
}
imagedestroy($src);