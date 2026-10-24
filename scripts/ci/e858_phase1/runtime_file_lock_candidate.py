"""Read-only B1 runtime file/loaded-origin lock candidate; not a live bootstrap.

Reuses the existing Program, Git checkout/archive verifier and wheel/native
checks. It binds one *already present* public checkout to observed loaded .py
modules. It never stages files, acquires private inputs, issues GO, connects or
starts any process beyond the existing read-only Git integrity check.

Same-UID replacement, mutable Python runtime, OS/dyld trust, operator consent,
and concurrent filesystem TOCTOU remain EXTERNAL security gates. The checks
must not be treated as a live runtime/adoption or a hardware-enforced lock.
"""
import os
from pathlib import Path
import stat
import sys

from e858_b_offline import SHA
from . import b1_integrity, b1_package, private_distribution
from .private_binding import Block, HEX
from .practical import Program


def _identity(path):
    st = os.lstat(path)
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or stat.S_ISLNK(st.st_mode):
        raise Block()
    return (st.st_dev, st.st_ino, st.st_uid, st.st_gid, st.st_mode,
            st.st_nlink, st.st_size, st.st_mtime_ns, st.st_ctime_ns)


def _expected_modules(program):
    """Only package files already included and source checked by Program."""
    expected = {}
    for name in set(b1_package.FILES) | set(private_distribution.FILES):
        if not name.startswith('scripts/ci/') or not name.endswith('.py'):
            continue
        relative = name[len('scripts/ci/'):]
        if relative.endswith('/__init__.py'):
            module = relative[:-len('/__init__.py')].replace('/', '.')
        else:
            module = relative[:-3].replace('/', '.')
        expected[module] = (name, program.files[name])
    return expected


class RuntimeFileLockCandidate:
    """One revocable, read-only observation: NEVER execution authority."""

    def __init__(self, program, root, archive, expected_archive_sha, head):
        self._closed = True
        self._modules = {}
        try:
            if (type(program) is not Program or type(root) is not str
                    or not Path(root).is_absolute() or Path(root).is_symlink()
                    or type(archive) is not bytes or not 0 < len(archive) <= private_distribution.CAP
                    or type(expected_archive_sha) is not str or not HEX.fullmatch(expected_archive_sha)
                    or type(head) is not str or not SHA.fullmatch(head)
                    or program.sha != expected_archive_sha
                    or program.pins['tooling_sha'] != head):
                raise Block()
            self._program = program
            self._root = Path(root)
            self._archive = archive
            self._sha = expected_archive_sha
            self._head = head
            self._root_identity = os.lstat(self._root)
            if not stat.S_ISDIR(self._root_identity.st_mode):
                raise Block()
            self._expected = _expected_modules(program)
            self._source_ids = {name: _identity(self._root / path)
                                for path, _ in self._expected.values()
                                for name in (path,)}
            self._sys_path = tuple(sys.path)
            # Snapshot only modules loaded at this point. Later unreviewed
            # modules and monkey-patched __file__/__spec__ invalidate the lock.
            self._modules = {name: sys.modules[name] for name in self._expected
                             if name in sys.modules}
            self._closed = False
            self.check()
        except BaseException:
            self.close()
            raise Block() from None

    def check(self):
        try:
            if self._closed or tuple(sys.path) != self._sys_path:
                raise Block()
            observed_root = os.lstat(self._root)
            if (not stat.S_ISDIR(observed_root.st_mode)
                    or (observed_root.st_dev, observed_root.st_ino,
                        observed_root.st_uid, observed_root.st_gid,
                        observed_root.st_mode, observed_root.st_ctime_ns) !=
                       (self._root_identity.st_dev, self._root_identity.st_ino,
                        self._root_identity.st_uid, self._root_identity.st_gid,
                        self._root_identity.st_mode, self._root_identity.st_ctime_ns)):
                raise Block()
            # The existing verifier binds the actual file bytes, Git HEAD,
            # frozen B1 sources and review archive. Do NOT fork that logic.
            self._program.check()
            b1_integrity.checkout(self._root, self._head, self._archive, self._sha)
            current = {name: sys.modules[name] for name in self._expected
                       if name in sys.modules}
            if (set(current) != set(self._modules)
                    or any(current[name] is not value for name, value in self._modules.items())):
                raise Block()
            for name, value in current.items():
                relative, expected_bytes = self._expected[name]
                real = self._root / relative
                declared = getattr(value, '__file__', None)
                spec = getattr(value, '__spec__', None)
                origin = getattr(spec, 'origin', None)
                if (type(declared) is not str or type(origin) is not str
                        or os.path.islink(declared) or os.path.islink(origin)
                        or not os.path.samefile(real, declared)
                        or not os.path.samefile(real, origin)
                        or private_distribution._public_file(real, 4*1024*1024, allow_empty=True)
                        != expected_bytes):
                    raise Block()
            if any(_identity(self._root / name) != identity
                   for name, identity in self._source_ids.items()):
                raise Block()
            self._program.check()
            return dict(result='B1_PUBLIC_RUNTIME_FILE_ORIGIN_RECHECKED',
                        tooling_sha=self._head, source_count=len(self._source_ids),
                        loaded_source_modules=len(current),
                        lock_type='READ_ONLY_DRIFT_DETECTION_NOT_OS_ENFORCED',
                        file_placement='OBSERVED_EXISTING_CHECKOUT_NOT_INSTALLED',
                        operator_identity='UNVERIFIED', native_linkage='EXTERNAL_TRUST_REQUIRED',
                        owner_approval_verified=False, live_tooling_adopted=False,
                        execution_allowed=False, real_aiven_connected=False,
                        production_go=False)
        except BaseException:
            self.close()
            raise Block() from None

    def close(self):
        self._closed = True
        self._modules.clear()
        self._archive = None

    def __repr__(self):
        return '<B1 public runtime file drift lock; not live authorization>'
