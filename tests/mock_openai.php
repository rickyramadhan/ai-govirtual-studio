<?php
declare(strict_types=1);

// Server OpenAI-compatible PALSU untuk tes penyedia AI (dijalankan: php -S 127.0.0.1:8099 tests/mock_openai.php).
// Tidak memanggil API sungguhan. Key yang diterima hanya "mock-key-123"; model wajib diisi.
if (PHP_SAPI === 'cli') { fwrite(STDERR, "jalankan lewat php -S\n"); exit(1); }
header('Content-Type: application/json; charset=utf-8');

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/v1/chat/completions') { http_response_code(404); echo json_encode(['error' => ['message' => 'not found']]); exit; }
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer mock-key-123') { http_response_code(401); echo json_encode(['error' => ['message' => 'Invalid API key']]); exit; }
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (trim((string)($body['model'] ?? '')) === '') { http_response_code(400); echo json_encode(['error' => ['message' => 'model is required']]); exit; }
echo json_encode(['model' => $body['model'], 'choices' => [['message' => ['role' => 'assistant', 'content' => 'pong']]]]);
