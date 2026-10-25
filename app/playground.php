<?php

declare(strict_types=1);

/**
 * Playground: chat langsung untuk menguji AI Guide per kota dan per lokasi.
 * - Lokasi yang sudah dideploy dijawab oleh tour-chat.php di folder ekspornya (sama dengan produksi).
 * - Selain itu jawaban disimulasikan dari aturan AI kota + pengetahuan kota, lalu dikirim ke penyedia AI
 *   sesuai urutan primer → cadangan di Penyedia AI (key dibaca dari database hub).
 * Hanya Studio; runtime AI Guide tidak diubah.
 */

/** Daftar kota (dalam cakupan pengguna) beserta lokasinya, untuk pilihan di halaman. */
function play_city_map(): array
{
    $tours = all_tours();
    $out = [];
    foreach (all_cities() as $c) {
        $slug = (string)$c['slug'];
        $list = [];
        foreach ($tours as $t) {
            if (tour_city_slug($t) !== $slug) continue;
            $list[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'location' => (string)($t['location'] ?? ''), 'deployed' => play_api_url($t) !== ''];
        }
        $out[] = ['slug' => $slug, 'name' => (string)$c['name'], 'tours' => $list, 'topics' => count(city_knowledge($slug)['entries'] ?? []), 'hasRules' => trim((string)(read_json(city_dir($slug) . '/meta.json')['instructions'] ?? '')) !== ''];
    }
    return $out;
}

/** URL tour-chat.php lokasi (http://localhost/...), atau '' bila lokasi belum berada di htdocs. */
function play_api_url(array $tour): string
{
    $htdocs = rtrim(str_replace('\\', '/', dirname(APP_ROOT)), '/');
    $export = rtrim(str_replace('\\', '/', (string)($tour['export_path'] ?? '')), '/');
    if ($export === '' || !str_starts_with($export, $htdocs . '/')) return '';
    $rel = implode('/', array_map('rawurlencode', explode('/', substr($export, strlen($htdocs) + 1))));
    $url = 'http://localhost/' . $rel . '/govirtual-ai/api/tour-chat.php';
    return is_file(rtrim($export, '/') . '/govirtual-ai/api/tour-chat.php') ? $url : '';
}

/** Scene (id & nama) dari parsed.json lokasi. */
function play_scenes(array $tour): array
{
    $parsed = read_json(tour_dir($tour['slug']) . '/parsed.json', []);
    $out = [];
    foreach (($parsed['scenes'] ?? []) as $s) {
        $out[] = ['id' => (string)$s['id'], 'name' => (string)($s['displayName'] ?? $s['name'] ?? $s['id'])];
    }
    return $out;
}

/** Pilih topik pengetahuan yang paling relevan dengan pertanyaan (cocok kata kunci sederhana). */
function play_pick_topics(array $entries, string $q, int $max = 6): array
{
    $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [], fn($w) => mb_strlen($w) >= 3)));
    $scored = [];
    foreach ($entries as $e) {
        $hay = mb_strtolower(($e['title'] ?? '') . ' ' . implode(' ', (array)($e['aliases'] ?? [])) . ' ' . ($e['content'] ?? ''));
        $score = 0;
        foreach ($words as $w) if (str_contains($hay, $w)) $score++;
        if ($score > 0) $scored[] = [$score, $e];
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    $picked = array_map(fn($x) => $x[1], array_slice($scored, 0, $max));
    // Tidak ada yang cocok: beri topik umum kota supaya AI tetap punya konteks.
    return $picked ?: array_slice($entries, 0, 3);
}

/** Prompt sistem simulasi: aturan AI kota + konteks lokasi + pengetahuan yang relevan. */
function play_system_prompt(array $city, ?array $tour, string $scene, array $picks): string
{
    $rules = trim((string)(read_json(city_dir($city['slug']) . '/meta.json')['instructions'] ?? ''));
    $s = $rules !== '' ? $rules : 'Kamu adalah pemandu virtual untuk ' . $city['name'] . '. Jawab ramah dan informatif.';
    if ($tour) {
        $s .= "\n\nLOKASI: " . $tour['name'] . ($tour['location'] !== '' ? ' (' . $tour['location'] . ')' : '') . '.';
        if ($scene !== '') $s .= ' Pengunjung sedang melihat panorama: ' . $scene . '.';
    }
    if ($picks) {
        $s .= "\n\nPENGETAHUAN RELEVAN:";
        foreach ($picks as $p) $s .= "\n- " . $p['title'] . ': ' . mb_substr((string)$p['content'], 0, 700);
    }
    $s .= "\n\nATURAN JAWABAN: Jawab dalam bahasa pertanyaan pengguna. Jika informasi tidak ada di pengetahuan, katakan dengan jujur belum tahu dan sarankan verifikasi ke sumber resmi. Jangan menyebut berkas internal (knowledge.json, database, retrieval).";
    return $s;
}

/** Base URL per penyedia (kosong bila belum diisi). */
function play_base(string $prov): string
{
    if ($prov === 'groq') return 'https://api.groq.com/openai/v1';
    $map = ['openrouter' => 'OPENROUTER_BASE_URL', 'ollama' => 'OLLAMA_URL'];
    return rtrim(setting_get($map[$prov] ?? provider_env_name($prov) . '_BASE_URL', ''), '/');
}

/** Panggil satu penyedia dengan satu key. Mengembalikan ['ok','reply','error','http','ms']. */
function play_call(string $prov, string $key, string $model, array $messages, string $system): array
{
    $res = ['ok' => false, 'reply' => '', 'error' => '', 'http' => 0, 'ms' => 0];
    if ($prov === 'gemini') {
        $contents = [];
        foreach ($messages as $m) $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
        $r = test_key_request('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
            ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 900]], 25);
        $d = json_decode((string)$r['body'], true);
        $res['reply'] = trim((string)($d['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    } elseif ($prov === 'ollama') {
        if (setting_get('OLLAMA_ENABLED', 'false') !== 'true') { $res['error'] = 'Ollama nonaktif (atur di Penyedia AI).'; return $res; }
        $base = play_base('ollama') ?: 'http://127.0.0.1:11434';
        $msgs = array_merge([['role' => 'system', 'content' => $system]], $messages);
        $r = test_key_request($base . '/api/chat', ['Content-Type: application/json'], ['model' => $model, 'messages' => $msgs, 'stream' => false, 'options' => ['temperature' => 0.3]], 25);
        $d = json_decode((string)$r['body'], true);
        $res['reply'] = trim((string)($d['message']['content'] ?? ''));
    } else {
        $base = play_base($prov);
        if ($base === '') { $res['error'] = 'Base URL belum diisi.'; return $res; }
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        if ($prov === 'openrouter') $headers[] = 'HTTP-Referer: ' . (getenv('APP_URL') ?: 'https://localhost');
        if ($prov === 'opencode') $headers[] = 'x-opencode-session: govirtual-app';
        $msgs = array_merge([['role' => 'system', 'content' => $system]], $messages);
        $r = test_key_request($base . '/chat/completions', $headers, ['model' => $model, 'messages' => $msgs, 'temperature' => 0.3, 'max_tokens' => 900], 25);
        $d = json_decode((string)$r['body'], true);
        $res['reply'] = trim((string)($d['choices'][0]['message']['content'] ?? ''));
    }
    $res['http'] = (int)$r['code']; $res['ms'] = (int)$r['time_ms'];
    if ($res['reply'] !== '') { $res['ok'] = true; return $res; }
    $err = $d['error'] ?? '';
    $msg = is_array($err) ? (string)($err['message'] ?? '') : (string)$err;
    $res['error'] = $r['error'] !== '' ? 'curl: ' . $r['error'] : ($msg !== '' ? $msg : 'balasan kosong (HTTP ' . $r['code'] . ')');
    return $res;
}

/** Coba penyedia primer lalu cadangan; tiap penyedia memakai maks. 2 key aktif. */
function play_chain(string $system, array $messages): array
{
    $primary = setting_get('AI_PROVIDER', 'gemini');
    $fallbacks = array_values(array_filter(array_map('trim', explode(',', setting_get('AI_FALLBACK_PROVIDERS', '')))));
    $order = array_values(array_unique(array_filter(array_merge([$primary], $fallbacks))));
    $attempts = [];
    foreach (array_slice($order, 0, 4) as $prov) {
        $model = match ($prov) {
            'gemini' => setting_get('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
            'openrouter' => setting_get('OPENROUTER_MODEL', ''),
            'groq' => setting_get('GROQ_MODEL', ''),
            'ollama' => setting_get('OLLAMA_MODEL', ''),
            default => setting_get(provider_env_name($prov) . '_MODEL', ''),
        };
        if ($model === '') { $attempts[] = ['provider' => $prov, 'ok' => false, 'error' => 'model belum diisi']; continue; }
        $keys = $prov === 'ollama' ? [''] : array_slice(api_key_active_keys($prov), 0, 2);
        if (!$keys) { $attempts[] = ['provider' => $prov, 'ok' => false, 'error' => 'tidak ada key aktif']; continue; }
        foreach ($keys as $key) {
            $r = play_call($prov, $key, $model, $messages, $system);
            if ($r['ok']) {
                $attempts[] = ['provider' => $prov, 'ok' => true, 'error' => ''];
                return ['ok' => true, 'provider' => $prov, 'model' => $model, 'reply' => $r['reply'], 'ms' => $r['ms'], 'attempts' => $attempts];
            }
            $attempts[] = ['provider' => $prov, 'ok' => false, 'error' => $r['error']];
        }
    }
    return ['ok' => false, 'provider' => '', 'model' => '', 'reply' => '', 'ms' => 0, 'attempts' => $attempts];
}

/** Handler JSON untuk ?a=playAnswer (POST, login, CSRF). */
function play_answer_route(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $user = current_user();
    if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Sesi login berakhir. Muat ulang halaman.']); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }
    if (!csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF tidak valid. Muat ulang halaman.']); exit; }
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok' => false, 'error' => 'Tulis pertanyaan dulu.']); exit; }
    if (mb_strlen($q) > 1000) { echo json_encode(['ok' => false, 'error' => 'Pertanyaan terlalu panjang (maks 1000 karakter).']); exit; }
    $c = city_row_by_slug(city_slugify((string)($_POST['city'] ?? '')));
    if (!$c) { echo json_encode(['ok' => false, 'error' => 'Pilih kota dulu.']); exit; }
    if (!city_in_scope((int)$c['id'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Kota ini di luar cakupan Anda.']); exit; }
    $city = ['slug' => (string)$c['slug'], 'name' => (string)$c['name']];
    $tour = null;
    if ((int)($_POST['tour'] ?? 0) > 0) {
        $tour = get_tour((int)$_POST['tour']);
        if (!$tour || tour_city_slug($tour) !== $city['slug']) { echo json_encode(['ok' => false, 'error' => 'Lokasi tidak ada di kota ini.']); exit; }
    }
    $scene = (string)($_POST['scene'] ?? '');
    $hist = json_decode((string)($_POST['history'] ?? '[]'), true);
    $history = [];
    foreach (array_slice(is_array($hist) ? $hist : [], -6) as $h) {
        $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string)($h['content'] ?? ''));
        if ($content !== '') $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
    }

    // 1) Lokasi terdeploy → jawab lewat tour-chat.php (jalur produksi).
    if ($tour) {
        $apiUrl = play_api_url($tour);
        if ($apiUrl !== '') {
            $parsed = read_json(tour_dir($tour['slug']) . '/parsed.json', []);
            $sid = $scene !== '' ? $scene : (string)($parsed['startNode'] ?? ($parsed['scenes'][0]['id'] ?? ''));
            $payload = [
                'aiRequestId' => 'playground-' . bin2hex(random_bytes(3)),
                'message' => $q, 'currentScene' => $sid, 'history' => [],
                'conversationState' => ['lastSceneId' => $sid, 'visitedSceneIds' => [$sid], 'visitorProfile' => []],
                'viewContext' => [],
                'tourContext' => ['scenes' => $parsed['scenes'] ?? [], 'hotspots' => $parsed['hotspots'] ?? [], 'routes' => $parsed['routes'] ?? [], 'video360' => $parsed['video360'] ?? []],
            ];
            $r = testanswer_http_post($apiUrl, $payload);
            $d = $r['ok'] ? json_decode((string)$r['body'], true) : null;
            $reply = is_array($d) ? trim((string)($d['reply'] ?? $d['respons'] ?? $d['answer'] ?? '')) : '';
            if ($reply !== '') {
                echo json_encode(['ok' => true, 'mode' => 'deployed', 'reply' => $reply, 'provider' => (string)($d['provider'] ?? 'tour-chat'), 'model' => (string)($d['model'] ?? ''), 'ms' => (int)($d['latencyMs'] ?? 0), 'sources' => [], 'attempts' => [], 'note' => ''], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    // 2) Simulasi dari aturan kota + pengetahuan kota → penyedia AI.
    $picks = play_pick_topics(city_knowledge($city['slug'])['entries'] ?? [], $q);
    $system = play_system_prompt($city, $tour, $scene, $picks);
    $messages = array_merge($history, [['role' => 'user', 'content' => $q]]);
    $ai = play_chain($system, $messages);
    $note = $tour ? 'Lokasi ini belum dideploy atau tidak bisa dijangkau, jadi jawaban disimulasikan dari aturan dan pengetahuan kota.' : 'Simulasi kota: jawaban dari aturan AI dan pengetahuan kota.';
    if (!$ai['ok']) {
        echo json_encode(['ok' => false, 'error' => 'Semua penyedia AI gagal menjawab. Cek Penyedia AI (key, model, dan cadangan).', 'attempts' => $ai['attempts'], 'note' => $note], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'mode' => 'simulasi', 'reply' => $ai['reply'], 'provider' => $ai['provider'], 'model' => $ai['model'], 'ms' => $ai['ms'],
        'sources' => array_map(fn($p) => (string)$p['title'], $picks), 'attempts' => $ai['attempts'], 'note' => $note], JSON_UNESCAPED_UNICODE);
    exit;
}

function render_playground(): void
{
    $cities = play_city_map();
    if (!$cities) { layout('Playground', '<div class="card"><h2>Belum ada kota</h2><p>Tambahkan kota dulu untuk memakai Playground.</p></div>'); return; }
    $wantCity = city_slugify((string)($_GET['city'] ?? ''));
    $cur = null;
    foreach ($cities as $c) if ($c['slug'] === $wantCity) $cur = $c;
    if ($cur === null) { $cur = $cities[0]; foreach ($cities as $c) if ($c['tours']) { $cur = $c; break; } }
    $tourId = (int)($_GET['tour'] ?? 0);
    $curTour = null;
    foreach ($cur['tours'] as $t) if ($t['id'] === $tourId) $curTour = $t;

    $cityOpts = '';
    foreach ($cities as $c) $cityOpts .= '<option value="' . h($c['slug']) . '"' . ($c['slug'] === $cur['slug'] ? ' selected' : '') . '>' . h($c['name']) . ' (' . count($c['tours']) . ' lokasi)</option>';
    $tourOpts = '<option value="0">Semua lokasi (simulasi kota)</option>';
    foreach ($cur['tours'] as $t) $tourOpts .= '<option value="' . $t['id'] . '"' . ($curTour && $curTour['id'] === $t['id'] ? ' selected' : '') . '>' . h($t['name']) . ($t['deployed'] ? ' · terdeploy' : ' · belum dideploy') . '</option>';

    // Data lokasi terpilih untuk pilihan scene (dibaca JS).
    $sceneData = [];
    if ($curTour) {
        $full = get_tour($curTour['id']);
        if ($full) $sceneData = play_scenes($full);
    }
    $sceneOpts = '<option value="">Panorama pertama</option>';
    foreach ($sceneData as $s) $sceneOpts .= '<option value="' . h($s['id']) . '">' . h($s['name']) . '</option>';

    $rules = $cur['hasRules'] ? '<span class="pg-ok">✓ Aturan AI sudah diisi</span>' : '<span class="pg-warn">Aturan AI belum diisi</span>';
    $deployInfo = !$curTour ? '<span class="pg-muted">Mode: simulasi kota</span>'
        : ($curTour['deployed'] ? '<span class="pg-ok">✓ Lokasi terdeploy (jawaban dari AI Guide asli)</span>' : '<span class="pg-warn">Belum terdeploy → disimulasikan dari aturan &amp; pengetahuan kota</span>');
    $chain = setting_get('AI_PROVIDER', 'gemini') . (($fb = setting_get('AI_FALLBACK_PROVIDERS', '')) !== '' ? ' → ' . $fb : '');

    $starters = ['Apa yang paling menarik dikunjungi di sini?', 'Bagaimana cara ke sana dan jam berapa buka?', 'Ceritakan sejarah atau latar belakang tempat ini.'];
    $chips = '';
    foreach ($starters as $s) $chips .= '<button type="button" class="pg-chip" data-q="' . h($s) . '">' . h($s) . '</button>';

    $body = '<style>' . render_playground_css() . '</style>'
        . '<div class="pg">'
        . '<header class="pg-hero"><div class="pg-kicker">Simulasi AI Guide</div><h1>Playground</h1>'
        . '<p>Uji AI Guide langsung: pilih kota, lalu lokasi kalau ingin menguji satu tur. Tanyakan apa saja dan lihat jawabannya sebelum tur dipublikasikan.</p></header>'
        . '<div class="pg-grid">'
        . '<aside class="pg-side card">'
        . '<form method="get" action="?" class="pg-form"><input type="hidden" name="a" value="playground">'
        . '<label class="pg-field"><span>Kota</span><select name="city" onchange="this.form.submit()">' . $cityOpts . '</select></label>'
        . '<label class="pg-field"><span>Lokasi</span><select name="tour" onchange="this.form.submit()">' . $tourOpts . '</select></label>'
        . '<noscript><button class="pg-btn" type="submit">Terapkan</button></noscript></form>'
        . '<label class="pg-field" id="pgSceneWrap"' . ($curTour ? '' : ' hidden') . '><span>Panorama (opsional)</span><select id="pgScene">' . $sceneOpts . '</select></label>'
        . '<div class="pg-status"><div>' . $rules . '</div><div>' . $deployInfo . '</div>'
        . '<div><span class="pg-muted">Pengetahuan kota: <b>' . (int)$cur['topics'] . '</b> topik</span></div>'
        . '<div><span class="pg-muted">Penyedia: <b>' . h($chain) . '</b></span></div></div>'
        . '<button type="button" class="pg-btn pg-btn-ghost" id="pgReset">Mulai percakapan baru</button>'
        . '</aside>'
        . '<section class="pg-chat card">'
        . '<div id="pgLog" class="pg-log" aria-live="polite"><div class="pg-bubble pg-bot"><div class="pg-who">AI Guide</div><div>Halo! Saya pemandu virtual untuk <b>' . h($cur['name']) . '</b>' . ($curTour ? ' — lokasi <b>' . h($curTour['name']) . '</b>' : '') . '. Silakan tanyakan apa saja.</div></div></div>'
        . '<div class="pg-chips">' . $chips . '</div>'
        . '<form id="pgForm" method="post" action="?a=playAnswer" class="pg-input"><textarea id="pgQ" rows="2" maxlength="1000" placeholder="Ketik pertanyaan pengunjung…" aria-label="Pertanyaan"></textarea>'
        . '<button class="pg-btn pg-btn-primary" id="pgSend" type="submit">Kirim</button></form>'
        . '</section></div></div>'
        . '<script>' . render_playground_js($cur['slug'], $curTour ? (string)$curTour['id'] : '0') . '</script>';
    layout('Playground', $body);
}

function render_playground_css(): string
{
    return <<<'CSS'
.pg{display:flex;flex-direction:column;gap:var(--space-5)}
.pg-hero{border-radius:22px;padding:26px 30px;background:linear-gradient(135deg,#25221d 0%,#17161a 55%,#121315 100%);border:1px solid rgba(201,169,110,.18);box-shadow:0 18px 50px rgba(0,0,0,.35)}
.pg-kicker{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--ai-accent)}
.pg-hero h1{font-family:var(--font-display);font-size:28px;margin:6px 0;letter-spacing:-.02em}
.pg-hero p{color:var(--text-secondary);max-width:72ch;margin:0;font-size:14px}
.pg-grid{display:grid;grid-template-columns:300px 1fr;gap:18px;align-items:start}
.pg-side{display:flex;flex-direction:column;gap:14px;position:sticky;top:12px}
.pg-form{display:flex;flex-direction:column;gap:12px}
.pg-field{display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--text-secondary)}
.pg-field[hidden]{display:none}
.pg-status{display:flex;flex-direction:column;gap:8px;font-size:12.5px;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.025);border:1px solid var(--border)}
.pg-ok{color:var(--success)}
.pg-warn{color:var(--warning)}
.pg-muted{color:var(--text-muted)}
.pg-btn{font:inherit;font-size:13px;font-weight:600;padding:9px 16px;border-radius:999px;border:1px solid var(--border-strong);background:rgba(255,255,255,.03);color:var(--text-primary);cursor:pointer;transition:transform .2s,border-color .2s,background .2s}
.pg-btn:hover{border-color:var(--ai-accent);background:rgba(201,169,110,.08)}
.pg-btn:disabled{opacity:.5;cursor:default}
.pg-btn-primary{background:linear-gradient(135deg,#e2c28a,#c9a96e);border-color:transparent;color:#1a1409}
.pg-btn-primary:hover{background:linear-gradient(135deg,#ecd29f,#d4b27a);color:#1a1409}
.pg-btn-ghost{background:transparent}
.pg-chat{display:flex;flex-direction:column;gap:12px;min-height:620px}
.pg-log{flex:1;display:flex;flex-direction:column;gap:12px;overflow-y:auto;max-height:560px;padding:4px 2px}
.pg-bubble{max-width:82%;padding:12px 14px;border-radius:16px;font-size:14px;line-height:1.6;white-space:pre-wrap;word-wrap:break-word}
.pg-user{align-self:flex-end;background:rgba(201,169,110,.14);border:1px solid rgba(201,169,110,.3)}
.pg-bot{align-self:flex-start;background:var(--surface-2);border:1px solid var(--border)}
.pg-who{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--ai-accent);margin-bottom:4px;font-weight:700}
.pg-meta{margin-top:8px;padding-top:8px;border-top:1px solid var(--border);font-size:11px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:6px 10px}
.pg-meta b{color:var(--text-secondary);font-weight:600}
.pg-note{margin-top:6px;font-size:11.5px;color:var(--warning)}
.pg-err{color:var(--danger)}
.pg-chips{display:flex;flex-wrap:wrap;gap:8px}
.pg-chip{font:inherit;font-size:12px;padding:6px 12px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.02);color:var(--text-secondary);cursor:pointer;transition:border-color .2s,color .2s}
.pg-chip:hover{border-color:var(--ai-accent);color:var(--text-primary)}
.pg-input{display:flex;gap:10px;align-items:flex-end}
.pg-input textarea{flex:1;resize:vertical;min-height:52px}
.pg-input .pg-btn{height:44px}
.pg-typing{color:var(--text-muted);font-style:italic}
@media (max-width:920px){.pg-grid{grid-template-columns:1fr}.pg-side{position:static}.pg-chat{min-height:0}.pg-bubble{max-width:94%}}
@media (prefers-reduced-motion:reduce){.pg-btn,.pg-chip{transition:none!important}}
CSS;
}

function render_playground_js(string $citySlug, string $tourId): string
{
    $cfg = json_encode(['city' => $citySlug, 'tour' => $tourId], JSON_UNESCAPED_SLASHES);
    return 'var PG=' . $cfg . ';' . <<<'JS'
(function(){
  var log=document.getElementById("pgLog"), form=document.getElementById("pgForm"), q=document.getElementById("pgQ"), send=document.getElementById("pgSend");
  var sceneSel=document.getElementById("pgScene"), history=[], busy=false;
  function esc(s){var d=document.createElement("div");d.textContent=(s==null?"":String(s));return d.innerHTML;}
  function fmt(s){return esc(s).replace(/\*\*([^*\n]+)\*\*/g,"<b>$1</b>").replace(/\*([^*\n]+)\*/g,"<i>$1</i>");}
  function bubble(cls,who,html){var d=document.createElement("div");d.className="pg-bubble "+cls;d.innerHTML=(who?'<div class="pg-who">'+esc(who)+'</div>':'')+html;log.appendChild(d);log.scrollTop=log.scrollHeight;return d;}
  function userMsg(text){bubble("pg-user","Pengunjung",esc(text));}
  function meta(d){
    var parts=[];
    parts.push(d.mode==="deployed"?"<span>Mode: <b>AI Guide terdeploy</b></span>":"<span>Mode: <b>simulasi</b></span>");
    if(d.provider) parts.push("<span>Penyedia: <b>"+esc(d.provider)+"</b></span>");
    if(d.model) parts.push("<span>Model: <b>"+esc(d.model)+"</b></span>");
    if(d.ms) parts.push("<span>"+d.ms+" ms</span>");
    var h='<div class="pg-meta">'+parts.join("")+'</div>';
    if(d.sources&&d.sources.length) h+='<div class="pg-meta"><span>Topik dipakai:</span> '+d.sources.map(function(s){return "<span>"+esc(s)+"</span>";}).join(" · ")+'</div>';
    if(d.note) h+='<div class="pg-note">'+esc(d.note)+'</div>';
    if(d.attempts&&d.attempts.length>1) h+='<div class="pg-meta"><span>Percobaan:</span> '+d.attempts.map(function(a){return "<span>"+esc(a.provider)+(a.ok?" ✓":" ✗ "+esc(a.error||""))+"</span>";}).join(" · ")+'</div>';
    return h;
  }
  function ask(text){
    text=(text||"").trim(); if(!text||busy) return;
    busy=true; send.disabled=true; userMsg(text);
    var wait=bubble("pg-bot","AI Guide",'<span class="pg-typing">sedang menjawab…</span>');
    var fd=new FormData(); fd.append("city",PG.city); fd.append("tour",PG.tour); fd.append("scene",sceneSel&&!sceneSel.parentNode.hidden?sceneSel.value:"");
    fd.append("q",text); fd.append("history",JSON.stringify(history.slice(-6))); fd.append("csrf_token",form.querySelector("[name=csrf_token]").value);
    fetch("?a=playAnswer",{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){
      if(d.ok){
        wait.innerHTML='<div class="pg-who">AI Guide</div><div style="white-space:pre-wrap">'+fmt(d.reply)+'</div>'+meta(d);
        history.push({role:"user",content:text}); history.push({role:"assistant",content:d.reply});
      } else {
        wait.innerHTML='<div class="pg-who">AI Guide</div><div class="pg-err">'+esc(d.error||"Gagal menjawab.")+'</div>'+(d.attempts?meta({attempts:d.attempts,note:d.note}):"");
      }
    }).catch(function(e){wait.innerHTML='<div class="pg-err">Gagal menghubungi server: '+esc(e)+'</div>';})
    .finally(function(){busy=false; send.disabled=false; q.value=""; q.focus();});
  }
  form.addEventListener("submit",function(e){e.preventDefault();ask(q.value);});
  q.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();ask(q.value);}});
  document.querySelectorAll("[data-q]").forEach(function(b){b.addEventListener("click",function(){ask(b.getAttribute("data-q"));});});
  document.getElementById("pgReset").addEventListener("click",function(){history=[];log.innerHTML="";bubble("pg-bot","AI Guide","Percakapan baru dimulai. Silakan tanyakan apa saja.");});
})();
JS;
}
