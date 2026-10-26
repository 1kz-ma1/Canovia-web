#!/usr/bin/env python3
"""Loopback only; immutable allowlist, no application/API/file upload routes."""
import argparse
import hashlib
import http.server
import json
from pathlib import Path
from urllib.parse import urlsplit, unquote
from preparation_static_build import HEADERS

def serve(directory, port):
    root=Path(directory).resolve();meta=json.loads((root/'manifest.json').read_text())
    names=set(meta['files']);payload={}
    for name in names:
        p=root/name
        if p.is_symlink() or '..' in Path(name).parts or not p.is_file(): raise ValueError('BAD_BUNDLE')
        raw=p.read_bytes()
        if hashlib.sha256(raw).hexdigest()!=meta['files'][name]:raise ValueError('BAD_BUNDLE')
        if name!='_headers':payload['/'+name]=raw
    payload['/']=payload['/index.html']
    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):self.respond(False)
        def do_HEAD(self):self.respond(True)
        def do_POST(self):self.send_error(405);self.close_connection=True
        def log_message(self,*args):pass  # Never log URL/query/input.
        def respond(self,head):
            u=urlsplit(self.path);path=unquote(u.path)
            if u.query or path not in payload:self.send_error(404);return
            raw=payload[path];ext=Path(path).suffix
            mime={'.mjs':'text/javascript','.css':'text/css','.png':'image/png','.webp':'image/webp','.html':'text/html'}.get(ext,'text/html')
            self.send_response(200)
            for k,v in HEADERS.items():self.send_header(k,v)
            self.send_header('Content-Type',mime+'; charset=utf-8' if mime.startswith('text/') else mime)
            self.send_header('Content-Length',str(len(raw)));self.end_headers()
            if not head:self.wfile.write(raw)
    http.server.ThreadingHTTPServer(('127.0.0.1',port),Handler).serve_forever()

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--directory',required=True);p.add_argument('--port',type=int,default=18798)
    a=p.parse_args();serve(a.directory,a.port)
