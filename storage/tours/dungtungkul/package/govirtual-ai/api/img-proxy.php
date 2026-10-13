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

// Resize with GD
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
// Output WebP (fallback: PNG if WebP unsupported)
header('Content-Type: image/webp');
$ok = @imagewebp($src, $cacheFile, 80);
if ($ok) { readfile($cacheFile); }
else {
    // Fallback: serve PNG
    header('Content-Type: image/png');
    imagepng($src, $cacheDir . '/' . $hash . '.png', 9);
    readfile($cacheDir . '/' . $hash . '.png');
}
imagedestroy($src);