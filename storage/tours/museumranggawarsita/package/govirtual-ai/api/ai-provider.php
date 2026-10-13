<?php

declare(strict_types=1);

require_once __DIR__ . '/ai-core.php';

function ai_provider_config(): array
{
    $fallbacks = array_values(array_filter(array_map('trim', explode(',', getenv('AI_FALLBACK_PROVIDERS') ?: 'openrouter,ollama'))));
    return [
        'primary' => strtolower(trim(getenv('AI_PROVIDER') ?: 'groq')),
        'fallbacks' => $fallbacks,
        'request_timeout' => max(8, min(60, (int)(getenv('AI_REQUEST_TIMEOUT') ?: 25))),
        'debug' => filter_var(getenv('AI_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    ];
}

function ai_provider_model(string $provider): string
{
    // Generic lookup: {PROVIDER}_MODEL env var works for ANY provider.
    $envModel = getenv(strtoupper($provider) . '_MODEL') ?: '';
    if ($envModel !== '') return $envModel;
    switch ($provider) {
        case 'groq':
            return getenv('GROQ_MODEL') ?: 'groq/compound';
        case 'gemini':
            return getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite';
        case 'openrouter':
            return getenv('OPENROUTER_MODEL') ?: 'openai/gpt-4o-mini';
        case 'ollama':
            return getenv('OLLAMA_MODEL') ?: 'qwen2.5:7b';
        default:
            // Custom provider — empty means "not configured yet".
            return '';
    }
}

/**
 * Generic per-provider env prefix helper. For a provider named e.g. "mistral",
 * this maps to MISTRAL_ so MISTRAL_API_KEY / MISTRAL_MODEL / MISTRAL_BASE_URL work.
 */
function ai_provider_env_prefix(string $provider): string
{
    return strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $provider));
}

function ai_provider_env(string $provider, string $suffix, string $default = ''): string
{
    $val = getenv(ai_provider_env_prefix($provider) . '_' . $suffix) ?: '';
    return $val !== '' ? $val : $default;
}

/**
 * Keys (primary + backups) for ANY provider, e.g. MISTRAL_API_KEY, MISTRAL_API_KEY_1, ...
 */
function ai_provider_keys(string $provider): array
{
    return ai_env_keys(ai_provider_env_prefix($provider) . '_API_KEY');
}

function ai_gemini_models(): array
{
    $primary = trim((string)(getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite'));
    $fallbacks = array_values(array_filter(array_map('trim', explode(',', (string)(getenv('GEMINI_FALLBACK_MODELS') ?: '')))));
    return array_values(array_unique(array_filter(array_merge([$primary], $fallbacks))));
}

function ai_provider_caps(string $provider): array
{
    switch ($provider) {
        case 'groq':
        case 'openrouter':
        case 'gemini':
        case 'ollama':
            return ['supportsStructuredOutput' => true, 'supportsJsonMode' => true];
        default:
            // Custom providers are assumed OpenAI-compatible (JSON mode supported).
            $providerCaps = ai_provider_env($provider, 'CAPS');
            return $providerCaps !== ''
                ? ['supportsStructuredOutput' => true, 'supportsJsonMode' => $providerCaps === 'json']
                : ['supportsStructuredOutput' => true, 'supportsJsonMode' => true];
    }
}

/**
 * Collect all active API keys for a given env prefix.
 * Returns [primary_key, backup_key_1, backup_key_2, ...] — non-empty only.
 * Example: ai_env_keys('GEMINI_API_KEY') reads GEMINI_API_KEY, GEMINI_API_KEY_1, ...
 */
function ai_env_keys(string $prefix): array {
    $keys = [];
    $primary = getenv($prefix) ?: '';
    if ($primary !== '') $keys[] = $primary;
    for ($i = 1; $i <= 20; $i++) {
        $backup = getenv($prefix . '_' . $i) ?: '';
        if ($backup !== '') $keys[] = $backup;
    }
    return $keys;
}

function ai_provider_state_file(): string
{
    return sys_get_temp_dir() . '/govirtual-ai-provider-state.json';
}

function ai_provider_state(): array
{
    $state = json_decode((string)@file_get_contents(ai_provider_state_file()), true);
    return is_array($state) ? $state : [];
}

function ai_provider_record(string $provider, bool $success, ?string $error = null): void
{
    $state = ai_provider_state();
    $row = (array)($state[$provider] ?? []);
    $failures = $success ? 0 : (int)($row['failures'] ?? 0) + 1;
    $state[$provider] = ['failures' => $failures, 'cooldown_until' => !$success && $failures >= 3 ? time() + 60 : 0, 'last_error' => $success ? null : $error];
    @file_put_contents(ai_provider_state_file(), json_encode($state), LOCK_EX);
}

function ai_provider_in_cooldown(string $provider): bool
{
    return (int)(ai_provider_state()[$provider]['cooldown_until'] ?? 0) > time();
}

function ai_http_post_json(string $url, array $payload, array $headers, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => is_string($body) ? $body : '', 'code' => $code, 'error' => $error ?: null];
}

function ai_is_retryable_provider_error(array $result): bool
{
    $code = (int)($result['http_code'] ?? $result['code'] ?? 0);
    $error = strtolower((string)($result['error'] ?? ''));
    return $code === 429 || $code >= 500 || in_array($error, ['missing_api_key', 'invalid_json', 'ollama_disabled'], true) || str_contains($error, 'timeout') || str_contains($error, 'timed out') || str_contains($error, 'could not resolve host') || str_contains($error, 'failed to connect') || str_contains($error, 'connection');
}

function ai_parse_provider_content(string $provider, array $result): array
{
    $body = $result['body'] ?? '';
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return ['success' => false, 'provider' => $provider, 'model' => '', 'content' => '', 'latency_ms' => 0, 'error' => 'invalid_json', 'raw' => $body, 'http_code' => (int)($result['code'] ?? 0)];
    // HTTP non-2xx = gagal, walau body berisi JSON (mis. error 429/402/401).
    $httpCode = (int)($result['code'] ?? 0);
    if ($httpCode >= 400) {
        $err = $decoded['error'] ?? $decoded['message'] ?? null;
        $msg = is_array($err) ? (string)($err['message'] ?? json_encode($err)) : (string)($err ?? 'http_error');
        return ['success' => false, 'provider' => $provider, 'model' => '', 'content' => '', 'latency_ms' => 0, 'error' => 'http_' . $httpCode . ': ' . mb_substr($msg, 0, 200), 'raw' => $decoded, 'http_code' => $httpCode];
    }

    switch ($provider) {
        case 'groq':
        case 'openrouter':
            return [
                'success' => isset($decoded['choices'][0]['message']['content']),
                'provider' => $provider,
                'model' => (string)($decoded['model'] ?? ''),
                'content' => trim((string)($decoded['choices'][0]['message']['content'] ?? '')),
                'latency_ms' => 0,
                'error' => null,
                'raw' => $decoded,
                'http_code' => (int)($result['code'] ?? 0),
            ];
        case 'gemini':
            return [
                'success' => isset($decoded['candidates'][0]['content']['parts'][0]['text']),
                'provider' => $provider,
                'model' => (string)($decoded['modelVersion'] ?? ''),
                'content' => trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '')),
                'latency_ms' => 0,
                'error' => null,
                'raw' => $decoded,
                'http_code' => (int)($result['code'] ?? 0),
            ];
        case 'ollama':
            return [
                'success' => isset($decoded['message']['content']) || isset($decoded['response']),
                'provider' => $provider,
                'model' => (string)($decoded['model'] ?? ''),
                'content' => trim((string)($decoded['message']['content'] ?? $decoded['response'] ?? '')),
                'latency_ms' => 0,
                'error' => null,
                'raw' => $decoded,
                'http_code' => (int)($result['code'] ?? 0),
            ];
        default:
            // Generic OpenAI-compatible format (works for any custom provider).
            if (isset($decoded['choices'][0]['message']['content'])) {
                return [
                    'success' => true,
                    'provider' => $provider,
                    'model' => (string)($decoded['model'] ?? ''),
                    'content' => trim((string)($decoded['choices'][0]['message']['content'])),
                    'latency_ms' => 0,
                    'error' => null,
                    'raw' => $decoded,
                    'http_code' => (int)($result['code'] ?? 0),
                ];
            }
            // Fallback: just return the raw body as content.
            $text = trim((string)($decoded['response'] ?? $decoded['text'] ?? $decoded['output'] ?? json_encode($decoded)));
            return [
                'success' => $text !== '',
                'provider' => $provider,
                'model' => (string)($decoded['model'] ?? ''),
                'content' => $text,
                'latency_ms' => 0,
                'error' => $text === '' ? 'unknown_provider' : null,
                'raw' => $decoded,
                'http_code' => (int)($result['code'] ?? 0),
            ];
    }
}

function ai_provider_request(string $provider, array $messages, array $options): array
{
    $timeout = (int)($options['timeout'] ?? 25);
    $temperature = (float)($options['temperature'] ?? 0.2);
    $maxTokens = (int)($options['max_tokens'] ?? 600);
    $model = (string)($options['model'] ?? ai_provider_model($provider));
    $jsonMode = (bool)($options['json_mode'] ?? true);
    $started = microtime(true);

    if ($provider === 'ollama') {
        if (!filter_var(getenv('OLLAMA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            return ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'ollama_disabled', 'raw' => null, 'http_code' => 0];
        }
        $url = rtrim(getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434', '/') . '/api/chat';
        $payload = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => $jsonMode ? 'json' : '', 'options' => ['temperature' => $temperature, 'num_predict' => $maxTokens]];
        $result = ai_http_post_json($url, $payload, ['Content-Type: application/json'], $timeout);
        $parsed = ai_parse_provider_content($provider, $result);
        $parsed['latency_ms'] = (int)((microtime(true) - $started) * 1000);
        return $parsed;
    }

    if ($provider === 'gemini') {
        $geminiKeys = ai_env_keys('GEMINI_API_KEY');
        if (!$geminiKeys) return ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'missing_api_key', 'raw' => null, 'http_code' => 0];
        $models = (array)($options['models'] ?? ai_gemini_models());
        $last = null;
        foreach ($geminiKeys as $keyIdx => $key) {
            foreach ($models as $model) {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
                $payload = ['systemInstruction' => ['parts' => [['text' => (string)($options['system'] ?? '')]]], 'contents' => [[ 'role' => 'user', 'parts' => [['text' => (string)($options['user'] ?? '')]] ]], 'generationConfig' => ['temperature' => $temperature, 'responseMimeType' => $jsonMode ? 'application/json' : 'text/plain', 'maxOutputTokens' => $maxTokens]];
                $result = ai_http_post_json($url, $payload, ['Content-Type: application/json'], $timeout);
                $parsed = ai_parse_provider_content($provider, $result);
                $parsed['model'] = $model;
                $parsed['latency_ms'] = (int)((microtime(true) - $started) * 1000);
                $parsed['key_index'] = $keyIdx;
                if ($parsed['success']) return $parsed;
                $last = $parsed;
                // Retryable error (quota/auth/5xx) → try next backup key, else break model loop
                if (($result['code'] ?? 0) === 429 || ($result['code'] ?? 0) >= 500 || ($result['code'] ?? 0) === 400) break;
            }
        }
        return $last ?? ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'gemini_models_unavailable', 'raw' => null, 'http_code' => 0];
    }

    // Generic OpenAI-compatible path for ANY provider (groq, openrouter, and custom ones).
    // Custom providers: {PROVIDER}_BASE_URL / {PROVIDER}_API_KEY / {PROVIDER}_MODEL env vars.
    if ($model === '') return ['success' => false, 'provider' => $provider, 'model' => '', 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'missing_model', 'raw' => null, 'http_code' => 0];
    $base = $provider === 'groq'
        ? (getenv('GROQ_BASE_URL') ?: 'https://api.groq.com/openai/v1')
        : ($provider === 'openrouter'
            ? (getenv('OPENROUTER_BASE_URL') ?: 'https://openrouter.ai/api/v1')
            : (ai_provider_env($provider, 'BASE_URL') ?: 'https://api.' . $provider . '.com/v1'));
    $apiKeys = ai_provider_keys($provider);
    if (!$apiKeys) return ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'missing_api_key', 'raw' => null, 'http_code' => 0];
    $last = null;
    foreach ($apiKeys as $keyIdx => $key) {
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        if ($provider === 'openrouter') $headers[] = 'HTTP-Referer: ' . (getenv('APP_URL') ?: 'https://localhost');
        // OpenCode Zen: endpoint butuh header sesi (dipakai juga oleh free tier).
        if ($provider === 'opencode') $headers[] = 'x-opencode-session: ' . (getenv('OPENCODE_SESSION') ?: 'govirtual-app');
        $payload = ['model' => $model, 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens];
        if ($jsonMode) $payload['response_format'] = ['type' => 'json_object'];
        $result = ai_http_post_json($base . '/chat/completions', $payload, $headers, $timeout);
        $parsed = ai_parse_provider_content($provider, $result);
        $parsed['latency_ms'] = (int)((microtime(true) - $started) * 1000);
        $parsed['key_index'] = $keyIdx;
        if ($parsed['success']) return $parsed;
        $last = $parsed;
        if (($result['code'] ?? 0) === 429 || ($result['code'] ?? 0) >= 500 || ($result['code'] ?? 0) === 401) break;
    }
    return $last;
}

function ai_provider_router(array $messages, array $options = []): array
{
    $config = ai_provider_config();
    $primary = strtolower((string)($options['provider'] ?? $config['primary']));
    $candidates = array_values(array_unique(array_filter(array_merge([$primary], $config['fallbacks'], ['ollama']))));
    $attempts = [];
    $last = null;
    // Anggaran waktu total lintas-provider: bila provider lambat/hang, jangan
    // menunggu timeout penuh — sisa waktu dibagi agar total tetap terkendali.
    $budget = (float)(getenv('AI_PROVIDER_BUDGET_S') ?: 8);
    if ($budget <= 0) $budget = 8;
    $deadline = microtime(true) + $budget;

    foreach ($candidates as $provider) {
        if (ai_provider_in_cooldown($provider)) {
            $attempts[] = ['provider' => $provider, 'success' => false, 'http_code' => 0, 'latency_ms' => 0, 'error' => 'circuit_open'];
            continue;
        }
        $remaining = (int)ceil($deadline - microtime(true));
        if ($remaining < 1) {
            $attempts[] = ['provider' => $provider, 'success' => false, 'http_code' => 0, 'latency_ms' => 0, 'error' => 'budget_exhausted'];
            break;
        }
        $request = $options['request_callback'] ?? null;
        $reqOptions = $options + ['timeout' => min($config['request_timeout'], $remaining)];
        $result = is_callable($request) ? $request($provider, $messages, $reqOptions) : ai_provider_request($provider, $messages, $reqOptions);
        $validStructured = !($options['json_mode'] ?? true) || ai_parse_json((string)($result['content'] ?? '')) !== null;
        if (($result['success'] ?? false) && !$validStructured) { $result['success'] = false; $result['error'] = 'malformed_structured_output'; }
        $attempts[] = ['provider' => $provider, 'success' => (bool)($result['success'] ?? false), 'http_code' => (int)($result['http_code'] ?? 0), 'latency_ms' => (int)($result['latency_ms'] ?? 0), 'error' => $result['error'] ?? null];
        if (($result['success'] ?? false) && trim((string)($result['content'] ?? '')) !== '') {
            ai_provider_record($provider, true);
            return ['success' => true, 'provider' => $provider, 'model' => (string)($result['model'] ?? ''), 'content' => (string)($result['content'] ?? ''), 'latency_ms' => (int)($result['latency_ms'] ?? 0), 'error' => null, 'fallback_used' => $provider !== $primary, 'attempted_providers' => $attempts];
        }
        $last = $result;
        ai_provider_record($provider, false, (string)($result['error'] ?? 'provider_error'));
        // Lanjut ke provider berikutnya apa pun jenis error-nya: penolakan
        // auth/kuota/kredit pada satu provider TIDAK boleh menghentikan rantai
        // cadangan (tujuan: AI tetap menjawab walau free tier habis). Provider
        // yang gagal berulang otomatis masuk cooldown.
    }

    return ['success' => false, 'provider' => $primary, 'model' => ai_provider_model($primary), 'content' => '', 'latency_ms' => (int)($last['latency_ms'] ?? 0), 'error' => $last['error'] ?? 'provider_unavailable', 'fallback_used' => count($attempts) > 1, 'attempted_providers' => $attempts];
}

/**
 * Web search fallback via Gemini Google Search grounding.
 * Dipakai saat knowledge internal tidak punya jawaban. Mengembalikan
 * ['reply'=>..., 'sources'=>[{title,url}], 'provider'=>'gemini-search', 'model'=>...]
 * atau null bila tidak tersedia/gagal.
 */
function ai_gemini_web_search(string $query, string $system, int $timeout = 20): ?array
{
    $query = trim($query);
    if ($query === '') return null;
    if (filter_var(getenv('AI_WEB_SEARCH') ?: 'false', FILTER_VALIDATE_BOOLEAN) === false) return null;
    $keys = ai_env_keys('GEMINI_API_KEY');
    if (!$keys) return null;
    // Model yang mendukung Google Search grounding (ID terbaru; 2.5 sudah pensiun).
    $preferred = ['gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-flash-latest', 'gemini-3.5-flash-lite'];
    $models = array_values(array_unique(array_merge($preferred, ai_gemini_models())));
    foreach ($keys as $key) {
        foreach ($models as $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
            $payload = [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $query]]]],
                'tools' => [['google_search' => new stdClass()]],
                'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 600],
            ];
            $result = ai_http_post_json($url, $payload, ['Content-Type: application/json'], $timeout);
            $decoded = json_decode((string)($result['body'] ?? ''), true);
            $cand = is_array($decoded) ? ($decoded['candidates'][0] ?? null) : null;
            if (is_array($cand)) {
                $text = '';
                foreach ((array)($cand['content']['parts'] ?? []) as $part) {
                    if (isset($part['text'])) $text .= (string)$part['text'];
                }
                $text = trim(preg_replace('/\s+/', ' ', $text));
                if ($text !== '') {
                    $sources = [];
                    $seen = [];
                    foreach ((array)($cand['groundingMetadata']['groundingChunks'] ?? []) as $ch) {
                        $web = is_array($ch) ? ($ch['web'] ?? null) : null;
                        if (!is_array($web)) continue;
                        $uri = trim((string)($web['uri'] ?? ''));
                        if ($uri === '' || isset($seen[$uri])) continue;
                        $seen[$uri] = 1;
                        $sources[] = ['title' => trim((string)($web['title'] ?? $uri)), 'url' => $uri];
                    }
                    return ['reply' => $text, 'sources' => array_slice($sources, 0, 5), 'provider' => 'gemini-search', 'model' => $model];
                }
            }
            $code = (int)($result['code'] ?? 0);
            if ($code === 429) break; // kuota key ini habis → coba key berikutnya
            // 400/404 (model tak mendukung) → coba model berikutnya
        }
    }
    return null;
}

/**
 * Bersihkan jawaban hasil web search agar enak diucapkan (buang markdown link & URL).
 */
function ai_clean_web_reply(string $text): string
{
    $text = (string)preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', '$1', $text);
    $text = (string)preg_replace('#https?://\S+#i', '', $text);
    $text = trim((string)preg_replace('/\s+/', ' ', $text));
    return $text;
}

/**
 * Ambil daftar sumber dari hasil provider web search (annotations OpenRouter
 * atau URL di dalam teks). Return [['title'=>..,'url'=>..], ...].
 */
function ai_web_sources_from_result(array $parsed, string $content = ''): array
{
    $out = [];
    $raw = $parsed['raw'] ?? null;
    $msg = is_array($raw) ? ($raw['choices'][0]['message'] ?? null) : null;
    if (is_array($msg) && !empty($msg['annotations'])) {
        foreach ((array)$msg['annotations'] as $a) {
            $url = trim((string)($a['url_citation']['url'] ?? ($a['url'] ?? '')));
            if ($url === '') continue;
            $title = trim((string)($a['url_citation']['title'] ?? ($a['title'] ?? $url)));
            $out[] = ['title' => $title !== '' ? $title : $url, 'url' => $url];
        }
    }
    if (!$out && $content !== '' && preg_match_all('#https?://[^\s\)\]\}"<>]+#i', $content, $m)) {
        $seen = [];
        foreach ($m[0] as $url) {
            $url = rtrim((string)$url, '.,;:');
            if ($url === '' || isset($seen[$url])) continue;
            $seen[$url] = 1;
            $out[] = ['title' => (string)(parse_url($url, PHP_URL_HOST) ?: $url), 'url' => $url];
        }
    }
    return array_slice($out, 0, 5);
}

/**
 * Web search generik untuk provider OpenAI-compatible:
 *  - groq      : model "groq/compound" (web search bawaan, tanpa parameter tambahan)
 *  - openrouter: plugin web ({id:"web"}) pada model apa pun
 */
function ai_openai_compat_web_search(string $provider, string $query, string $system, int $timeout = 20): ?array
{
    $query = trim($query);
    if ($query === '') return null;
    $keys = ai_provider_keys($provider);
    if (!$keys) return null;
    if ($provider === 'groq') {
        $base = rtrim((string)(getenv('GROQ_BASE_URL') ?: 'https://api.groq.com/openai/v1'), '/');
    } elseif ($provider === 'openrouter') {
        $base = rtrim((string)(getenv('OPENROUTER_BASE_URL') ?: 'https://openrouter.ai/api/v1'), '/');
    } else {
        return null;
    }
    $model = ai_provider_model($provider);
    if ($model === '') return null;
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $query],
    ];
    foreach ($keys as $key) {
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        if ($provider === 'openrouter') $headers[] = 'HTTP-Referer: ' . (getenv('APP_URL') ?: 'https://localhost');
        $payload = ['model' => $model, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => 650];
        if ($provider === 'openrouter') $payload['plugins'] = [['id' => 'web', 'max_results' => 4]];
        $result = ai_http_post_json($base . '/chat/completions', $payload, $headers, $timeout);
        $parsed = ai_parse_provider_content($provider, $result);
        $content = ai_clean_web_reply((string)($parsed['content'] ?? ''));
        if (($parsed['success'] ?? false) && mb_strlen(trim($content)) >= 20) {
            return [
                'reply' => $content,
                'sources' => ai_web_sources_from_result($parsed, $content),
                'provider' => $provider . '-search',
                'model' => (string)($parsed['model'] ?? $model),
            ];
        }
        if ((int)($result['code'] ?? 0) === 429) break; // kuota key ini habis → key berikutnya
    }
    return null;
}

/**
 * Web search dengan rantai cadangan lintas provider. Urutan dapat diatur lewat
 * env AI_WEB_SEARCH_PROVIDERS (default: groq,openrouter,gemini). Bila satu
 * provider gagal/kuota habis, lanjut ke berikutnya — sehingga AI tetap menjawab.
 * Return ['reply'=>..,'sources'=>[..],'provider'=>..,'model'=>..] atau null.
 */
function ai_web_search_backup(string $query, string $system, int $timeout = 20): ?array
{
    $query = trim($query);
    if ($query === '') return null;
    if (filter_var(getenv('AI_WEB_SEARCH') ?: 'false', FILTER_VALIDATE_BOOLEAN) === false) return null;

    $order = array_values(array_filter(array_map('trim', explode(',', strtolower((string)(getenv('AI_WEB_SEARCH_PROVIDERS') ?: 'groq,openrouter,gemini'))))));
    $order = array_values(array_intersect($order, ['groq', 'openrouter', 'gemini']));
    if (!$order) $order = ['groq', 'openrouter', 'gemini'];

    foreach ($order as $provider) {
        if ($provider === 'gemini') {
            $gemini = ai_gemini_web_search($query, $system, $timeout);
            if ($gemini && trim((string)$gemini['reply']) !== '') {
                $gemini['reply'] = ai_clean_web_reply((string)$gemini['reply']);
                return $gemini;
            }
        } else {
            $res = ai_openai_compat_web_search($provider, $query, $system, $timeout);
            if ($res && trim((string)$res['reply']) !== '') return $res;
        }
    }

    return null;
}

function get_ai_provider_health(): array
{
    // Start with the well-known providers so the UI always shows them,
    // then add any extra provider that has an {X}_API_KEY env var configured.
    $known = ['groq', 'gemini', 'openrouter', 'ollama'];
    $providers = $known;
    foreach (array_keys($_ENV) as $envKey) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)_API_KEY(?:_\d+)?$/', (string)$envKey, $m)) {
            $name = strtolower(str_replace('_', '', $m[1]));
            if (!in_array($name, $providers, true)) $providers[] = $name;
        }
    }
    // Also scan actual env (getenv list not available directly; check known patterns too).
    $extra = ['mistral', 'anthropic', 'together', 'cohere', 'deepseek', 'perplexity', 'xai', 'groqcloud', 'fireworks', 'openai', 'opencode'];
    foreach ($extra as $name) {
        if (count(ai_provider_keys($name)) > 0 && !in_array($name, $providers, true)) $providers[] = $name;
    }
    $configured = [];
    foreach ($providers as $provider) {
        $configured[$provider] = $provider === 'ollama'
            ? filter_var(getenv('OLLAMA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)
            : count(ai_provider_keys($provider)) > 0;
    }
    $state = ai_provider_state();
    $health = [];
    foreach ($configured as $provider => $isConfigured) $health[$provider] = ['configured' => $isConfigured, 'cooldown' => (int)($state[$provider]['cooldown_until'] ?? 0) > time()];
    return ['primary' => strtolower(trim(getenv('AI_PROVIDER') ?: 'groq')), 'providers' => $health];
}
