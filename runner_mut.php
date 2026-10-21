<?php
// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Mutation runner — include index.php once with given GET/POST/method. Reports OK or ERR.
// register_shutdown_function runs even when index.php calls exit; (most routes end with layout(); exit;)
$g = isset($argv[1]) ? json_decode(base64_decode($argv[1]), true) : [];
$g = is_array($g) ? $g : [];
$_GET = is_array($g['get'] ?? null) ? $g['get'] : [];
$_POST = is_array($g['post'] ?? null) ? $g['post'] : [];
$_SERVER['REQUEST_METHOD'] = ($g['method'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/?a=' . ($_GET['a'] ?? 'list');

ob_start();
register_shutdown_function(function () {
    $h = ob_get_clean();
    echo 'OK|' . strlen($h) . '|' . (strpos($h, 'studio-sidebar') !== false ? 'shell' : 'noshell');
});
try {
    include 'index.php';
} catch (Throwable $e) {
    ob_end_clean();
    echo 'ERR|' . $e->getMessage() . '@' . $e->getLine();
    exit(1);
}
