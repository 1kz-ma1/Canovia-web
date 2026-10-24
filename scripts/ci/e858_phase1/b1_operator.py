"""B supervised entry library. No CLI, target/credential loader or live permit.

Human presence/identity and a fresh B2 GO are external B decisions. Native ACL
errors never become absence proofs. Only synthetic directories/PTYs are used
by the callers supplied in this change.
"""
import ctypes
import os
import select
from pathlib import PurePath
import stat
import struct
import sys
import termios
import threading
import time

from .private_binding import Block, read_binding
from .private_candidate import WindowedTTY


def identity(st):
    return (st.st_dev, st.st_ino, st.st_uid, st.st_gid, st.st_mode, st.st_ctime_ns)


class AttrList(ctypes.Structure):
    _fields_ = [('count',ctypes.c_ushort),('reserved',ctypes.c_ushort),
                ('common',ctypes.c_uint32),('vol',ctypes.c_uint32),
                ('directory',ctypes.c_uint32),('file',ctypes.c_uint32),('fork',ctypes.c_uint32)]


def decode_security(raw, supported):
    """Darwin attr.h/kauth.h: successful extended-security buffer, not errno.

    A supported-filesystem empty variable attribute is distinct from acl_get_fd
    returning ENOENT. Unknown capability, shape, flags or any ALLOW ACE blocks.
    """
    if supported != 1 or type(supported) is not int or len(raw)<12: raise Block()
    length,offset,size=struct.unpack_from('<IiI',raw)
    if length!=len(raw) or offset<8 or 4+offset+size!=length: raise Block()
    if size==0:
        if length!=12: raise Block()
        return 'SUPPORTED_FS_EMPTY_SECURITY_OBSERVED'
    blob=raw[4+offset:]
    if size<44 or struct.unpack_from('<I',blob)[0]!=0x012cc16d: raise Block()
    count,flags=struct.unpack_from('<II',blob,36)
    if flags or count not in (0,0xffffffff) and not 0<count<=128: raise Block()
    entries=0 if count==0xffffffff else count
    if size!=44+24*entries: raise Block()
    for i in range(entries):
        ace_flags=struct.unpack_from('<I',blob,44+i*24+16)[0]
        if ace_flags & 0xf != 2 or ace_flags & ~0xff: raise Block()  # DENY only
    return 'NATIVE_DENY_ONLY_OR_EMPTY_SECURITY_OBSERVED'


def native_acl(fd):
    before=identity(os.fstat(fd))
    if sys.platform=='darwin':
        if sys.byteorder!='little': raise Block()
        # XNU bsd/sys/unistd.h: _PC_EXTENDED_SECURITY_NP=13.
        # CPython does not expose this Darwin-specific pathconf name.
        lib=ctypes.CDLL(None,use_errno=True)
        capability=lib.fpathconf
        capability.argtypes=[ctypes.c_int,ctypes.c_int];capability.restype=ctypes.c_long
        supported=capability(fd,13)
        request=AttrList(5,0,0x00400000,0,0,0,0)
        lib=ctypes.CDLL(None,use_errno=True)
        call=lib.fgetattrlist
        call.argtypes=[ctypes.c_int,ctypes.POINTER(AttrList),ctypes.c_void_p,ctypes.c_size_t,ctypes.c_ulong]
        call.restype=ctypes.c_int
        buffer=ctypes.create_string_buffer(8192)
        if call(fd,ctypes.byref(request),buffer,len(buffer),0)!=0: raise Block()
        length=struct.unpack_from('<I',buffer.raw)[0]
        if not 12<=length<=len(buffer): raise Block()
        result=decode_security(buffer.raw[:length],supported)
    elif sys.platform=='linux':
        # Synthetic CI backend only; not a management-Mac acceptance.
        if any(n in ('system.posix_acl_access','system.posix_acl_default') for n in os.listxattr(fd)): raise Block()
        result='LINUX_SYNTHETIC_NO_POSIX_ACL_OBSERVED'
    else: raise Block()
    if identity(os.fstat(fd))!=before: raise Block()
    return result


class Root:
    """Own the complete no-follow chain from /, including parent-name links."""
    def __init__(self,path):
        self.fds=[];self.owned=[];self.names=[];self.stats=[];self.acls=[];self.closed=False
        try:
            if (type(path) is not str or not path.startswith('/') or len(path)>4096
                    or len(path.split('/'))>64 or any(p in ('','.','..') for p in path.split('/')[1:])): raise Block()
            flags=os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC|os.O_NOFOLLOW|os.O_NONBLOCK
            self.fds.append(os.open('/',flags));self.owned.append(identity(os.fstat(self.fds[-1]))[:2])
            for part in PurePath(path).parts[1:]:
                self.names.append(part);self.fds.append(os.open(part,flags,dir_fd=self.fds[-1]))
                self.owned.append(identity(os.fstat(self.fds[-1]))[:2])
            if len(self.fds)>64: raise Block()
            for i,fd in enumerate(self.fds):
                st=os.fstat(fd)
                if (not stat.S_ISDIR(st.st_mode) or st.st_uid not in ((os.stat('/').st_uid if sys.platform=='linux' else 0),os.geteuid())
                        or stat.S_IMODE(st.st_mode)&0o022 or os.get_inheritable(fd)): raise Block()
                if i==len(self.fds)-1 and (st.st_uid!=os.geteuid() or stat.S_IMODE(st.st_mode)!=0o700): raise Block()
                self.stats.append(identity(st));self.acls.append(native_acl(fd))
            self.check()
        except BaseException:
            self.close();raise Block() from None

    def check(self):
        try:
            if self.closed or len(self.stats)!=len(self.fds): raise Block()
            for i,fd in enumerate(self.fds):
                if identity(os.fstat(fd))!=self.stats[i] or native_acl(fd)!=self.acls[i] or os.get_inheritable(fd): raise Block()
                if i and identity(os.stat(self.names[i-1],dir_fd=self.fds[i-1],follow_symlinks=False))!=self.stats[i]: raise Block()
            return self.fds[-1]
        except BaseException: raise Block() from None

    def close(self):
        okay=True
        for fd,owned in reversed(list(zip(self.fds,self.owned))):
            try:
                if identity(os.fstat(fd))[:2]!=owned: raise Block()
                os.close(fd)
            except BaseException: okay=False
        self.fds=[];self.closed=True
        if not okay: raise Block()

    def __enter__(self): self.check();return self
    def __exit__(self,*_): self.close()
    def __repr__(self): return '<B private root chain; no authorization>'


class Terminal:
    """Own controlling /dev/tty. SID/foreground are OS facts, not owner auth."""
    def __init__(self):
        self.fd=None
        try:
            if threading.current_thread() is not threading.main_thread(): raise Block()
            self.fd=os.open('/dev/tty',os.O_RDWR|os.O_NOCTTY|os.O_NOFOLLOW|os.O_CLOEXEC)
            self.st=identity(os.fstat(self.fd));self.group=os.getpgrp();self.session=os.getsid(0)
            self.mode=termios.tcgetattr(self.fd)
            self.check()
        except BaseException:
            self.close();raise Block() from None

    def check(self):
        try:
            lib=ctypes.CDLL(None,use_errno=True)
            sid=lib.tcgetsid;sid.argtypes=[ctypes.c_int];sid.restype=ctypes.c_int
            if (self.fd is None or self.fd<=2 or not os.isatty(self.fd) or os.get_inheritable(self.fd)
                    or identity(os.fstat(self.fd))!=self.st or not stat.S_ISCHR(os.fstat(self.fd).st_mode)
                    or os.getpgrp()!=self.group or os.getsid(0)!=self.session or sid(self.fd)!=self.session
                    or os.tcgetpgrp(self.fd)!=self.group or termios.tcgetattr(self.fd)!=self.mode): raise Block()
            return self.fd
        except BaseException: raise Block() from None

    def write_private(self, raw, until):
        """Bounded output on this owned TTY; never stdout or an arbitrary FD.

        Darwin devfs updates character-device ctime on write. Advance that
        observation only after our own successful write, with device/inode/
        owner/group/mode unchanged; every other Terminal check stays strict.
        This does not detect a hostile same-UID/OS ABA during the write.
        """
        fd=None;blocking=None
        try:
            if (threading.current_thread() is not threading.main_thread()
                    or type(raw) is not bytes or not 0<len(raw)<=8192):raise Block()
            fd=self.check();blocking=os.get_blocking(fd);os.set_blocking(fd,False)
            sent=0
            while sent<len(raw):
                self.check();remaining=until-time.monotonic()
                if remaining<=0:raise Block()
                if not select.select([], [fd], [], min(.1,remaining))[1]:continue
                try:count=os.write(fd,raw[sent:])
                except BlockingIOError:continue
                if count<=0:raise Block()
                observed=identity(os.fstat(fd))
                if observed[:5]!=self.st[:5]:raise Block()
                self.st=observed
                self.check();sent+=count
        except BaseException:raise Block() from None
        finally:
            if fd is not None and blocking is not None:
                try:os.set_blocking(fd,blocking)
                except BaseException:raise Block() from None

    def close(self):
        if self.fd is not None:
            fd=self.fd;self.fd=None
            try:
                if hasattr(self,'st') and identity(os.fstat(fd))[:2]!=self.st[:2]: raise Block()
                os.close(fd)
            except BaseException: raise Block() from None

    def __repr__(self): return '<B controlling terminal; not identity proof>'


class Entry:
    """B1 supervised boundary. No B2 issuer, durable store, socket or SQL.

    begin() is an explicit human-supervised invocation, not authentication.
    A failed/interrupted instance is terminal; fresh instances/reboot still
    require procedural fresh owner approval under B.
    """
    def __init__(self):
        self.started=False;self.closed=False;self.root=None;self.terminal=None;self.secret=None
        self.until=None;self.target=None;self.binding=None

    def begin(self,path,*,human_supervised=False):
        try:
            if self.started or self.closed or human_supervised is not True: raise Block()
            self.started=True;self.until=time.monotonic()+120
            self.root=Root(path);self.terminal=Terminal();self.check()
            return self
        except BaseException:
            self.close();raise Block() from None

    def check(self):
        if self.closed or not self.started or time.monotonic()>=self.until: raise Block()
        self.root.check();self.terminal.check()
        if self.binding is not None:
            if read_binding(self.root.check(),*self.binding,int(time.time()))!=self.target: raise Block()

    def bind(self,record_hash,tooling_sha):
        try:
            self.check()
            if self.binding is not None: raise Block()
            self.target=read_binding(self.root.check(),record_hash,tooling_sha,int(time.time()))
            self.binding=(record_hash,tooling_sha)
            return self.target
        except BaseException:
            self.close();raise Block() from None

    def capture(self):
        try:
            self.check()
            if self.secret is not None: raise Block()
            self.secret=WindowedTTY(self.until)
            if self.secret.capture(self.terminal.fd)!='R1_CAPTURED_OFFLINE': raise Block()
            self.check()
            return self.secret  # Private mutable buffer owned until close; no print/receipt.
        except BaseException:
            self.close();raise Block() from None

    def close(self):
        self.closed=True;okay=True
        if self.secret is not None:
            self.secret.discard();self.secret=None
        for obj in (self.terminal,self.root):
            if obj is not None:
                try: obj.close()
                except BaseException: okay=False
        self.terminal=self.root=None;self.target=self.binding=None
        if not okay: raise Block()

    def __enter__(self): return self
    def __exit__(self,*_): self.close()
    def __repr__(self): return '<B supervised entry; execution blocked pending B2>'
