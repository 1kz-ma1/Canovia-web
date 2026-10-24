"""B integration using the real driver, locked to owned synthetic Docker only.

There is deliberately no live backend, endpoint argument, GO switch or CLI.
Phase1 and full metadata are separate scopes. The latter adds exactly 15 SQL
(the remaining fixed ORDER after initial Q0/Q1), never silently authorizes it.
"""
import hashlib
import json
import os
from pathlib import Path
import socket
import ssl
import time

import pymysql

from . import runtime as r
from .protocol import Gate, INIT, CID, CONTROL_ID, MAX_POLLS
from .private_binding import Target, Block
from .private_candidate import Candidate
from .r1_driver import IsolatedAttempt, R1Wire
from .r1_adapter import SYNTHETIC_PASSWORD


from .bridge_core import ORDER,BridgeGate,Channel,Bridge as CoreBridge


class BoundWire(R1Wire):
    """Use the exact FD-read CA bytes, not a second filename-based CA read.

    Inherited DNS/peer/SAN/auth checks still hard-lock the owned fixture.
    There is no implementation that enables a live target.
    """
    def initialize_bound(self,gate,secret,pin,target):
        if (type(secret) is not bytearray or secret!=SYNTHETIC_PASSWORD or type(target) is not Target
                or (target.host,target.port,target.database,target.principal)!=(r.HOST,3306,r.DB,r.USER)
                or hashlib.sha256(target.ca).hexdigest()!=pin['ca_sha256']
                or target.tooling_sha!=pin['tooling_sha']):raise Block()
        self.gate=gate;self.pin=dict(pin);self.quit_written=False
        self.alias_verified=False;self.full_auth_packet_seen=False;self.password=b''
        context=target.tls_context()
        if (context.verify_mode!=ssl.CERT_REQUIRED or not context.check_hostname
                or context.minimum_version<ssl.TLSVersion.TLSv1_2):raise Block()
        r.StrictConnection.__init__(self,host=target.host,port=target.port,user=target.principal,
            database=target.database,password=secret,ssl=context,charset='ascii',
            autocommit=None,sql_mode=None,connect_timeout=2,read_timeout=10,write_timeout=10,
            cursorclass=pymysql.cursors.SSCursor)
        self.alias_verified=self.password is secret
        if not self.alias_verified:raise Block()


class BoundAttempt(IsolatedAttempt):
    def __init__(self,config,secret,target):
        super().__init__(config,secret);self.target=target
    def _factory(self,gate,fault=None):
        if (self.closed or fault is not None or len(self.wires)>=2
                or gate.role!=('control','data')[len(self.wires)]
                or gate.q0!=self.config['q0'] or gate.q1!=self.config['q1']
                or time.monotonic()>=self.until):raise Block()
        pin=self.seal()
        gate.deadline=min(gate.deadline,self.until)
        wire=object.__new__(BoundWire);self.wires.append(wire)
        wire.initialize_bound(gate,self.secret,pin,self.target)
        return wire
    def close(self):
        try:return super().close()
        finally:self.target=None






class Bridge(CoreBridge):
    def prepare(self):
            self.entry.check()
            config=r.load()
            self.boundary=BoundAttempt(config,None,self.entry.target)
            self.boundary.until=self.entry.until
            self.boundary.seal()  # fixed Docker IP/CA, not an Aiven dynamic-IP policy
            target=self.entry.target
            if (target is None or (target.host,target.port,target.database,target.principal)!=(r.HOST,3306,r.DB,r.USER)
                    or hashlib.sha256(target.ca).hexdigest()!=config['r1']['ca_sha256']
                    or target.tooling_sha!=config['pins']['tooling_sha']):raise Block()
            self.validator=Candidate(target,config['q0'],config['q1'])
            bundle={}
            if self.scope=='metadata':
                manifest=json.loads(Path('/synthetic/manifest.json').read_text())
                if tuple(manifest['order'])!=ORDER:raise Block()
                bundle={k:(Path('/synthetic/queries')/(k+'.sql')).read_bytes() for k in config['sql_digests']}
                if any(hashlib.sha256(raw).hexdigest()!=config['sql_digests'][k] for k,raw in bundle.items()):raise Block()
                # Comparator assets verify the fixed Reference, source seal and SQL pins.
                from e858_tls.bridge import assets
                self.inputs=assets()
            return config,bundle

    def validate_secret(self,secret):
            if secret!=SYNTHETIC_PASSWORD:raise Block()
