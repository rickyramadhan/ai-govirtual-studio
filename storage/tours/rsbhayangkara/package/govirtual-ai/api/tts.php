<?php
/* Proxy TTS — Microsoft Edge TTS neural (gratis, lokal via Python edge-tts).
   Suara konsisten: id-ID-GadisNeural (id) / en-US-JennyNeural (en).
   Pemakaian: tts.php?text=...&lang=id&rate=-5&pitch=+2 → audio MP3. */
if (!isset($_GET['text']) || trim($_GET['text']) === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('text required');
}
$text = trim($_GET['text']);
if (function_exists('mb_substr')) { if (mb_strlen($text) > 1000) $text = mb_substr($text, 0, 1000); }
elseif (strlen($text) > 1000) { $text = substr($text, 0, 1000); }

$lang = isset($_GET['lang']) ? strtolower(preg_replace('/[^a-zA-Z-]/', '', $_GET['lang'])) : 'id';
if ($lang === '') $lang = 'id';

/* Kelembutan: rate/pitch opsional (mis. rate=-5&pitch=+2). Dibatasi agar aman. */
$rate = 0;
if (isset($_GET['rate']) && preg_match('/^[+-]?\d+$/', (string)$_GET['rate'])) $rate = (int)$_GET['rate'];
if ($rate < -30) $rate = -30; if ($rate > 30) $rate = 30;
$pitch = 0;
if (isset($_GET['pitch']) && preg_match('/^[+-]?\d+$/', (string)$_GET['pitch'])) $pitch = (int)$_GET['pitch'];
if ($pitch < -20) $pitch = -20; if ($pitch > 20) $pitch = 20;

$voices = array(
    'id'    => 'id-ID-GadisNeural',
    'id-id' => 'id-ID-GadisNeural',
    'en'    => 'en-US-JennyNeural',
    'en-us' => 'en-US-JennyNeural',
);
$voice = isset($voices[$lang]) ? $voices[$lang] : 'id-ID-GadisNeural';

function tts_cache_get(string $key): string|false {
    $dir = __DIR__ . '/cache/tts';
    $f = $dir . '/' . $key . '.mp3';
    if (!is_file($f)) return false;
    // TTL 30 hari; sentuh agar LRU bertahan.
    if (time() - (int)@filemtime($f) > 30 * 86400) { @unlink($f); return false; }
    @touch($f);
    $bin = @file_get_contents($f);
    return is_string($bin) && $bin !== '' ? $bin : false;
}

function tts_cache_put(string $key, string $bin): void {
    $dir = __DIR__ . '/cache/tts';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    if (!is_dir($dir)) return;
    // Batasi 500 file (buang yang paling lama tak dipakai).
    $files = glob($dir . '/*.mp3') ?: array();
    if (count($files) >= 500) {
        usort($files, fn($a, $b) => (int)@filemtime($a) - (int)@filemtime($b));
        foreach (array_slice($files, 0, count($files) - 499) as $old) @unlink($old);
    }
    @file_put_contents($dir . '/' . $key . '.mp3', $bin);
}

function tts_serve(string $bin, string $type): void {
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');
    echo $bin;
}

/* ---- Worker TTS persisten (opsional, untuk responsivitas) ----------------
   Menghilangkan biaya spawn Python (~0.8s) tiap request. Jika worker tidak
   tersedia/gagal, otomatis jatuh ke mode spawn per-request (perilaku lama). */
function tts_worker_port(): int {
    $p = (int)(getenv('TTS_WORKER_PORT') ?: 0);
    return ($p > 0 && $p < 65536) ? $p : 5599;
}
function tts_worker_request(string $text, string $voice, string $rateArg, string $pitchArg) {
    if (!function_exists('curl_init')) return false;
    $ch = curl_init('http://127.0.0.1:' . tts_worker_port() . '/synth');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array('text' => $text, 'voice' => $voice, 'rate' => $rateArg, 'pitch' => $pitchArg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 400,
        CURLOPT_TIMEOUT => 40,
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && is_string($body) && strlen($body) > 1024) return $body;
    return false;
}
function tts_worker_alive(): bool {
    $c = @fsockopen('127.0.0.1', tts_worker_port(), $errno, $errstr, 0.3);
    if ($c) { fclose($c); return true; }
    return false;
}
function tts_worker_ensure(string $py): void {
    // Worker sudah hidup → jangan spawn lagi. Tanpa ini, tiap request PHP yang
    // gagal konek sesaat bisa menumpuk worker zombie (pernah 20+ proses).
    if (tts_worker_alive()) return;
    $lock = sys_get_temp_dir() . '/aiguide_tts_worker.lock';
    if (is_file($lock) && (time() - (int)@filemtime($lock)) < 15) return;
    @touch($lock);
    $worker = __DIR__ . '/tts-worker.py';
    if (!is_file($worker)) return;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'start "" /B ' . escapeshellarg($py) . ' ' . escapeshellarg($worker) . ' > NUL 2>&1';
        @pclose(@popen($cmd, 'r'));
    } else {
        @exec(escapeshellarg($py) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &');
    }
}

$cacheKey = md5('edge-v1|' . $voice . '|' . $rate . '|' . $pitch . '|' . $text);
$hit = tts_cache_get($cacheKey);
if ($hit !== false) { tts_serve($hit, 'audio/mpeg'); exit; }

$py = 'C:\\Users\\Ricky\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
if (!file_exists($py)) $py = 'python';

/* proc_open + bypass_shell (Windows) agar '%' pada --rate tidak dimakan cmd.exe.
   Nilai disambung dengan '=' agar argparse tak mengira flag baru. */
$rateArg = $rate . '%';
$pitchArg = ($pitch >= 0 ? '+' : '') . $pitch . 'Hz';

/* 1) Coba worker persisten (cepat). 2) Jika tidak ada, jalankan worker
   (throttled) lalu tetap sintesis via spawn agar request ini tidak gagal. */
$audio = tts_worker_request($text, $voice, $rateArg, $pitchArg);
if ($audio === false) tts_worker_ensure($py);

$cmdArr = false;
if ($audio === false) {
    /* proc_open + bypass_shell (Windows) agar '%' pada --rate tidak dimakan cmd.exe.
       Nilai disambung dengan '=' agar argparse tak mengira flag baru. */
    $cmdArr = array($py, '-m', 'edge_tts', '--text', $text, '--voice', $voice, '--rate=' . $rateArg, '--pitch=' . $pitchArg, '--write-media', '{OUT}');
}

for ($attempts = 0; is_array($cmdArr) && $attempts < 2 && $audio === false; $attempts++) {
    if ($attempts > 0) sleep(1);
    $outFile = tempnam(sys_get_temp_dir(), 'ettst') . '.mp3';
    $cmd = $cmdArr;
    $cmd[count($cmd) - 1] = $outFile;
    $desc = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $bopts = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? array('bypass_shell' => true) : array();
    $proc = proc_open($cmd, $desc, $pipes, null, null, $bopts);
    if (is_resource($proc)) {
        if (isset($pipes[1]) && is_resource($pipes[1])) stream_get_contents($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) stream_get_contents($pipes[2]);
        foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
        proc_close($proc);
    }
    if (is_file($outFile)) {
        $bin = file_get_contents($outFile);
        @unlink($outFile);
        if (is_string($bin) && strlen($bin) > 1024) $audio = $bin;
    }
}
if (!is_string($audio) || $audio === '') {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('tts failed');
}

tts_cache_put($cacheKey, $audio);
tts_serve($audio, 'audio/mpeg');
