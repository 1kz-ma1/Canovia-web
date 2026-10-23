"""Portable child supervision for a dormant backend; NO child launch or DNS.

Only owns an already-created direct child and its anonymous pipes. The caller
must create a fixed non-spawning worker BEFORE R1 capture. There is no command,
endpoint, credential reader, socket, execution permit or activation interface.
The owned fixture is the only producer/consumer of DNS requests today.
"""
import math
import os
import selectors
import time

from .private_binding import Block

OUTPUT_CAP=8192
REQUEST_CAP=256
CLEANUP_SECONDS=.25  # reserved INSIDE the existing shared connect budget


def reap(process,until):
    """Actual direct-child wait proof, not kill-request success or remote proof."""
    okay=False
    try:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=max(0,min(CLEANUP_SECONDS,until-time.monotonic())))
        okay=process.poll() is not None
    except BaseException:okay=False
    finally:
        for name in ('stdin','stdout','stderr'):
            try:
                stream=getattr(process,name)
                if stream is not None:stream.close()
            except BaseException:okay=False
    return okay


def exchange(process,request,until):
    """Bounded nonblocking pipes on Darwin/Linux; never communicate() buffers.

    No stdout/stderr/raw exception is rendered. Any stderr, signal, unknown
    exit, excessive output, timeout or interruption is terminal. Child reaping
    is independent of its success. This cannot prove OS/NSS provenance or
    termination of descendants, and is not a remote MySQL termination oracle.
    """
    status='DNS_CHILD_ERROR';result=None;selector=None
    try:
        if (type(until) not in (int,float) or not math.isfinite(until)
                or type(request) is not bytes or not 0<len(request)<=REQUEST_CAP
                or process.poll() is not None
                or any(v is None for v in (process.stdin,process.stdout,process.stderr))):raise Block()
        stop=until-CLEANUP_SECONDS
        if time.monotonic()>=stop:status='DNS_TIMEOUT';raise Block()
        selector=selectors.DefaultSelector();output=bytearray();sent=0
        for stream,events in ((process.stdin,selectors.EVENT_WRITE),(process.stdout,selectors.EVENT_READ),
                              (process.stderr,selectors.EVENT_READ)):
            os.set_blocking(stream.fileno(),False);selector.register(stream,events)
        while selector.get_map() or process.poll() is None:
            remaining=stop-time.monotonic()
            if remaining<=0:status='DNS_TIMEOUT';raise Block()
            for key,_ in selector.select(min(.02,remaining)):
                stream=key.fileobj
                if stream is process.stdin:
                    try:sent+=os.write(stream.fileno(),request[sent:])
                    except BlockingIOError:continue
                    if sent==len(request):selector.unregister(stream);stream.close()
                else:
                    try:data=os.read(stream.fileno(),OUTPUT_CAP+1-len(output) if stream is process.stdout else 1)
                    except BlockingIOError:continue
                    if not data:selector.unregister(stream);stream.close();continue
                    if stream is process.stderr:raise Block()
                    output.extend(data)
                    if len(output)>OUTPUT_CAP:status='DNS_RESPONSE_INVALID';raise Block()
        if process.returncode is None:raise Block()
        if process.returncode<0:status='DNS_CHILD_SIGNAL';raise Block()
        if process.returncode:raise Block()
        if not output:status='DNS_RESPONSE_INVALID';raise Block()
        result=bytes(output)
    except BaseException:result=None
    finally:
        if selector is not None:
            try:selector.close()
            except BaseException:result=None
        try:reaped=reap(process,until)
        except BaseException:reaped=False
    if result is None or not reaped:
        error=Block();error.dns_reaped=reaped
        error.dns_status=status if reaped else 'DNS_CHILD_CLEANUP_UNVERIFIED'
        raise error from None
    return result
