#!/usr/bin/env python3
"""Loopback-only standalone preview fixture; never serves Laravel/public."""
import http.server
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2] / 'local-preview'
class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs): super().__init__(*args, directory=str(ROOT), **kwargs)
    def translate_path(self, path):
        value = Path(super().translate_path(path)).resolve()
        return str(value) if value.is_relative_to(ROOT) else str(ROOT / 'missing')
    def end_headers(self):
        self.send_header('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; manifest-src 'self'; worker-src 'self'; connect-src " + ("'self'" if self.path == '/sw.js' else "'none'") + "; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Cache-Control', 'no-cache')
        super().end_headers()
if __name__ == '__main__': http.server.ThreadingHTTPServer(('127.0.0.1', 18799), Handler).serve_forever()
