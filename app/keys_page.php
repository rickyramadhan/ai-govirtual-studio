<?php

declare(strict_types=1);

/**
 * Halaman Penyedia AI (API Keys): ringkasan primer & cadangan, kartu per penyedia, pengelolaan key
 * dan endpoint, penambahan penyedia OpenAI-compatible (dengan tes sebelum simpan), dan pengaturan routing.
 * Nama field POST dipertahankan agar handler saveKeys di index.php tetap dipakai.
 * Key tidak pernah ditampilkan ke browser; tes dijalankan server-side.
 */

const KP_BUILTIN = ['gemini', 'openrouter', 'groq', 'ollama'];

function kp_label(string $prov): string
{
    return match ($prov) {
        'gemini' => 'Gemini (Google)',
        'openrouter' => 'OpenRouter',
        'groq' => 'Groq',
        'ollama' => 'Ollama (lokal)',
        default => ucfirst($prov) . ' (OpenAI-compatible)',
    };
}

function kp_model(string $prov): string
{
    $map = ['gemini' => 'GEMINI_MODEL', 'openrouter' => 'OPENROUTER_MODEL', 'groq' => 'GROQ_MODEL', 'ollama' => 'OLLAMA_MODEL'];
    return setting_get($map[$prov] ?? provider_env_name($prov) . '_MODEL', '');
}

/** Alamat endpoint untuk ditampilkan (tanpa path panjang). */
function kp_endpoint(string $prov): string
{
    $fixed = ['gemini' => 'generativelanguage.googleapis.com', 'groq' => 'api.groq.com'];
    if (isset($fixed[$prov])) return $fixed[$prov];
    $map = ['openrouter' => 'OPENROUTER_BASE_URL', 'ollama' => 'OLLAMA_URL'];
    $url = setting_get($map[$prov] ?? provider_env_name($prov) . '_BASE_URL', '');
    $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
    return is_string($host) && $host !== '' ? $host : '';
}

/** Pilihan <option> untuk <select>. */
function kp_options(array $list, string $current): string
{
    $out = '';
    foreach ($list as $value => $label) {
        $out .= '<option value="' . h((string)$value) . '"' . ((string)$value === $current ? ' selected' : '') . '>' . h((string)$label) . '</option>';
    }
    return $out;
}

/** Field pengaturan (input) yang nilainya diambil dari tabel settings. */
function kp_field(string $key, string $label, string $help = '', string $placeholder = ''): string
{
    return '<label class="kp-field"><span>' . h($label) . '</span>'
        . '<input name="set_' . h($key) . '" id="set_' . h($key) . '" value="' . h(setting_get($key, '')) . '" placeholder="' . h($placeholder) . '" class="mono" spellcheck="false" autocomplete="off">'
        . ($help !== '' ? '<small class="kp-muted">' . h($help) . '</small>' : '') . '</label>';
}

/** Field pilihan (select) yang nilainya diambil dari tabel settings. */
function kp_select(string $key, string $label, array $options, string $help = ''): string
{
    $cur = setting_get($key, '');
    return '<label class="kp-field"><span>' . h($label) . '</span>'
        . '<select name="set_' . h($key) . '" id="set_' . h($key) . '">' . kp_options($options, $cur) . '</select>'
        . ($help !== '' ? '<small class="kp-muted">' . h($help) . '</small>' : '') . '</label>';
}

function render_keys_page(string $error = ''): void
{
    $keys = api_key_list();
    $byProv = [];
    foreach ($keys as $idx => $r) $byProv[$r['provider']][] = ['row' => $r, 'idx' => $idx];
    $custom = array_values(array_filter(api_key_providers(), fn($p) => !in_array($p, KP_BUILTIN, true)));
    $all = array_merge(KP_BUILTIN, $custom);
    $primary = setting_get('AI_PROVIDER', 'gemini');
    $fallbacks = array_values(array_filter(array_map('trim', explode(',', setting_get('AI_FALLBACK_PROVIDERS', '')))));
    $totalActive = 0;
    foreach ($keys as $r) if ((int)$r['is_active'] === 1) $totalActive++;

    // Status satu penyedia: peran (primer/cadangan/tidak dipakai) dan peringatan bila belum punya key aktif.
    $status = function (string $p) use ($byProv, $primary, $fallbacks): array {
        $active = 0;
        foreach ($byProv[$p] ?? [] as $e) if ((int)$e['row']['is_active'] === 1) $active++;
        if ($p !== 'ollama' && $active === 0) return ['warn', 'Belum ada key aktif', $active];
        if ($p === $primary) return ['primer', 'Primer', $active];
        if (in_array($p, $fallbacks, true)) return ['cadangan', 'Cadangan', $active];
        return ['off', 'Tidak dipakai', $active];
    };

    // ---- Ringkasan ----
    $hero = '<header class="kp-hero"><div class="kp-kicker">Pengaturan AI</div><h1>Penyedia AI</h1>'
        . '<p>Semua penyedia AI dikelola dari satu tempat. Simpan key sekali, pilih primer dan cadangan, lalu simpan untuk menyinkronkan <code>.env</code> ke master, package, dan semua lokasi tur.</p>'
        . '<div class="kp-chips"><span>Primer <b>' . h(kp_label($primary)) . '</b></span>'
        . '<span>Cadangan <b>' . ($fallbacks ? h(implode(' → ', $fallbacks)) : 'tidak ada') . '</b></span>'
        . '<span><b>' . count($all) . '</b> penyedia</span><span><b>' . $totalActive . '</b> key aktif</span></div>'
        . '<div class="kp-hero-actions"><a class="kp-btn" href="#kp-add">+ Tambah penyedia</a>'
        . '<button class="kp-btn kp-btn-primary" type="submit">Simpan &amp; sinkron</button></div></header>';

    // ---- Kartu penyedia ----
    $cards = '';
    foreach ($all as $p) {
        [$cls, $txt, $active] = $status($p);
        $n = count($byProv[$p] ?? []);
        $model = kp_model($p);
        $cards .= '<article class="kp-card kp-' . $cls . '">'
            . '<div class="kp-card-top"><div><h3>' . h(kp_label($p)) . '</h3>' . (in_array($p, KP_BUILTIN, true) ? '' : '<span class="kp-tag">Custom</span>') . '</div>'
            . '<span class="kp-pill kp-pill-' . $cls . '">' . h($txt) . '</span></div>'
            . '<dl class="kp-meta">'
            . '<div><dt>Key</dt><dd>' . ($p === 'ollama' ? 'tidak perlu' : $active . ' aktif dari ' . $n) . '</dd></div>'
            . '<div><dt>Model</dt><dd>' . ($model !== '' ? '<code>' . h($model) . '</code>' : '<span class="kp-muted">belum diisi</span>') . '</dd></div>'
            . '<div><dt>Endpoint</dt><dd>' . ($p === 'ollama' || kp_endpoint($p) !== '' ? h(kp_endpoint($p)) : '<span class="kp-muted">belum diisi</span>') . '</dd></div>'
            . '</dl>'
            . '<div class="kp-card-actions">'
            . '<button type="button" class="kp-btn kp-btn-sm" onclick="kpSetPrimary(\'' . h($p) . '\')">Jadikan primer</button>'
            . '<button type="button" class="kp-btn kp-btn-sm" onclick="kpAddFallback(\'' . h($p) . '\')">+ Cadangan</button>'
            . '<button type="button" class="kp-btn kp-btn-sm kp-btn-ghost" onclick="kpOpen(\'' . h($p) . '\')">Kelola</button>'
            . '</div></article>';
    }

    // ---- Akordeon key & endpoint ----
    $acc = '';
    foreach ($all as $p) {
        $rows = $byProv[$p] ?? [];
        $slug = h($p);
        $ids = array_map(fn($e) => (int)$e['row']['id'], $rows);
        $body = '';
        if ($rows) {
            $body .= '<div class="kp-keys-head"><span>Aktif</span><span>Label</span><span>Prioritas</span><span>Ganti key (kosongkan jika tetap)</span><span>Hapus</span><span></span></div>';
            foreach ($rows as $e) {
                $r = $e['row']; $i = $e['idx']; $kid = (int)$r['id'];
                $body .= '<div class="kp-key-row"><input type="hidden" name="k_id[]" value="' . $kid . '">'
                    . '<label class="kp-check"><input type="checkbox" name="k_' . $i . '_active"' . ((int)$r['is_active'] === 1 ? ' checked' : '') . ' aria-label="Aktif"></label>'
                    . '<input name="k_' . $i . '_label" value="' . h((string)$r['label']) . '" placeholder="Label">'
                    . '<input name="k_' . $i . '_priority" value="' . h((string)$r['priority']) . '" class="kp-prio" inputmode="numeric" aria-label="Prioritas">'
                    . '<input class="key-mon" type="password" name="k_' . $i . '_key" placeholder="•••• tersimpan" autocomplete="new-password">'
                    . '<label class="kp-check kp-danger"><input type="checkbox" name="del_' . $i . '" value="1" aria-label="Hapus"></label>'
                    . '<button type="button" class="kp-btn kp-btn-sm" onclick="testKey(' . $kid . ', \'' . $slug . '\')">▶ Tes</button></div>'
                    . '<div id="key-test-' . $kid . '" class="kp-result" hidden></div>';
            }
            $body .= '<div class="kp-keys-tools"><button type="button" class="kp-btn kp-btn-sm" onclick="testAllKeys([' . implode(',', $ids) . '], \'provider-test-' . $slug . '\')">Tes semua key (' . count($ids) . ')</button>'
                . '<span class="kp-muted">Dites dari server. Key tidak pernah ditampilkan di browser.</span></div>'
                . '<div id="provider-test-' . $slug . '" class="kp-result" hidden></div>';
        } else {
            $body .= '<p class="kp-muted">' . ($p === 'ollama'
                ? 'Ollama lokal tidak memakai API key. Atur URL dan model di bagian "Ollama lokal" di bawah.'
                : 'Belum ada key untuk penyedia ini. Tambahkan di bawah.') . '</p>';
        }
        $body .= '<div class="kp-add-key"><div class="kp-add-title">Tambah key baru</div><div class="kp-add-grid">'
            . '<input name="add_label[]" placeholder="Label (contoh: Key B)" aria-label="Label key baru">'
            . '<input class="key-mon" type="password" name="add_key[]" placeholder="Paste API key" autocomplete="new-password" aria-label="API key baru">'
            . '<input type="hidden" name="add_provider[]" value="' . $slug . '"></div></div>';
        if (!in_array($p, KP_BUILTIN, true)) {
            $env = provider_env_name($p);
            $body .= '<div class="kp-endpoint"><div class="kp-add-title">Endpoint &amp; model</div><div class="kp-add-grid">'
                . '<label class="kp-field"><span>Base URL</span><input name="set_' . h($env) . '_BASE_URL" value="' . h(setting_get($env . '_BASE_URL', '')) . '" placeholder="https://api.namaprovider.com/v1" class="mono" spellcheck="false"></label>'
                . '<label class="kp-field"><span>Model</span><input name="set_' . h($env) . '_MODEL" value="' . h(setting_get($env . '_MODEL', '')) . '" placeholder="nama-model" class="mono" spellcheck="false"></label>'
                . '</div><p class="kp-muted">Env: <code>' . h($env) . '_API_KEY</code>, <code>' . h($env) . '_MODEL</code>, <code>' . h($env) . '_BASE_URL</code>. Endpoint harus OpenAI-compatible (<code>/chat/completions</code>).</p></div>';
        }
        [$cls, $txt, $active] = $status($p);
        $acc .= '<details class="kp-acc" id="kp-p-' . $slug . '"' . ($p === $primary ? ' open' : '') . '>'
            . '<summary><span class="kp-acc-name">' . h(kp_label($p)) . '</span><span class="kp-pill kp-pill-' . $cls . '">' . h($txt) . '</span>'
            . '<span class="kp-muted">' . ($p === 'ollama' ? 'lokal' : $active . ' key aktif') . '</span></summary>'
            . '<div class="kp-acc-body">' . $body . '</div></details>';
    }

    // ---- Tambah penyedia baru ----
    $addCard = '<section class="kp-card kp-add" id="kp-add"><div class="kp-section-head"><h2>Tambah penyedia AI baru</h2>'
        . '<p>Untuk penyedia apa pun yang punya API <b>OpenAI-compatible</b>, misalnya DeepSeek, Mistral, Together, xAI, atau endpoint sendiri. Tes koneksi dulu, lalu simpan.</p></div>'
        . '<div class="kp-add-grid kp-add-grid-3">'
        . '<label class="kp-field"><span>Nama provider</span><input name="new_provider_name" id="np-name" placeholder="contoh: deepseek" class="mono" spellcheck="false" maxlength="30"><small class="kp-muted">Huruf dan angka saja. Dipakai sebagai nama env, mis. DEEPSEEK_API_KEY.</small></label>'
        . '<label class="kp-field"><span>Label tampilan (opsional)</span><input name="new_provider_label" placeholder="contoh: DeepSeek"></label>'
        . '<label class="kp-field"><span>Model</span><input name="new_provider_model" id="np-model" placeholder="contoh: deepseek-chat" class="mono" spellcheck="false"></label>'
        . '<label class="kp-field kp-span-2"><span>Base URL</span><input name="new_provider_base" id="np-base" placeholder="https://api.deepseek.com/v1" class="mono" spellcheck="false"><small class="kp-muted">Alamat endpoint tanpa /chat/completions.</small></label>'
        . '<label class="kp-field"><span>API key</span><input class="key-mon" type="password" name="new_provider_key" id="np-key" placeholder="Paste API key" autocomplete="new-password"></label>'
        . '</div>'
        . '<div class="kp-add-actions"><button type="button" class="kp-btn" onclick="kpTestDraft()">Tes koneksi</button>'
        . '<span class="kp-muted">Tes memakai data di atas dan tidak menyimpan apa pun. Setelah berhasil, klik <b>Simpan &amp; sinkron</b>.</span></div>'
        . '<div id="kp-draft-result" class="kp-result" hidden></div></section>';

    // ---- Routing & model ----
    $providerOpts = [];
    foreach ($all as $p) $providerOpts[$p] = kp_label($p);
    if (!isset($providerOpts[$primary])) $providerOpts[$primary] = $primary;
    $settings = '<div class="kp-settings">'
        . '<section class="kp-card"><h3>Routing</h3>'
        . '<label class="kp-field"><span>Penyedia utama</span><select name="set_AI_PROVIDER" id="set_AI_PROVIDER">' . kp_options($providerOpts, $primary) . '</select><small class="kp-muted">Dipakai untuk setiap jawaban.</small></label>'
        . kp_field('AI_FALLBACK_PROVIDERS', 'Cadangan (berurutan, pisah koma)', 'Dicoba berurutan jika primer gagal. Jika dikosongkan, runtime memakai openrouter,ollama.', 'groq,openrouter')
        . kp_field('AI_REQUEST_TIMEOUT', 'Batas waktu per permintaan (detik)', '', '12')
        . kp_select('AI_REASONING_MODE', 'Mode penalaran', ['fast' => 'fast', 'balanced' => 'balanced', 'deep' => 'deep'])
        . kp_select('AI_DEBUG', 'Mode debug', ['false' => 'false', 'true' => 'true'])
        . '</section>'
        . '<section class="kp-card"><h3>Model Gemini &amp; OpenRouter &amp; Groq</h3>'
        . kp_field('GEMINI_MODEL', 'Model Gemini utama')
        . kp_field('GEMINI_FALLBACK_MODELS', 'Model Gemini cadangan (koma)')
        . kp_field('OPENROUTER_MODEL', 'Model OpenRouter')
        . kp_field('OPENROUTER_BASE_URL', 'Base URL OpenRouter')
        . kp_field('GROQ_MODEL', 'Model Groq')
        . '</section>'
        . '<section class="kp-card"><h3>Ollama lokal</h3>'
        . kp_select('OLLAMA_ENABLED', 'Aktif', ['false' => 'nonaktif', 'true' => 'aktif'])
        . kp_field('OLLAMA_URL', 'URL Ollama', '', 'http://127.0.0.1:11434')
        . kp_field('OLLAMA_MODEL', 'Model Ollama', '', 'qwen2.5:7b')
        . kp_field('OLLAMA_EMBED_MODEL', 'Model embedding', '', 'nomic-embed-text')
        . kp_field('OLLAMA_NUM_PREDICT', 'Batas token jawaban', '', '600')
        . kp_field('OLLAMA_TEMPERATURE', 'Temperature', '', '0.2')
        . '</section>'
        . '<section class="kp-card"><h3>Pencarian web cadangan</h3>'
        . kp_select('AI_WEB_SEARCH', 'Aktif', ['true' => 'aktif', 'false' => 'nonaktif'], 'Dipakai saat pengetahuan tur tidak punya jawaban.')
        . kp_field('AI_WEB_SEARCH_TIMEOUT', 'Batas waktu (detik)', '', '12')
        . '</section></div>';

    $save = '<div class="kp-savebar"><div><b>Simpan untuk menerapkan perubahan</b>'
        . '<span class="kp-muted"> · menulis .env ke master, package, dan semua lokasi ter-deploy</span>'
        . '<div id="kp-note" class="kp-note" hidden></div></div>'
        . '<button class="kp-btn kp-btn-primary" type="submit">Simpan &amp; sinkron semua</button></div>';

    $alert = $error !== '' ? '<div class="kp-alert" role="alert">' . h($error) . '</div>' : '';
    $body = '<style>' . render_keys_css() . '</style>'
        . '<div class="kp">' . $alert
        . '<form method="post" action="?a=saveKeys" id="kpForm" class="kp-form"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">'
        . $hero
        . '<section class="kp-section"><div class="kp-section-head"><h2>Penyedia</h2><p>Atur urutan dengan <b>Jadikan primer</b> atau <b>+ Cadangan</b>. Perubahan aktif setelah disimpan.</p></div><div class="kp-grid">' . $cards . '</div></section>'
        . '<section class="kp-section"><div class="kp-section-head"><h2>Key &amp; endpoint</h2><p>Prioritas 0 adalah key primer tiap penyedia, sisanya cadangan otomatis.</p></div>' . $acc . '</section>'
        . '<section class="kp-section"><div class="kp-section-head"><h2>Tambah penyedia</h2><p>Penyedia baru langsung bisa dipakai setelah disimpan dan dipilih sebagai primer atau cadangan.</p></div>' . $addCard . '</section>'
        . '<section class="kp-section"><div class="kp-section-head"><h2>Routing &amp; model</h2><p>Pengaturan umum yang berlaku untuk semua lokasi tur.</p></div>' . $settings . '</section>'
        . $save
        . '</form></div>'
        . '<script>' . render_keys_js() . '</script>';
    layout('Penyedia AI', $body);
}

function render_keys_css(): string
{
    return <<<'CSS'
.kp{display:flex;flex-direction:column;gap:var(--space-5)}
.kp-form{display:flex;flex-direction:column;gap:var(--space-5)}
.kp-hero{border-radius:22px;padding:28px 30px;background:linear-gradient(135deg,#25221d 0%,#17161a 55%,#121315 100%);border:1px solid rgba(201,169,110,.18);box-shadow:0 18px 50px rgba(0,0,0,.35)}
.kp-kicker{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--ai-accent)}
.kp-hero h1{font-family:var(--font-display);font-size:30px;margin:6px 0;letter-spacing:-.02em}
.kp-hero p{color:var(--text-secondary);max-width:72ch;margin:0 0 14px}
.kp-chips{display:flex;flex-wrap:wrap;gap:8px}
.kp-chips span{font-size:12px;padding:6px 12px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.03);color:var(--text-secondary)}
.kp-chips b{color:var(--text-primary);font-weight:650}
.kp-hero-actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
.kp-section-head h2{font-size:18px;margin:0 0 4px}
.kp-section-head p{color:var(--text-muted);font-size:13px;margin:0 0 14px}
.kp-section{display:flex;flex-direction:column}
.kp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.kp-card{background:linear-gradient(180deg,var(--surface-2),var(--surface-1));border:1px solid var(--border);border-radius:18px;padding:18px 20px;transition:border-color .25s,transform .25s}
.kp-card:hover{border-color:rgba(201,169,110,.28)}
.kp-card.kp-primer{border-color:rgba(201,169,110,.5);box-shadow:0 0 0 1px rgba(201,169,110,.12) inset}
.kp-card.kp-warn{border-color:rgba(245,158,11,.3)}
.kp-card h3{margin:0;font-size:15px;letter-spacing:-.01em}
.kp-card-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.kp-tag{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ai-accent);margin-left:6px}
.kp-pill{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;white-space:nowrap;border:1px solid var(--border);color:var(--text-muted)}
.kp-pill-primer{background:rgba(201,169,110,.16);border-color:rgba(201,169,110,.45);color:var(--ai-accent)}
.kp-pill-cadangan{background:rgba(255,255,255,.05);color:var(--text-primary)}
.kp-pill-warn{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.35);color:var(--warning)}
.kp-meta{margin:14px 0;display:grid;gap:8px;font-size:12.5px}
.kp-meta>div{display:flex;justify-content:space-between;gap:12px;min-width:0}
.kp-meta dt{color:var(--text-muted);flex-shrink:0}
.kp-meta dd{margin:0;text-align:right;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kp-card-actions{display:flex;flex-wrap:wrap;gap:6px}
.kp-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 14px;border-radius:999px;border:1px solid var(--border-strong);background:rgba(255,255,255,.03);color:var(--text-primary);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:transform .2s,border-color .2s,background .2s}
.kp-btn:hover{border-color:var(--ai-accent);background:rgba(201,169,110,.08);transform:translateY(-1px)}
.kp-btn:active{transform:scale(.98)}
.kp-btn-primary{background:linear-gradient(135deg,#e2c28a,#c9a96e);border-color:transparent;color:#1a1409;box-shadow:0 8px 20px rgba(201,169,110,.22)}
.kp-btn-primary:hover{background:linear-gradient(135deg,#ecd29f,#d4b27a);color:#1a1409}
.kp-btn-sm{padding:6px 11px;font-size:12px}
.kp-btn-ghost{background:transparent}
.kp-acc{border:1px solid var(--border);border-radius:16px;background:var(--surface-1);margin-bottom:10px;overflow:hidden}
.kp-acc>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:12px;padding:14px 18px;flex-wrap:wrap}
.kp-acc>summary::-webkit-details-marker{display:none}
.kp-acc>summary::after{content:"›";margin-left:auto;font-size:20px;line-height:1;color:var(--text-muted);transition:transform .2s}
.kp-acc[open]>summary::after{transform:rotate(90deg)}
.kp-acc[open]>summary{border-bottom:1px solid var(--border)}
.kp-acc-name{font-weight:650}
.kp-acc-body{padding:4px 18px 18px}
.kp-keys-head,.kp-key-row{display:grid;grid-template-columns:52px 1.1fr 84px 1.7fr 56px 84px;gap:10px;align-items:center}
.kp-keys-head{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);padding:12px 0 6px}
.kp-key-row{padding:8px 0;border-top:1px solid var(--border)}
.kp-key-row input{margin:0}
.kp-prio{text-align:center}
.kp-check{display:flex;justify-content:center;margin:0}
.kp-check input{width:auto;margin:0}
.kp-keys-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0 4px}
.kp-result{margin:8px 0;background:var(--code-bg);border:1px solid var(--border);border-radius:10px;padding:9px 12px;font-size:12.5px;line-height:1.7}
.kp-result[hidden]{display:none}
.kp-add-key,.kp-endpoint{margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)}
.kp-add-title{font-size:12px;color:var(--text-secondary);font-weight:650;margin-bottom:8px}
.kp-add-grid{display:grid;grid-template-columns:1fr 2fr;gap:10px}
.kp-add-grid-3{grid-template-columns:1fr 1fr 1fr}
.kp-span-2{grid-column:span 2}
.kp-field{display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--text-secondary);margin-bottom:12px;min-width:0}
.kp-field input,.kp-field select{margin:0}
.kp-field small{font-size:11px;line-height:1.5}
.kp-muted{color:var(--text-muted);font-size:12px}
.kp-settings{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
.kp-settings .kp-card h3{margin:0 0 14px}
.kp-settings .kp-card .kp-field:last-child{margin-bottom:0}
.kp-add{border-style:dashed;border-color:rgba(201,169,110,.4)}
.kp-add-actions{display:flex;gap:12px;align-items:center;margin-top:6px;flex-wrap:wrap}
.kp-savebar{position:sticky;bottom:12px;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 18px;border-radius:16px;background:rgba(24,25,28,.94);backdrop-filter:blur(10px);border:1px solid var(--border-strong);box-shadow:0 14px 36px rgba(0,0,0,.4)}
.kp-note{margin-top:6px;font-size:12px;color:var(--ai-accent)}
.kp-note[hidden]{display:none}
.kp-alert{border:1px solid rgba(239,68,68,.4);background:rgba(239,68,68,.08);color:var(--danger);border-radius:14px;padding:12px 16px;font-size:13px}
.kp-info{color:var(--link-code)}
.kp-bad{color:var(--danger)}
.kp-ok{color:var(--link-light)}
@media (max-width:820px){
  .kp-hero{padding:22px 20px}
  .kp-keys-head{display:none}
  .kp-key-row{grid-template-columns:1fr 1fr}
  .kp-add-grid,.kp-add-grid-3{grid-template-columns:1fr}
  .kp-span-2{grid-column:auto}
}
@media (prefers-reduced-motion:reduce){.kp-btn,.kp-card,.kp-acc>summary::after{transition:none!important;transform:none!important}}
CSS;
}

function render_keys_js(): string
{
    return <<<'JS'
function escHtml(s){var d=document.createElement("div");d.textContent=(s==null?"":String(s));return d.innerHTML;}
function kpNote(msg){var n=document.getElementById("kp-note");if(!n)return;n.textContent=msg;n.hidden=false;}
function kpOpen(p){var d=document.getElementById("kp-p-"+p);if(d){d.open=true;d.scrollIntoView({behavior:"smooth",block:"start"});}}
function kpSetPrimary(p){var s=document.getElementById("set_AI_PROVIDER");if(!s)return;s.value=p;kpNote("Primer diubah ke "+p+". Klik “Simpan & sinkron” untuk menerapkan.");}
function kpAddFallback(p){var i=document.getElementById("set_AI_FALLBACK_PROVIDERS");if(!i)return;var list=i.value.split(",").map(function(x){return x.trim();}).filter(Boolean);if(list.indexOf(p)<0)list.push(p);i.value=list.join(",");kpNote("Cadangan diperbarui: "+list.join(" → ")+". Klik “Simpan & sinkron” untuk menerapkan.");}
function renderTestResult(d){
  if(!d) return "<span class=\"kp-bad\">❌ Tidak ada respon dari server</span>";
  var t=(d.time_ms||0)+" ms";
  if(d.ok){
    var rep=d.reply?" — "+escHtml(String(d.reply).slice(0,90)):"";
    return "<span class=\"kp-ok\">✓ OK ("+t+")</span> <span class=\"kp-muted\">HTTP "+d.http+" · model "+escHtml(d.model)+"</span>"+rep;
  }
  var err=d.error||("HTTP "+d.http);
  return "<span class=\"kp-bad\">✗ Gagal ("+t+")</span> <span class=\"kp-bad\">HTTP "+d.http+" — "+escHtml(err)+"</span>";
}
function kpTestDraft(){
  var box=document.getElementById("kp-draft-result");
  box.hidden=false;
  box.innerHTML="<span class=\"kp-info\">⏳ Mengetes koneksi…</span>";
  var fd=new FormData();
  fd.append("provider",document.getElementById("np-name").value);
  fd.append("key",document.getElementById("np-key").value);
  fd.append("base",document.getElementById("np-base").value);
  fd.append("model",document.getElementById("np-model").value);
  fd.append("csrf_token",document.querySelector("[name=csrf_token]").value);
  fetch("?a=testDraft",{method:"POST",body:fd})
    .then(function(r){return r.json();})
    .then(function(d){box.innerHTML=renderTestResult(d);})
    .catch(function(e){box.innerHTML="<span class=\"kp-bad\">❌ "+escHtml(e)+"</span>";});
}
function testKey(keyId, provider){
  var box=document.getElementById("key-test-"+keyId);
  if(!box) return;
  box.hidden=false;
  box.innerHTML="<span class=\"kp-info\">⏳ Tes "+escHtml(provider)+"…</span>";
  var fd=new FormData();
  fd.append("key_id",keyId);
  fd.append("csrf_token",document.querySelector("[name=csrf_token]").value);
  fetch("?a=testKey",{method:"POST",body:fd})
    .then(function(r){return r.json();})
    .then(function(d){box.innerHTML=renderTestResult(d);})
    .catch(function(e){box.innerHTML="<span class=\"kp-bad\">❌ "+escHtml(e)+"</span>";});
}
async function testAllKeys(keyIds, boxId){
  var box=document.getElementById(boxId);
  if(!box) return;
  box.hidden=false;
  if(!keyIds||!keyIds.length){box.innerHTML="<span class=\"kp-muted\">Tidak ada key untuk dites.</span>";return;}
  var lines=[];
  for(var i=0;i<keyIds.length;i++){
    box.innerHTML="<span class=\"kp-info\">⏳ Tes key "+(i+1)+" dari "+keyIds.length+"…</span>";
    var fd=new FormData();
    fd.append("key_id",keyIds[i]);
    fd.append("csrf_token",document.querySelector("[name=csrf_token]").value);
    try{
      var d=await (await fetch("?a=testKey",{method:"POST",body:fd})).json();
      lines.push("• Key "+(i+1)+": "+renderTestResult(d));
    }catch(e){
      lines.push("<span class=\"kp-bad\">• Key "+(i+1)+": "+escHtml(e)+"</span>");
    }
  }
  box.innerHTML=lines.join("<div style=\"margin-top:4px\"></div>");
}
JS;
}
