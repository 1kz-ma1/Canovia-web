"""Bounded fixed-SQL reader, used ONLY by synthetic subprocess tests/Docker.

There is deliberately no operator/live connection builder or credential loader.
"""
import os
import selectors
import signal
import subprocess
import time
from e858_audit.core import Block, CAPS, queries


class Budget:
    def __init__(self, seconds=120, maximum=16*1024*1024):
        self.until=time.monotonic()+seconds
        self.maximum=maximum
        self.used=0


def bounded_query(command, query_id, budget, *, seconds=10, max_bytes=8*1024*1024,
                  row_bytes=256*1024, cell_bytes=128*1024, sql=None, env=None):
    bundle=queries()
    if query_id not in bundle or (sql is not None and sql!=bundle[query_id]):
        raise Block('SQL_NOT_ALLOWLISTED')
    if time.monotonic()>=budget.until:raise Block('QUERY_TIMEOUT')
    # command is constructed by the verified disposable harness, NEVER CLI input.
    process=None
    selector=selectors.DefaultSelector()
    raw=bytearray();line=bytearray();rows=0
    try:
        process=subprocess.Popen(command,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                                 start_new_session=True,env=env)
        process.stdin.write(bundle[query_id]);process.stdin.close()
        for pipe,tag in [(process.stdout,'out'),(process.stderr,'error')]:
            os.set_blocking(pipe.fileno(),False);selector.register(pipe,selectors.EVENT_READ,tag)
        until=min(time.monotonic()+seconds,budget.until)
        while selector.get_map():
            remaining=until-time.monotonic()
            if remaining<=0:raise Block('QUERY_TIMEOUT')
            for key,_ in selector.select(min(remaining,0.05)):
                chunk=os.read(key.fd,4096)
                if not chunk:
                    selector.unregister(key.fileobj);continue
                budget.used+=len(chunk)
                if key.data=='error':raise Block('PRIVATE_CLIENT_FAILURE')
                if budget.used>budget.maximum or len(raw)+len(chunk)>max_bytes:
                    raise Block('QUERY_BYTES_EXCEEDED')
                if not chunk.isascii() or any(c<32 and c not in (9,10) or c==127 for c in chunk):
                    raise Block('PRIVATE_OUTPUT_REJECTED')
                raw.extend(chunk)
                for part in chunk.splitlines(keepends=True):
                    line.extend(part)
                    if len(line)>row_bytes or any(len(c)>cell_bytes for c in line.split(b'\t')):
                        raise Block('QUERY_FIELD_EXCEEDED')
                    if line.endswith(b'\n'):
                        rows+=1
                        if rows>CAPS[query_id]:raise Block('QUERY_ROWS_EXCEEDED')
                        line.clear()
        if process.wait(timeout=max(0.01,until-time.monotonic()))!=0:
            raise Block('PRIVATE_CLIENT_FAILURE')
        if line or not raw:raise Block('PRIVATE_OUTPUT_REJECTED')
        return raw.decode('ascii')
    except Block:
        raise
    except (Exception,KeyboardInterrupt):
        raise Block('PRIVATE_CLIENT_FAILURE') from None
    finally:
        selector.close()
        if process is not None:
            # Also kill a descendant retaining a pipe after the parent exits.
            try:os.killpg(process.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            process.wait()
            for pipe in (process.stdin,process.stdout,process.stderr):
                if pipe is not None and not pipe.closed:pipe.close()
