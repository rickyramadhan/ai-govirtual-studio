/**
 * ai-client.js — Client-side Gemini API caller (browser-only, no PHP).
 *
 * This is the SAME approach used in the previous working build: the Gemini
 * API key lives in the browser and the request goes straight to Google from
 * the client. It is used as the automatic fallback in guide.js (tryGemini)
 * when the PHP backend (api/chat.php) is unavailable — e.g. on static hosting
 * that cannot execute PHP.
 *
 * NOTE: putting the key in the browser exposes it to visitors. This is only
 * acceptable for free-tier demo deployments. For production, keep the key
 * server-side (api/chat.php) on a PHP host instead.
 */
(function () {
  'use strict';

  var cfg = window.GOVIRTUAL_AI_CONFIG || {};
  var GEMINI_API_KEY = cfg.geminiApiKey || 'YOUR_GEMINI_API_KEY';
  var GEMINI_MODEL = cfg.geminiModel || 'gemini-3.5-flash-lite';
  var GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/' + GEMINI_MODEL + ':generateContent?key=' + GEMINI_API_KEY;

  // Backup model if the primary fails (same idea as the PHP provider fallback).
  var GEMINI_FALLBACK_MODEL = cfg.geminiFallbackModel || 'gemini-3.5-flash';

  function buildUrl(model) {
    return 'https://generativelanguage.googleapis.com/v1beta/models/' + model + ':generateContent?key=' + GEMINI_API_KEY;
  }

  function callModel(model, systemPrompt, userMessage) {
    var fullPrompt = systemPrompt + '\n\nIMPORTANT RULES:\n' +
      '1. Selalu balas dalam format JSON: {"reply":"jawaban Anda di sini","intent":"ENTITY_DESCRIPTION","confidence":0.9}.\n' +
      '2. Gunakan key "reply" (bukan "respons" atau "answer").\n' +
      '3. Jangan diawali dengan "Halo!" atau "Selamat datang" pada pertanyaan lanjutan. Hanya sapa di pesan pertama.\n' +
      '4. Langsung ke jawaban. Tanpa sapaan atau kata pengisi yang tidak perlu.\n' +
      '5. Jawab singkat dan langsung. Gunakan bahasa yang sama dengan pertanyaan (default Indonesia).\n' +
      '6. Jika Anda menyarankan pengunjung untuk meneruskan kunjungan ke bagian/lokasi LAIN (misal "ruang bawah tanah", "lantai dua"), sertakan field tambahan "nextScene" berisi NAMA bagian tersebut (bukan id). Jika tidak menyarankan pindah lokasi, jangan sertakan field nextScene.';
    var payload = {
      systemInstruction: { parts: [{ text: fullPrompt }] },
      contents: [{ role: 'user', parts: [{ text: userMessage }] }],
      generationConfig: { temperature: 0.3, maxOutputTokens: 600, responseMimeType: 'application/json' }
    };
    return fetch(buildUrl(model), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    }).then(function (data) {
      var text = (data.candidates && data.candidates[0] && data.candidates[0].content && data.candidates[0].content.parts && data.candidates[0].content.parts[0] && data.candidates[0].content.parts[0].text) || '';
      if (!text) throw new Error('empty');
      return text;
    });
  }

  window.aiClientRequest = function (systemPrompt, userMessage, callback) {
    callModel(GEMINI_MODEL, systemPrompt, userMessage)
      .catch(function () { return callModel(GEMINI_FALLBACK_MODEL, systemPrompt, userMessage); })
      .then(function (text) { callback(null, text); })
      .catch(function (err) { callback(err && err.message ? err.message : 'Gemini request failed', null); });
  };
})();
