"""Private-target adapter candidate. No live connector, launcher or GO switch.

Generic TLS/identity/secret machinery is exercised only by the separately
owned synthetic fixture backend. B's supervised private records are not an
approval authority; all successful reports retain execution_allowed=False.
"""
import hashlib
import io
import json
import os
from pathlib import Path
import ssl
import sys
import time
import zipfile
from importlib.metadata import version

import pymysql
from pymysql import connections, _auth
from e858_b_offline import canonical, digest, SOURCE, RELEASE
from . import runtime as r, b1_package as pkg, private_distribution as dist
from .b1_bridge import Bridge, ORDER
from .b1_integrity import installed_wheel
from .b1_operator import Entry, Root, native_acl
from .private_binding import Block, Target, read_binding, _read, HEX
from .private_candidate import Candidate

CAPS=dict(engine=1,identity=1,grants=8,ledger=115,table=512,column=4096,index=4096,
          foreign_key=2048,check=512,trigger=128,semantic_version=1,
          semantic_index=4096,semantic_foreign_key=2048,column_extra=4096)


class Program:
    """Actual public code/wheel/runtime comparison, never an owner attestation."""
    def __init__(self,archive,expected_sha,pins):
        try:
            pkg.verify(archive,expected_sha,pins)
            self.pins=dict(pins);self.sha=expected_sha
            with zipfile.ZipFile(io.BytesIO(archive)) as outer:
                self.files={n:outer.read(n) for n in pkg.FILES+pkg.FROZEN+pkg.DERIVED}
                with zipfile.ZipFile(io.BytesIO(outer.read('library.zip'))) as inner:
                    self.files.update({n:inner.read(n) for n in dist.FILES if n not in self.files})
                    self.wheel=inner.read(dist.WHEEL_PATH)
            installed_wheel(self.wheel)
            self.runtime=digest(dist.runtime_components())
            self.queries={k:self.files['scripts/ci/e858_audit/queries/'+k+'.sql'] for k in pkg.KEYS}
            if digest({k:dist._sha(v) for k,v in self.queries.items()})!=pins['sql_sha256']:raise Block()
            self.check()
        except BaseException:raise Block() from None

    def check(self):
        try:
            if (version('PyMySQL')!='1.1.2' or connections.DEBUG or _auth.DEBUG
                    or self.pins['source_sha']!=SOURCE or self.pins['release_ref']!=RELEASE
                    or digest(dist.runtime_components())!=self.runtime):raise Block()
            directory=Path(__file__).resolve().parents[1]
            for name in set(dist.FILES)|set(pkg.FILES):
                path=directory/name[len('scripts/ci/'):]
                if dist._public_file(path,4*1024*1024,allow_empty=True)!=self.files[name]:raise Block()
            # Frozen comparator may be mounted separately, but its loaded bytes
            # cannot be replaced by moving-main implementations or import hooks.
            for module_name,file_name in [('e858_audit.core','e858_audit/core.py'),
                    ('integrated_release20_audit','integrated_release20_audit.py'),
                    ('integrated_release20_diagnose','integrated_release20_diagnose.py'),
                    ('integrated_release20_semantics','integrated_release20_semantics.py'),
                    ('p0_mysql_full_ledger_offline_check','p0_mysql_full_ledger_offline_check.py')]:
                module=sys.modules.get(module_name)
                if module is not None:
                    path=getattr(module,'__file__',None)
                    if not path or dist._public_file(path,4*1024*1024)!=self.files['scripts/ci/'+file_name]:raise Block()
        except BaseException:raise Block() from None

    def config(self):
        return dict(q0=self.queries['identity'],q1=self.queries['grants'],caps=dict(CAPS))

    def inputs(self,engine):
        if engine not in ('8.0','8.4'):raise Block()
        return (json.loads(self.files['assets/facts.json']),
                json.loads(self.files['scripts/ci/e858_audit_reference/'+engine+'.json']),
                json.loads(self.files['assets/contract.json']))

    def __repr__(self):return '<reviewed public program; no admission>'


class PrivateReview:
    """Separate protected owner record and independent expected digest.

    Neither the record nor its hash authenticates the owner or issues B2.
    Actual files are never opened by any shipped entrypoint. Tests create new
    synthetic review roots and values only. No argv/env/credential input API.
    """
    def __init__(self,path,expected_digest,program):
        self.root=None;self.value=None
        try:
            if type(program) is not Program or type(expected_digest) is not str or not HEX.fullmatch(expected_digest):raise Block()
            self.program=program;self.expected_digest=expected_digest;self.root=Root(path)
            self.check()
        except BaseException:
            self.close();raise Block() from None

    def check(self):
        try:
            self.program.check()
            raw=_read(self.root.check(),'review.json',16384,native_acl)
            if dist._sha(raw)!=self.expected_digest:raise Block()
            from e858_b_offline import decode
            value=decode(raw)
            keys={'kind','state','source_sha','release_ref','tooling_sha','bundle_sha256',
                  'sql_sha256','reference_sha256','wheel_sha256','runtime_sha256',
                  'binding_sha256','scope','engine','attempt_id','starts_at','expires_at'}
            if type(value) is not dict or set(value)!=keys:raise Block()
            expected=dict(kind='E858_B1_PRIVATE_REVIEW_V1',state='OWNER_REVIEW_PENDING_NOT_GO',
                source_sha=SOURCE,release_ref=RELEASE,tooling_sha=self.program.pins['tooling_sha'],
                bundle_sha256=self.program.sha,sql_sha256=self.program.pins['sql_sha256'],
                wheel_sha256=dist.WHEEL_SHA,runtime_sha256=self.program.runtime)
            if any(value[k]!=v for k,v in expected.items()):raise Block()
            if (value['scope'] not in ('phase1','metadata') or value['engine'] not in ('8.0','8.4')
                    or value['reference_sha256']!=self.program.pins['reference_sha256'][value['engine']]
                    or type(value['binding_sha256']) is not str or not HEX.fullmatch(value['binding_sha256'])
                    or type(value['attempt_id']) is not str or len(value['attempt_id'])!=32
                    or any(c not in '0123456789abcdef' for c in value['attempt_id'])
                    or type(value['starts_at']) is not int or type(value['expires_at']) is not int
                    or not value['starts_at']<=int(time.time())<value['expires_at']
                    or self.value is not None and value!=self.value):raise Block()
            self.value=value
            return value
        except BaseException:raise Block() from None

    def close(self):
        self.value=None
        if self.root is not None:
            root=self.root;self.root=None;root.close()
    def __repr__(self):return '<private review draft; no approval>'


class ReviewedEntry(Entry):
    """Existing foreground TTY/root with separate private-review provenance."""
    def __init__(self):super().__init__();self.review=None
    def bind_review(self,review):
        try:
            self.check()
            if type(review) is not PrivateReview or self.review is not None:raise Block()
            value=review.check()
            # A self-declared hash in target.json / same private leaf is not an
            # independent expected record. Ancestor/ACL checks apply to both roots.
            if os.path.samestat(os.fstat(self.root.check()),os.fstat(review.root.check())):raise Block()
            self.review=review
            self.target=read_binding(self.root.check(),value['binding_sha256'],value['tooling_sha'],int(time.time()),inspect_acl=native_acl)
            self.binding=(value['binding_sha256'],value['tooling_sha'])
            self.check();return self.target
        except BaseException:self.close();raise Block() from None
    def check(self):
        super().check()
        if self.review is not None:
            value=self.review.check()
            if self.target!=read_binding(self.root.check(),value['binding_sha256'],value['tooling_sha'],int(time.time()),inspect_acl=native_acl):raise Block()
    def close(self):
        okay=True
        if self.review is not None:
            try:self.review.close()
            except BaseException:okay=False
            self.review=None
        try:super().close()
        except BaseException:okay=False
        if not okay:raise Block()


class PrivateWire(r.Wire):
    """Real PyMySQL deferred constructor for any one reviewed DNS target.

    No Docker IP policy is embedded here. The only connect implementation in
    this class is an unconditional BLOCK. Test-only subclasses run on owned
    internal networks, never a token/flag-enabled production implementation.
    """
    def initialize(self,gate,secret,target):
        if (type(target) is not Target or not target.valid(int(time.time()))
                or type(secret) is not bytearray or not 0<len(secret)<=1024):raise Block()
        self.gate=gate;self.target=target;self.password=b'';self.quit_written=False
        self.alias_verified=False;self.full_auth_packet_seen=False
        context=target.tls_context()
        context.hostname_checks_common_name=False  # require SAN; no CN fallback
        if (context.verify_mode!=ssl.CERT_REQUIRED or not context.check_hostname
                or context.minimum_version<ssl.TLSVersion.TLSv1_2):raise Block()
        r.StrictConnection.__init__(self,host=target.host,port=target.port,user=target.principal,
            database=target.database,password=secret,ssl=context,charset='ascii',autocommit=None,
            sql_mode=None,connect_timeout=2,read_timeout=10,write_timeout=10,cursorclass=pymysql.cursors.SSCursor)
        self.alias_verified=self.password is secret
        if not self.alias_verified:raise Block()
    def connect(self,sock=None):raise Block()  # no live activation exists
    def _request_authentication(self):
        try:return super()._request_authentication()
        finally:self.password=b''
    def write_packet(self,payload):
        if self.password and len(payload)==len(self.password)+1 and payload[-1]==0 and memoryview(payload)[:-1]==memoryview(self.password):
            if not isinstance(self._sock,ssl.SSLSocket):raise Block()
            self.full_auth_packet_seen=True
        return super().write_packet(payload)
    def _force_close(self):
        try:return super()._force_close()
        finally:self.password=b''
    def close(self):
        try:
            if hasattr(self,'_sock'):super().close()
        finally:self.password=b'';self.target=None
    def __repr__(self):return '<disabled private wire; no live authority>'


class PrivateBoundary:
    def __init__(self,entry):self.entry=entry;self.secret=None;self.wires=[];self.closed=False
    def factory(self,gate,fault=None):
        try:
            self.entry.check()
            if (self.closed or fault is not None or len(self.wires)>=2
                    or gate.role!=('control','data')[len(self.wires)]):raise Block()
            wire=object.__new__(PrivateWire);self.wires.append(wire)
            wire.initialize(gate,self.secret,self.entry.target)
            return wire
        except BaseException:self.close();raise Block() from None
    def close(self):
        okay=True
        for wire in reversed(self.wires):
            try:wire.close()
            except BaseException:okay=False
            finally:wire.password=b''
        if self.secret is not None:
            for i in range(len(self.secret)):self.secret[i]=0
            self.secret.clear();self.secret=None
        self.closed=True
        return okay


class Adapter(Bridge):
    """Common engine for reviewed targets. Shipped implementation cannot run.

    A future separately reviewed live activation must not be inferred from
    owner reports, this private draft, successful tests or source integration.
    """
    def prepare(self):raise Block()  # reject before private reads or password
    def validate_secret(self,secret):
        if type(secret) is not bytearray or not 0<len(secret)<=1024:raise Block()
    def report(self,success):
        value=super().report(success)
        value.update(production_go=False,live_tooling_adopted=False,b2_owner_go=False)
        return value

    def prepare_reviewed(self):
        # Shared, fully implemented setup. Only the owned synthetic subclass
        # calls this today; there is no real backend/launcher invoking it.
        if type(self.entry) is not ReviewedEntry:raise Block()
        self.entry.check()
        review=self.entry.review
        if review is None or review.check()['scope']!=self.scope:raise Block()
        self.program=review.program
        self.validator=Candidate(self.entry.target,self.program.queries['identity'],self.program.queries['grants'])
        self.inputs=self.program.inputs(review.value['engine'])
        return self.program.config(),self.program.queries if self.scope=='metadata' else {}
