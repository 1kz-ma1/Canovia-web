"""B runtime/package integrity. No approval issuer, network or private reads.

The managed OS/loader and authenticated human remain B's external trust base.
Comparisons detect drift; self-observation never authenticates provenance.
"""
import ctypes
import hashlib
from importlib import metadata
import io
import os
from pathlib import Path
import sys
import zipfile

from e858_b_offline import integrity, git, canonical, digest
from .private_binding import Block
from . import private_distribution as d
from .private_native_runtime import _invoke, _observe_component


def wheel_tree(wheel, package):
    """Exact importable tree; no extra .py, native modules, links or .pyc."""
    try:
        if hashlib.sha256(wheel).hexdigest()!=d.WHEEL_SHA:raise Block()
        with zipfile.ZipFile(io.BytesIO(wheel)) as archive:
            expected={n[len('pymysql/'):]:archive.read(n) for n in archive.namelist() if n.startswith('pymysql/') and not n.endswith('/')}
        root=Path(package)
        if root.is_symlink() or not root.is_dir():raise Block()
        actual={}
        for directory,dirs,files in os.walk(root,followlinks=False):
            if len(actual)>128 or any((Path(directory)/n).is_symlink() for n in dirs):raise Block()
            for name in files:
                path=Path(directory)/name
                actual[path.relative_to(root).as_posix()]=d._public_file(path,131072,allow_empty=True)
        if actual!=expected:raise Block()
        return dict(result='EXACT_PINNED_WHEEL_TREE',source_count=len(actual),
                    tree_sha256=digest({k:d._sha(v) for k,v in actual.items()}),execution_allowed=False)
    except BaseException:raise Block() from None


def installed_wheel(wheel):
    try:
        distribution=metadata.distribution('PyMySQL')
        if distribution.version!='1.1.2':raise Block()
        root=Path(distribution.locate_file('pymysql'))
        result=wheel_tree(wheel,root)
        for name,module in list(sys.modules.items()):
            if name=='pymysql' or name.startswith('pymysql.'):
                relative=name.removeprefix('pymysql').lstrip('.').replace('.','/')
                choices=[root/(relative+'.py'),root/relative/'__init__.py'] if relative else [root/'__init__.py']
                origin=getattr(module,'__file__',None)
                if not origin or not any(p.is_file() and os.path.samefile(p,origin) for p in choices):raise Block()
        return result
    except BaseException:raise Block() from None


def checkout(root,head,archive,archive_sha):
    """Clean Git AND actual library bytes; unknown Git-ignored source rejects."""
    try:
        pins=integrity(root,head)
        from . import b1_package as bp
        bp.verify(archive,archive_sha,pins)
        with zipfile.ZipFile(io.BytesIO(archive)) as outer:
            members={name:outer.read(name) for name in bp.FILES}
            with zipfile.ZipFile(io.BytesIO(outer.read('library.zip'))) as inner:
                members.update({name:inner.read(name) for name in d.FILES})
            for name,expected in members.items():
                observed=d._public_file(Path(root)/name,4*1024*1024,allow_empty=True)
                if observed!=expected or observed!=git(root,'show',head+':'+name):raise Block()
        # Including ignored files prevents cached/extra source from hiding behind git status.
        known=set(git(root,'ls-files','scripts/ci').decode('utf-8').splitlines())
        for subtree in ('e858_phase1','e858_tls'):
            for directory,dirs,files in os.walk(Path(root)/'scripts/ci'/subtree,followlinks=False):
                if any((Path(directory)/x).is_symlink() for x in dirs):raise Block()
                for name in files:
                    path=Path(directory)/name
                    if path.is_symlink() or path.relative_to(root).as_posix() not in known:raise Block()
        return dict(result='CHECKOUT_ARCHIVE_BYTES_MATCH',tooling_sha=head,execution_allowed=False)
    except BaseException:raise Block() from None


def native_graph():
    """Actual dyld images plus transitive otool declarations on THIS Mac.

    System shared-cache entries cannot be file-hashed. They are explicit OS
    trust dependencies, never silently called verified. @rpath resolution is
    recorded as unresolved unless the loaded-image view independently covers
    it; owner review must reject unexplained images. No raw paths are returned.
    """
    try:
        if (sys.platform!='darwin' or any(k.startswith('DYLD_') or k in ('LD_PRELOAD','LD_LIBRARY_PATH') for k in os.environ)):
            raise Block()
        lib=ctypes.CDLL(None)
        count=lib._dyld_image_count;count.restype=ctypes.c_uint32
        image=lib._dyld_get_image_name;image.argtypes=[ctypes.c_uint32];image.restype=ctypes.c_char_p
        start=count()
        if not 1<=start<=512:raise Block()
        paths=[Path(os.fsdecode(image(i))) for i in range(start)]
        if len(set(paths))!=len(paths):raise Block()
        queue=list(dict.fromkeys([Path(sys.executable).resolve()]+paths));seen=set();nodes=[];unresolved=0;system=0
        while queue:
            path=queue.pop(0)
            if path in seen:continue
            seen.add(path)
            if len(seen)>512:raise Block()
            if not path.is_absolute():raise Block()
            if str(path).startswith(('/usr/lib/','/System/Library/')):
                system+=1;continue  # dyld cache is the owner's OS trust boundary
            observation=_observe_component(path)
            code,raw=_invoke('/usr/bin/otool',('-L',str(path)))
            if code:raise Block()
            dependencies=[]
            for line in raw.splitlines()[1:]:
                value=line.strip().split(b' (',1)[0].decode('utf-8')
                if value.startswith('@loader_path/'):
                    target=path.parent/value[len('@loader_path/'):]
                elif value.startswith('@executable_path/'):
                    target=Path(sys.executable).resolve().parent/value[len('@executable_path/'):]
                elif value.startswith('/'):
                    target=Path(value)
                elif value.startswith('@rpath/'):
                    matches=[p for p in paths if p.name==Path(value).name]
                    if len(matches)!=1:raise Block()
                    target=matches[0]
                else:raise Block()
                dependencies.append(target)
            queue.extend(dependencies)
            # Signature identity is separately observed, never auto-trusted.
            nodes.append(dict(observation,signature_identity='OWNER_PROVENANCE_REVIEW_REQUIRED'))
        if count()!=start or [Path(os.fsdecode(image(i))) for i in range(start)]!=paths:raise Block()
        return dict(kind='B1_DARWIN_LOADED_AND_DECLARED_LINK_GRAPH',nodes=nodes,
            node_count=len(nodes),system_os_trust_dependencies=system,unresolved_loader_references=unresolved,
            graph_sha256=digest(nodes),os_provenance='OWNER_MACHINE_UNVERIFIED',
            runtime_complete=False,execution_allowed=False,real_aiven_connected=False)
    except BaseException:raise Block() from None
