<?php
/**
 * config.php — Hardcoded fallback configuration.
 * Used when .env is not loaded (shared hosting, restricted env).
 * Values are read from .env first; if not found, these defaults apply.
 */

// Only set if not already in environment
$envPairs = [
    'AI_PROVIDER' => 'gemini',
    'AI_FALLBACK_PROVIDERS' => 'openrouter',
    'AI_REQUEST_TIMEOUT' => '25',
    'AI_DEBUG' => 'false',
    'GEMINI_API_KEY' => 'YOUR_GEMINI_API_KEY',
    'GEMINI_MODEL' => 'gemini-3.5-flash-lite',
    'GEMINI_FALLBACK_MODELS' => 'gemini-3.5-flash',
    'OPENROUTER_API_KEY' => 'YOUR_OPENROUTER_API_KEY',
    'OPENROUTER_MODEL' => 'openai/gpt-4o-mini',
    'OPENROUTER_BASE_URL' => 'https://openrouter.ai/api/v1',
];

foreach ($envPairs as $key => $default) {
    $current = getenv($key);
    if ($current === false || $current === '') {
        putenv("$key=$default");
        $_ENV[$key] = $default;
    }
}
