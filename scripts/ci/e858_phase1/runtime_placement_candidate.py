"""Public-byte placement / snapshot-loader candidate, NOT a live bootstrap.

Only verified public archive bytes are written beneath a caller-owned synthetic
private anchor. No path, target, credential, authority, process or network input.
Pinned FDs and verified byte snapshots close the source-read/import race. They
cannot defend against a hostile same-UID process, Python hooks or a hostile OS.
The caller must NOT treat staging or a code object as execution authorization.
"""
import importlib.abc
import importlib.util
import io
import os
import stat
import time
import zipfile

from . import b1_package as package, private_distribution as distribution
from .private_binding import Block

BASENAME = 'phase1-public-runtime-candidate'


def _identity(st):
    return (st.st_dev, st.st_ino, st.st_uid, st.st_gid, st.st_mode,
            st.st_nlink, st.st_size, st.st_mtime_ns, st.st_ctime_ns)


def _directory(fd):
    st = os.fstat(fd)
    if (not stat.S_ISDIR(st.st_mode) or st.st_uid != os.geteuid()
            or stat.S_IMODE(st.st_mode) != 0o700 or os.get_inheritable(fd)):
        raise Block()
    return st


class _SnapshotLoader(importlib.abc.Loader):
    """Compile held, verified bytes; never re-open an import pathname."""
    def __init__(self, placement, name, relative, is_package):
        self.placement = placement
        self.name, self.relative, self.package = name, relative, is_package

    def create_module(self, spec):
        return None

    def exec_module(self, module):
        # No authority: this executes public module declarations only. Live
        # methods remain BLOCK. Import environment remains managed-runtime trust.
        try:
            if (module.__spec__.name != self.name or module.__spec__.loader is not self
                    or module.__spec__.origin != self.relative):
                raise Block()
            raw = self.placement.source(self.relative)
            code = compile(raw, self.relative, 'exec', dont_inherit=True)
            self.placement.check()
            exec(code, module.__dict__)
            self.placement.check()
        except BaseException:
            self.placement.close()
            raise Block() from None


class RuntimePlacementCandidate:
    """Own one exclusive public snapshot. Never an installer/launch authority.

    close() invalidates reads/loaders and releases FDs; staged PUBLIC files are
    retained for private-root owner disposal. Never recursively delete a path
    that may have been replaced. A partial/expired root is quarantine, not retry.
    """
    def __init__(self, anchor_fd, archive, archive_sha, pins, until):
        self.closed = True
        self._fds, self._directories, self._files, self._bytes = [], {}, {}, {}
        try:
            if (type(anchor_fd) is not int or anchor_fd <= 2
                    or type(until) is not float or not time.monotonic() < until <= time.monotonic()+120
                    or type(pins) is not dict):
                raise Block()
            _directory(anchor_fd)
            package.verify(archive, archive_sha, pins)
            self.until, self.archive_sha, self.tooling_sha = until, archive_sha, pins['tooling_sha']
            self._anchor = os.dup(anchor_fd)
            os.set_inheritable(self._anchor, False)
            self._fds.append(self._anchor)
            # Atomic no-overwrite mkdir; even an old empty candidate is refused.
            os.mkdir(BASENAME, 0o700, dir_fd=self._anchor)
            self._anchor_id = _identity(os.fstat(self._anchor))[:6]
            flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
            root = os.open(BASENAME, flags, dir_fd=self._anchor)
            self._fds.append(root)
            _directory(root)
            self._directories[''] = root
            with zipfile.ZipFile(io.BytesIO(archive)) as outer:
                files = {n:outer.read(n) for n in package.FILES+package.FROZEN+package.DERIVED}
                with zipfile.ZipFile(io.BytesIO(outer.read('library.zip'))) as inner:
                    files.update({n:inner.read(n) for n in distribution.FILES})
                    wheel = inner.read(distribution.WHEEL_PATH)
            if distribution._sha(wheel) != distribution.WHEEL_SHA:
                raise Block()
            files['dependency.whl'] = wheel
            # Include wheel payload, not site packages or a caller-selected tree.
            with zipfile.ZipFile(io.BytesIO(wheel)) as dependency:
                files.update({'dependency/'+n:dependency.read(n) for n in dependency.namelist() if not n.endswith('/')})
            if len(files)>256 or sum(map(len, files.values()))>distribution.CAP:
                raise Block()
            for name, raw in sorted(files.items()):
                self._write(name, raw)
            for fd in self._directories.values():
                os.fsync(fd)
            self._dir_ids = {n:_identity(os.fstat(fd)) for n,fd in self._directories.items()}
            self.closed = False
            self.check()
        except BaseException:
            self.close()
            raise Block() from None

    def _write(self, name, raw):
        parts = name.split('/')
        if any(not x or x in ('.','..') or '\\' in x or '\x00' in x for x in parts):
            raise Block()
        parent = ''
        for component in parts[:-1]:
            child = '/'.join(filter(None, (parent,component)))
            if child not in self._directories:
                os.mkdir(component, 0o700, dir_fd=self._directories[parent])
                fd = os.open(component, os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,
                             dir_fd=self._directories[parent])
                self._fds.append(fd)
                _directory(fd)
                self._directories[child] = fd
            parent = child
        fd = os.open(parts[-1], os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,
                     0o600, dir_fd=self._directories[parent])
        self._fds.append(fd)
        view = memoryview(raw)
        while view:
            count = os.write(fd, view)
            if count <= 0:raise Block()
            view = view[count:]
        os.fchmod(fd, 0o400)
        os.fsync(fd)
        st = os.fstat(fd)
        if st.st_nlink!=1 or st.st_uid!=os.geteuid() or not stat.S_ISREG(st.st_mode):raise Block()
        # Retain only a read descriptor after publication; no writable handle
        # survives into snapshot import or the lease.
        os.close(fd)
        self._fds.remove(fd)
        fd = os.open(parts[-1], os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC,
                     dir_fd=self._directories[parent])
        self._fds.append(fd)
        if _identity(os.fstat(fd)) != _identity(st):raise Block()
        self._files[name] = (parent,parts[-1],fd,_identity(st))
        self._bytes[name] = raw

    def check(self):
        try:
            if self.closed or time.monotonic()>=self.until:
                raise Block()
            _directory(self._anchor)
            if _identity(os.fstat(self._anchor))[:6]!=self._anchor_id:raise Block()
            root_stat = os.stat(BASENAME, dir_fd=self._anchor, follow_symlinks=False)
            if _identity(root_stat)!=self._dir_ids['']:raise Block()
            for name, fd in self._directories.items():
                if _identity(os.fstat(fd))!=self._dir_ids[name]:raise Block()
                _directory(fd)
                expected = {p[len(name)+1:].split('/')[0] if name else p.split('/')[0]
                            for p in self._files if not name or p.startswith(name+'/')}
                if set(os.listdir(fd))!=expected:raise Block()
                if name:
                    parent,_,leaf=name.rpartition('/')
                    if _identity(os.stat(leaf,dir_fd=self._directories[parent],follow_symlinks=False))!=self._dir_ids[name]:raise Block()
            for name,(parent,leaf,fd,identity) in self._files.items():
                if (_identity(os.stat(leaf,dir_fd=self._directories[parent],follow_symlinks=False))!=identity
                        or _identity(os.fstat(fd))!=identity
                        or os.pread(fd,len(self._bytes[name])+1,0)!=self._bytes[name]
                        or _identity(os.fstat(fd))!=identity):raise Block()
            return dict(result='VERIFIED_PUBLIC_FILES_STAGED_NOT_ACTIVATED',
                        file_count=len(self._files),tooling_sha=self.tooling_sha,
                        execution_allowed=False,real_aiven_connected=False,production_go=False,
                        loaded_runtime='NOT_ADOPTED',same_uid_os_trust='EXTERNAL')
        except BaseException:
            self.close()
            raise Block() from None

    def source(self, relative):
        self.check()
        if relative not in self._bytes or not relative.endswith('.py'):
            self.close();raise Block() from None
        # Return immutable verified snapshot, not a later pathname read.
        return self._bytes[relative]

    def spec(self, name):
        try:
            if type(name) is not str or any(not x.isidentifier() for x in name.split('.')):raise Block()
            choices = ['scripts/ci/'+name.replace('.','/')+suffix for suffix in ('.py','/__init__.py')]
            choices += ['dependency/'+name.replace('.','/')+suffix for suffix in ('.py','/__init__.py')]
            matches=[n for n in choices if n in self._files]
            if len(matches)!=1:raise Block()
            relative=matches[0];self.source(relative)
            loader=_SnapshotLoader(self,name,relative,relative.endswith('/__init__.py'))
            return importlib.util.spec_from_loader(name,loader,origin=relative,is_package=loader.package)
        except BaseException:
            self.close();raise Block() from None

    def close(self):
        self.closed=True
        fds,self._fds=self._fds,[]
        okay=True
        for fd in reversed(fds):
            try:os.close(fd)
            except OSError:okay=False
        self._bytes.clear()
        if not okay:raise Block() from None

    def __repr__(self):return '<public snapshot placement; no live bootstrap authority>'
