"""Fixed zipapp entry: verify public snapshot only. All live modes are BLOCK."""
import hashlib
import importlib.abc
import importlib.util
import io
import json
import os
import stat
import sys
import zipfile

CAP=8*1024*1024


def unique(pairs):
    value={}
    for key,item in pairs:
        if key in value:raise ValueError()
        value[key]=item
    return value


def digest(raw):return hashlib.sha256(raw).hexdigest()


def deny(event,args):
    if (event.startswith('socket.') or event in ('subprocess.Popen','os.system','os.fork','os.posix_spawn')
            or event.startswith('os.exec')):raise RuntimeError('OFFLINE_ONLY')
    if event=='open':
        name,mode,flags=args
        if isinstance(name,(str,bytes)):
            leaf=os.fsdecode(name).replace('\\','/').rsplit('/',1)[-1]
            if leaf in ('target.json','review.json','ca.pem','.mylogin.cnf','tty'):raise RuntimeError('OFFLINE_ONLY')
        if (mode and any(c in mode for c in 'wax+') or isinstance(flags,int) and flags & (os.O_WRONLY|os.O_RDWR|os.O_CREAT|os.O_TRUNC)):
            raise RuntimeError('READ_ONLY')


class Snapshot(importlib.abc.MetaPathFinder,importlib.abc.Loader):
    """Load verified bytes, never reopen a mutable zip path after validation."""
    def __init__(self,files):self.files=files
    def find_spec(self,fullname,path=None,target=None):
        stem=fullname.replace('.','/')
        for name,package in ((stem+'/__init__.py',True),(stem+'.py',False)):
            if name in self.files:
                spec=importlib.util.spec_from_loader(fullname,self,is_package=package)
                spec.origin='/VERIFIED_PUBLIC_SOURCE/scripts/ci/'+name
                return spec
        if fullname.startswith(('e858_','pymysql','integrated_release20','p0_mysql_','release488_')):
            raise ImportError('CLOSURE_REJECTED')
    def create_module(self,spec):return None
    def exec_module(self,module):
        stem=module.__name__.replace('.','/')
        name=stem+'/__init__.py' if module.__spec__.submodule_search_locations is not None else stem+'.py'
        module.__file__=module.__spec__.origin
        exec(compile(self.files[name],module.__file__,'exec'),module.__dict__)


def verify():
    if (sys.argv[1:]!=['--verify'] or not sys.flags.isolated or not sys.flags.no_site
            or not sys.dont_write_bytecode or sys.version_info<(3,9)):
        raise ValueError()
    if any(k.startswith(('DYLD_',)) or k in ('LD_PRELOAD','LD_LIBRARY_PATH','SSLKEYLOGFILE','OPENSSL_CONF','OPENSSL_MODULES') for k in os.environ):raise ValueError()
    sys.addaudithook(deny)
    fd=os.open(sys.argv[0],os.O_RDONLY|os.O_NONBLOCK|os.O_NOFOLLOW|os.O_CLOEXEC)
    try:
        before=os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or not 0<before.st_size<=CAP:raise ValueError()
        raw=bytearray()
        while len(raw)<=CAP:
            part=os.read(fd,min(65536,CAP+1-len(raw)))
            if not part:break
            raw.extend(part)
        after=os.fstat(fd)
        fields=lambda s:(s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        if len(raw)!=before.st_size or fields(before)!=fields(after):raise ValueError()
    finally:os.close(fd)
    raw=bytes(raw)
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        infos=z.infolist()
        if (not 1<len(infos)<512 or sum(i.file_size for i in infos)>CAP
                or len({i.filename for i in infos})!=len(infos)
                or any(i.compress_type or i.flag_bits or i.external_attr!=(0o100644<<16)
                    or i.filename.startswith('/') or '\\' in i.filename
                    or any(p in ('','.','..') for p in i.filename.split('/')) for i in infos)):raise ValueError()
        files={i.filename:z.read(i) for i in infos}
    meta=json.loads(files.pop('candidate-manifest.json'),object_pairs_hook=unique)
    if (set(meta)!={'kind','profile','pins','review_sha256','wheel_sha256','files','execution_allowed','live_entry','live_tooling_adopted','production_go'}
            or meta['kind']!='E858_PHASE1_EXECUTABLE_OFFLINE_CANDIDATE_V1' or meta['profile']!='OFFLINE_VERIFY_ONLY'
            or meta['files']!={n:digest(v) for n,v in files.items()}
            or meta['execution_allowed'] is not False or meta['live_tooling_adopted'] is not False
            or meta['production_go'] is not False or meta['live_entry']!='NOT_IMPLEMENTED'
            or meta['review_sha256']!=digest(files['review.zip'])
            or meta['wheel_sha256']!='e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9'
            or digest(files['dependency.whl'])!=meta['wheel_sha256']):raise ValueError()
    source={n[len('sources/'):]:v for n,v in files.items() if n.startswith('sources/')}
    sys.meta_path.insert(0,Snapshot(source))
    from e858_phase1 import b1_package as package,private_distribution as library
    package.verify(files['review.zip'],meta['review_sha256'],meta['pins'])
    with zipfile.ZipFile(io.BytesIO(files['review.zip'])) as outer:
        expected={n[len('scripts/ci/'):]:outer.read(n) for n in package.FILES+package.FROZEN if n.endswith('.py')}
        with zipfile.ZipFile(io.BytesIO(outer.read('library.zip'))) as inner:
            expected.update({n[len('scripts/ci/'):]:inner.read(n) for n in library.FILES})
    with zipfile.ZipFile(io.BytesIO(files['dependency.whl'])) as wheel:
        expected.update({n:wheel.read(n) for n in wheel.namelist() if n.startswith('pymysql/') and n.endswith('.py')})
    expected.update({p+'/__init__.py':b'' for p in ('e858_phase1','e858_tls','e858_audit')})
    if source!=expected:raise ValueError()
    from e858_phase1.practical import Adapter,PrivateWire
    from e858_phase1.phase1_driver_candidate import Phase1DriverCandidate
    from e858_phase1.producer_candidate import ProducerBinding
    from e858_phase1.launcher_candidate import LauncherCandidate
    from e858_phase1.opener_candidate import OpenerCandidate
    from e858_phase1.private_binding import Block
    if Phase1DriverCandidate.connect is not PrivateWire.connect:raise ValueError()
    for cls,name in ((Adapter,'prepare'),(PrivateWire,'connect'),(LauncherCandidate,'run'),(OpenerCandidate,'open')):
        try:getattr(object.__new__(cls),name)()
        except Block:pass
        else:raise ValueError()
    return dict(result='OFFLINE_EXECUTABLE_CANDIDATE_VERIFIED',artifact_sha256=digest(raw),
        tooling_sha=meta['pins']['tooling_sha'],source_sha=meta['pins']['source_sha'],
        sql_sha256=meta['pins']['sql_sha256'],reference_sha256=meta['pins']['reference_sha256'],
        source_members=len(source),fixture_credentials_included=False,
        profile='OFFLINE_VERIFY_ONLY',live_entry='NOT_IMPLEMENTED',
        runtime_file_model='SNAPSHOT_VERIFICATION_ONLY_NOT_LIVE',
        owner_approval_verified=False,live_tooling_adopted=False,execution_allowed=False,
        real_aiven_connected=False,production_go=False,status='TECHNICAL_WORK_REMAINING')


try:
    value=verify();code=0
except BaseException:
    value=dict(result='BLOCK',code='OFFLINE_EXECUTABLE_REJECTED',execution_allowed=False,real_aiven_connected=False,production_go=False);code=2
print(json.dumps(value,sort_keys=True,separators=(',',':')))
raise SystemExit(code)
