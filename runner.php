<?php
// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Route runner for smoke tests — include index.php once with given GET params.
// register_shutdown_function runs even when index.php calls exit; (most routes end with layout(); exit;)
define('STUDIO_ROUTE_RUNNER', true);
$g = isset($argv[1]) ? json_decode(base64_decode($argv[1]), true) : [];
$_GET = is_array($g) ? $g : [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/?a=' . ($_GET['a'] ?? 'list');

$captured = null;
ob_start();
register_shutdown_function(function () use (&$captured, $argv) {
    $h = ob_get_clean();
    $captured = $h;
    if (isset($argv[2]) && $argv[2] === 'body') { echo $h; return; }
    echo 'OK|' . strlen($h) . '|' . (strpos($h, 'studio-sidebar') !== false ? 'shell' : 'noshell');
});
try {
    include 'index.php';
} catch (Throwable $e) {
    ob_end_clean();
    echo 'ERR|' . $e->getMessage() . '@' . $e->getLine();
    exit(1);
}
