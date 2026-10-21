<?php
/**
 * _ai_health_check.php — Uji hidup setiap provider/model AI yang dipakai AI Guide.
 * Memakai ai_provider_request() dari runtime master (sama dengan jalur produksi),
 * model diambil dari settings hub, lalu hasilnya dicatat ke tabel ai_health_log.
 *
 * Usage (manual / terjadwal):
 *   c:\xampp\php\php.exe _ai_health_check.php
 * Exit code 1 bila provider utama (AI_PROVIDER) gagal.
 */

declare(strict_types=1);

// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const HEALTH_RUNTIME = 'C:/xampp/htdocs/govirtual-ai/govirtual-ai/api/ai-provider.php';

$db = new PDO('sqlite:' . __DIR__ . '/storage/app.sqlite');
$db->exec('CREATE TABLE IF NOT EXISTS ai_health_log (id INTEGER PRIMARY KEY AUTOINCREMENT, checked_at TEXT NOT NULL, provider TEXT NOT NULL, model TEXT NOT NULL, ok INTEGER NOT NULL, latency_ms INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT "")');
$db->exec('CREATE INDEX IF NOT EXISTS idx_ai_health_checked ON ai_health_log(checked_at)');

$settings = [];
foreach ($db->query("SELECT skey, svalue FROM settings") as $r) $settings[$r['skey']] = $r['svalue'];

$hasKey = function (string $provider) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) FROM api_keys WHERE provider=? AND is_active=1');
    $s->execute([$provider]);
    return (int)$s->fetchColumn() > 0;
};

// Daftar uji: provider => model yang benar-benar dipakai runtime.
$tests = [];
if ($hasKey('gemini')) {
    $tests[] = ['gemini', $settings['GEMINI_MODEL'] ?? 'gemini-2.0-flash'];
    foreach (array_filter(array_map('trim', explode(',', $settings['GEMINI_FALLBACK_MODELS'] ?? ''))) as $m) $tests[] = ['gemini', $m];
}
if ($hasKey('groq')) $tests[] = ['groq', $settings['GROQ_MODEL'] ?? 'openai/gpt-oss-120b'];
if ($hasKey('openrouter')) $tests[] = ['openrouter', $settings['OPENROUTER_MODEL'] ?? 'openai/gpt-4o-mini'];
if ($hasKey('opencode')) $tests[] = ['opencode', $settings['OPENCODE_MODEL'] ?? 'big-pickle'];

$primary = strtolower($settings['AI_PROVIDER'] ?? 'gemini');
$now = date('c');
$results = [];

if ($tests) {
    // Jalankan runtime di proses terpisah agar fungsinya tidak bentrok dengan hub.
    $php = PHP_BINARY ?: 'C:/xampp/php/php.exe';
    $worker = sys_get_temp_dir() . '/ai-health-worker-' . getmypid() . '.php';
    $code = '<?php require ' . var_export(HEALTH_RUNTIME, true) . ';'
        . '$tests = json_decode(file_get_contents($argv[1]), true); $out = [];'
        . 'foreach ($tests as [$p, $m]) { $r = ai_provider_request($p, [["role" => "system", "content" => "Jawab singkat."], ["role" => "user", "content" => "Balas satu kata: OK"]], ["model" => $m, "system" => "Jawab singkat.", "user" => "Balas satu kata: OK", "max_tokens" => 64, "timeout" => 20, "json_mode" => false, "models" => [$m]]);'
        . '$out[] = ["provider" => $p, "model" => $m, "ok" => !empty($r["success"]), "latency_ms" => (int)($r["latency_ms"] ?? 0), "error" => (string)($r["error"] ?? "")]; }'
        . 'echo json_encode($out, JSON_UNESCAPED_UNICODE);';
    $argFile = $worker . '.json';
    file_put_contents($worker, $code);
    file_put_contents($argFile, json_encode($tests));
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($argFile);
    $raw = (string)shell_exec($cmd . ' 2>&1');
    @unlink($worker); @unlink($argFile);
    $decoded = json_decode(trim($raw), true);
    if (!is_array($decoded)) $decoded = [];
    foreach ($decoded as $r) {
        $err = preg_replace('/(AQ\.|sk-or-|sk-|gsk_)[A-Za-z0-9_\-]{4,}/', '$1***', substr((string)$r['error'], 0, 200));
        $results[] = $r + ['error' => $err];
    }
    if (!$results) $results[] = ['provider' => 'runner', 'model' => '-', 'ok' => false, 'latency_ms' => 0, 'error' => 'runner gagal: ' . substr($raw, 0, 200)];
} else {
    $results[] = ['provider' => 'none', 'model' => '-', 'ok' => false, 'latency_ms' => 0, 'error' => 'Tidak ada API key aktif di hub.'];
}

$ins = $db->prepare('INSERT INTO ai_health_log(checked_at, provider, model, ok, latency_ms, error) VALUES(?,?,?,?,?,?)');
foreach ($results as $r) $ins->execute([$now, $r['provider'], $r['model'], $r['ok'] ? 1 : 0, (int)$r['latency_ms'], (string)$r['error']]);
// Simpan rentang 90 hari terakhir saja.
$db->prepare('DELETE FROM ai_health_log WHERE checked_at < ?')->execute([date('c', strtotime('-90 days'))]);

foreach ($results as $r) {
    printf("[%s] %-11s %-26s %s %dms%s\n", $now, $r['provider'], $r['model'], $r['ok'] ? 'OK  ' : 'GAGAL', (int)$r['latency_ms'], $r['error'] !== '' ? ' — ' . $r['error'] : '');
}
// Provider utama dianggap OK bila minimal satu model-nya berhasil.
$primaryOk = false;
foreach ($results as $r) if ($r['provider'] === $primary && $r['ok']) $primaryOk = true;
echo $primaryOk ? "Provider utama ($primary): OK\n" : "Provider utama ($primary): GAGAL\n";
exit($primaryOk ? 0 : 1);
