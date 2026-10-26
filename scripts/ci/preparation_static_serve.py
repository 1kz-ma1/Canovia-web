#!/usr/bin/env python3
"""Loopback only; immutable allowlist, no application/API/file upload routes."""
import argparse
import http.server
import sys
from pathlib import Path
from urllib.parse import urlsplit, unquote
from preparation_static_build import HEADERS,verify_directory

def serve(directory, port):
    files=verify_directory(directory);payload={'/'+n:raw for n,raw in files.items() if n!='_headers'}
    payload['/']=payload['/index.html']
    class Handler(http.server.BaseHTTPRequestHandler):
        def end_headers(self):
            for k,v in HEADERS.items():self.send_header(k,v)
            super().end_headers()
        def send_error(self,code,message=None,explain=None):
            code=405 if code==501 else code
            raw=b'LOCAL_FIXTURE_REQUEST_REJECTED';self.send_response(code)
            self.send_header('Content-Type','text/plain');self.send_header('Content-Length',str(len(raw)));self.end_headers()
            self.close_connection=True
            if self.command!='HEAD':self.wfile.write(raw)
        def do_GET(self):self.respond(False)
        def do_HEAD(self):self.respond(True)
        def do_POST(self):self.send_error(405);self.close_connection=True
        def log_message(self,*args):pass  # Never log URL/query/input.
        def respond(self,head):
            try:u=urlsplit(self.path);path=unquote(u.path)
            except ValueError:self.send_error(404);return
            host=self.headers.get_all('Host',[])
            if len(host)!=1 or host[0] not in (f'127.0.0.1:{port}',f'localhost:{port}'):self.send_error(421);return
            if (self.headers.get('Origin') not in (None,'http://'+host[0]) or
                self.headers.get('Sec-Fetch-Site')=='cross-site' or self.headers.get('Cookie') or self.headers.get('Authorization')):self.send_error(403);return
            if u.scheme or u.netloc or '?' in self.path or '#' in self.path or path!=self.path or path not in payload:self.send_error(404);return
            raw=payload[path];ext=Path(path).suffix
            mime={'.mjs':'text/javascript','.css':'text/css','.png':'image/png','.webp':'image/webp','.html':'text/html'}.get(ext,'text/html')
            self.send_response(200)
            self.send_header('Content-Type',mime+'; charset=utf-8' if mime.startswith('text/') else mime)
            self.send_header('Content-Length',str(len(raw)));self.end_headers()
            if not head:self.wfile.write(raw)
    http.server.ThreadingHTTPServer(('127.0.0.1',port),Handler).serve_forever()

if __name__=='__main__':
    class Parser(argparse.ArgumentParser):
        def error(self,message):raise ValueError('ARGUMENTS_REJECTED')
    try:
        p=Parser();p.add_argument('--directory',required=True);p.add_argument('--port',type=int,default=18798)
        a=p.parse_args();serve(a.directory,a.port)
    except KeyboardInterrupt:
        sys.exit(130)
    except (OSError,ValueError,RecursionError):
        print('PREPARATION_LAB_BLOCK',file=sys.stderr);sys.exit(2)
