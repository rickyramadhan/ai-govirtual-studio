#!/usr/bin/env python3
"""Worker TTS persisten (Edge neural) untuk ai-guide-hub.

Menjaga proses Python tetap hidup agar tidak ada biaya spawn (~0.8s) tiap
request. Hanya bind ke 127.0.0.1. Endpoint: POST /synth  {text,voice,rate,pitch}
Balasan: audio/mpeg.

Jalankan otomatis oleh api/tts.php; bisa juga manual:
    python tts-worker.py
"""
import asyncio
import json
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

try:
    import edge_tts
except Exception as exc:  # pragma: no cover
    sys.stderr.write("edge_tts import failed: %s\n" % exc)
    raise

_LOOP = asyncio.new_event_loop()
asyncio.set_event_loop(_LOOP)


def _synth(text, voice, rate, pitch):
    kwargs = {}
    if rate:
        kwargs["rate"] = rate
    if pitch:
        kwargs["pitch"] = pitch

    async def run():
        communicate = edge_tts.Communicate(text, voice=voice, **kwargs)
        buf = bytearray()
        async for chunk in communicate.stream():
            if chunk.get("type") == "audio" and chunk.get("data"):
                buf += chunk["data"]
        return bytes(buf)

    return _LOOP.run_until_complete(run())


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        return

    def _send(self, code, body, ctype):
        if isinstance(body, str):
            body = body.encode("utf-8", "replace")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/health":
            self._send(200, "ok", "text/plain")
        else:
            self._send(404, "not found", "text/plain")

    def do_POST(self):
        if self.path != "/synth":
            self._send(404, "not found", "text/plain")
            return
        try:
            length = int(self.headers.get("Content-Length", "0") or "0")
            req = json.loads(self.rfile.read(length) or b"{}")
        except Exception:
            self._send(400, "bad request", "text/plain")
            return
        text = str(req.get("text") or "").strip()[:1000]
        if not text:
            self._send(400, "text required", "text/plain")
            return
        voice = str(req.get("voice") or "id-ID-GadisNeural")
        rate = str(req.get("rate") or "")
        pitch = str(req.get("pitch") or "")
        try:
            audio = _synth(text, voice, rate, pitch)
        except Exception as exc:
            self._send(502, "tts failed: %s" % exc, "text/plain")
            return
        if not audio:
            self._send(502, "tts empty", "text/plain")
            return
        self._send(200, audio, "audio/mpeg")


def main():
    port = int(os.environ.get("TTS_WORKER_PORT", "5599") or "5599")
    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    server.daemon_threads = True
    server.serve_forever()


if __name__ == "__main__":
    main()
