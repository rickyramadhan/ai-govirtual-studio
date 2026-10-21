<?php
declare(strict_types=1);

// Helper CLI untuk integration_test.php: menjalankan SATU request ke salinan app di folder sementara.
// Pemakaian: php tests/_request.php <tmpdir> <base64(json{get,post,session})>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$dir = $argv[1] ?? ''; $spec = json_decode((string)base64_decode($argv[2] ?? ''), true) ?: [];
if (!is_dir($dir)) { fwrite(STDERR, "tmpdir tidak ada\n"); exit(2); }
chdir($dir);

define('STUDIO_ROUTE_RUNNER', true);
$_GET = $spec['get'] ?? [];
$_POST = $spec['post'] ?? [];
$_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/?a=' . ($_GET['a'] ?? 'list');

// Session buatan: user + token CSRF yang diketahui test.
session_save_path($dir . '/sessions');
session_id('testsession');
@session_start();
$_SESSION = array_merge($_SESSION, ['csrf_token' => 'TESTTOKEN'], $spec['session'] ?? []);
session_write_close();

ob_start();
register_shutdown_function(function () { echo ob_get_clean(); });
include $dir . '/index.php';
