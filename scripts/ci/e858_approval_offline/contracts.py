"""Pure in-memory SYNTHETIC contracts. No connector, credential/file/socket APIs.

Ephemeral fixture HMACs test tampering and role separation. They are NOT digital
signatures, real identity attestations, or a production signing authority.
"""
from dataclasses import dataclass
import hashlib
import hmac
import json
import re
import secrets
import threading

CANDIDATE = 'e858b9c855970fbe73f37a2d60c9db6525b132d6'
TOOLING = 'be89af9a2cb4b13ef16579d2570f4a5605a711b8'
TRANSPORT = '5e63b7db068121d000c316fa8700f7c5f1c0c3b4'
SQL = '8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1'
REFS = {'8.0': 'ab7a27ff4f95be54fa802d1d801082ac508f1611d32215003a5f1d6c0d9270b6',
        '8.4': 'c23bb40ad94a4c33392e7a237d81cad1d576816065f5bb48f4ee86ea323e86d7'}
ORDER = ('identity','grants','ledger','engine','table','column','index','foreign_key',
         'check','trigger','semantic_version','semantic_index','semantic_foreign_key',
         'column_extra','ledger','identity','grants')
CAPS = dict(engine=1,identity=1,grants=8,ledger=115,table=512,column=4096,index=4096,
            foreign_key=2048,check=512,trigger=128,semantic_version=1,
            semantic_index=4096,semantic_foreign_key=2048,column_extra=4096)
LIMITS = dict(query_seconds=10,stream_seconds=120,section_bytes=8*1024*1024,
              total_bytes=16*1024*1024,row_bytes=256*1024,cell_bytes=128*1024,
              preamble_bytes=16384)
ALGORITHM = 'SYNTHETIC_HMAC_SHA256_TEST_ONLY'
CA = hashlib.sha256(b'SYNTHETIC_CA_ONLY_NOT_A_CERTIFICATE').hexdigest()
REAL_GAPS = ('AUTHORITY_REAL_UNVERIFIED','TLS_REAL_SESSION_UNVERIFIED',
             'ONE_SHOT_REAL_CONSUMPTION_UNVERIFIED','REAL_AIVEN_UNVERIFIED',
             'PRODUCTION_NO_GO_HOLD')


class Block(Exception):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def _unique(pairs):
    out = {}
    for key, value in pairs:
        if key in out:
            raise Block('JSON_DUPLICATE_KEY')
        out[key] = value
    return out


def _ascii(value,depth=0):
    if depth>16:
        raise Block('JSON_DEPTH_REJECTED')
    if isinstance(value, str):
        if any(ord(c) < 32 or ord(c) > 126 for c in value):
            raise Block('JSON_TEXT_REJECTED')
    elif isinstance(value, dict):
        for k,v in value.items():
            if type(k) is not str:
                raise Block('JSON_TYPE_REJECTED')
            _ascii(k,depth+1); _ascii(v,depth+1)
    elif isinstance(value, list):
        for v in value:
            _ascii(v,depth+1)
    elif value is not None and type(value) not in (bool,int):
        raise Block('JSON_TYPE_REJECTED')


def decode(raw):
    if type(raw) is not bytes or len(raw) > 16384 or not raw.isascii():
        raise Block('JSON_INPUT_REJECTED')
    try:
        value = json.loads(raw, object_pairs_hook=_unique,
                           parse_constant=lambda _v: (_ for _ in ()).throw(Block('JSON_TYPE_REJECTED')))
    except Block:
        raise
    except (ValueError,RecursionError):
        raise Block('JSON_INPUT_REJECTED') from None
    _ascii(value)
    return value


def canonical(value):
    _ascii(value)
    try:
        raw = json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=True,allow_nan=False).encode('ascii')
    except (ValueError,TypeError,RecursionError):
        raise Block('JSON_TYPE_REJECTED') from None
    if len(raw) > 16384:
        raise Block('JSON_INPUT_REJECTED')
    return raw


def profile(engine='8.0'):
    if type(engine) is not str or engine not in REFS:
        raise Block('TARGET_CHANGED')
    return dict(kind='SYNTHETIC_ONLY',host='synthetic.invalid',port=13380 if engine=='8.0' else 13384,
                schema='synthetic_ci',engine=engine,server_uuid='00000000-0000-0000-0000-000000000000',
                current_user='synthetic_inspector@synthetic',actor='synthetic_operator',
                ca_sha256=CA,tls_mode='VERIFY_IDENTITY')


def validate_profile(value):
    if type(value) is not dict or value != profile(value.get('engine')):
        raise Block('TARGET_CHANGED')


def payload(head='a'*40, engine='8.0'):
    """Invented fixture only. No operator data or endorsement is loaded."""
    return dict(kind='SYNTHETIC_ONLY',version=1,algorithm=ALGORITHM,operation='METADATA_ONLY_READ',
                candidate_sha=CANDIDATE,tooling_sha=TOOLING,transport_sha=TRANSPORT,verifier_sha=head,
                sql_sha256=SQL,reference_sha256=REFS[engine],target=profile(engine),
                actor='synthetic_operator',owner='synthetic_owner',not_before=1700000000,
                expires_at=1700000120,nonce='a'*32,max_successes=1,
                owner_review='SYNTHETIC_REVIEW_ONLY',operator_review='SYNTHETIC_REVIEW_ONLY',
                query_order=list(ORDER),caps=dict(CAPS),limits=dict(LIMITS))


FIELDS = set(payload())


def validate_payload(value, head):
    if type(value) is not dict or set(value) != FIELDS:
        raise Block('APPROVAL_FIELDS_REJECTED')
    if value['kind'] != 'SYNTHETIC_ONLY':
        raise Block('MISSING_REAL_AUTHORITY')
    validate_profile(value['target'])
    expected = payload(head,value['target']['engine'])
    for key in FIELDS - {'nonce','not_before','expires_at'}:
        # Equality alone would admit bools as integer limits.
        if canonical(value[key]) != canonical(expected[key]):
            raise Block('SCOPE_OR_PIN_CHANGED')
    if (type(value['not_before']) is not int or type(value['expires_at']) is not int
            or not 0 <= value['not_before'] < value['expires_at'] <= 253402300799
            or value['expires_at']-value['not_before'] > 120
            or type(value['nonce']) is not str or not re.fullmatch('[a-f0-9]{32}',value['nonce'])
            or not re.fullmatch('[a-f0-9]{40}',head)):
        raise Block('APPROVAL_WINDOW_OR_NONCE_REJECTED')


def tls_evidence(engine='8.0'):
    return dict(kind='SYNTHETIC_ONLY',mode='VERIFY_IDENTITY',chain_valid=True,
                san='synthetic.invalid',ca_sha256=CA,tls_version='1.3',
                session='synthetic-session-1',q0_session='synthetic-session-1',q1_session='synthetic-session-1',
                q0=profile(engine),grants=['REFERENCES_SCHEMA','SELECT_MIGRATION_LEDGER','USAGE'],
                ledger_before='1'*64,ledger_after='1'*64,ledger_count=94,
                writer_epoch='synthetic-writer-1',strict_result='REVIEW_REQUIRED',
                semantic_names_only=False,trigger_visibility='UNVERIFIED')


def validate_tls(value, target):
    expected = tls_evidence(target['engine'])
    if type(value) is not dict or set(value) != set(expected):
        raise Block('TLS_CONTRACT_REJECTED')
    for key in ('kind','mode','chain_valid','san','ca_sha256','tls_version','session','q0_session','q1_session'):
        if canonical(value[key]) != canonical(expected[key]):
            raise Block('TLS_UNVERIFIED')
    if value['q0'] != target:
        raise Block('SESSION_IDENTITY_CHANGED')
    if value['grants'] not in (expected['grants'],expected['grants'][:2]):
        raise Block('METADATA_PRIVILEGE_BLOCK')
    if value['ledger_before'] != value['ledger_after']:
        raise Block('LEDGER_CHANGED')
    if (value['ledger_before'] != expected['ledger_before'] or type(value['ledger_count']) is not int
            or value['ledger_count'] not in (94,114) or value['writer_epoch'] != expected['writer_epoch']
            or value['trigger_visibility'] != 'UNVERIFIED' or type(value['semantic_names_only']) is not bool):
        raise Block('SNAPSHOT_UNVERIFIED')
    if value['strict_result'] != 'REVIEW_REQUIRED' or value['semantic_names_only']:
        raise Block('STRICT_SCHEMA_BLOCK')


class FakeClock:
    def __init__(self, utc=1700000000, monotonic=0):
        self.utc=utc; self.monotonic=monotonic; self._last=None

    def observe(self):
        if type(self.utc) is not int or type(self.monotonic) is not int or self.monotonic < 0:
            raise Block('CLOCK_UNVERIFIED')
        now = (self.utc,self.monotonic)
        if self._last:
            wall,mono = self.utc-self._last[0],self.monotonic-self._last[1]
            if wall < 0 or mono < 0:
                raise Block('CLOCK_ROLLBACK')
            if wall != mono:
                raise Block('CLOCK_DRIFT')
        self._last=now
        return now

    def advance(self, seconds):
        self.utc+=seconds; self.monotonic+=seconds


class SyntheticAuthority:
    """Ephemeral MAC fixture, NEVER an owner identity or signer distribution model."""
    def __init__(self):
        self._keys={role:secrets.token_bytes(32) for role in ('owner','operator')}
        self.generation=1; self.revoked=set()

    def endorsements(self, raw):
        # Test helper only; no credential/key input or private-key serialization.
        return {role:dict(signer='synthetic_'+role,role=role,generation=self.generation,
                         mac=hmac.new(key,raw,hashlib.sha256).hexdigest()) for role,key in self._keys.items()}

    def verify(self, raw, proofs):
        if type(proofs) is not dict or set(proofs) != {'owner','operator'}:
            raise Block('SYNTHETIC_SIGNER_MISSING')
        for role,key in self._keys.items():
            proof=proofs[role]
            if type(proof) is not dict or set(proof) != {'signer','role','generation','mac'}:
                raise Block('SYNTHETIC_SIGNER_REJECTED')
            if proof['signer']!='synthetic_'+role or proof['role']!=role:
                raise Block('SYNTHETIC_SIGNER_REJECTED')
            if role in self.revoked:
                raise Block('SIGNER_REVOKED')
            if type(proof['generation']) is not int or proof['generation'] != self.generation:
                raise Block('STALE_SIGNER')
            if type(proof['mac']) is not str or not hmac.compare_digest(
                    proof['mac'],hmac.new(key,raw,hashlib.sha256).hexdigest()):
                raise Block('SYNTHETIC_MAC_MISMATCH')

    def rotate(self):
        self.generation+=1
        self._keys={role:secrets.token_bytes(32) for role in self._keys}


@dataclass(frozen=True)
class Review:
    digest: str
    generation: int
    deadline: int
    utc: int
    monotonic: int


def _verify(value, proofs, authority, clock, head, tls):
    validate_payload(value,head); validate_tls(tls,value['target'])
    raw=canonical(value)
    if type(authority) is not SyntheticAuthority or type(clock) is not FakeClock:
        raise Block('MISSING_REAL_AUTHORITY')
    try:
        authority.verify(raw,proofs)
    except Block:
        raise
    except Exception:
        raise Block('VERIFICATION_FAILED') from None
    utc,mono=clock.observe()
    if utc < value['not_before']:
        raise Block('NOT_YET_VALID')
    if utc >= value['expires_at']:
        raise Block('EXPIRED')
    return raw,utc,mono


def review(value, proofs, authority, clock, head, tls):
    raw,utc,mono=_verify(value,proofs,authority,clock,head,tls)
    digest=hashlib.sha256(raw+canonical(tls)).hexdigest()
    return Review(digest,authority.generation,mono+value['expires_at']-utc,utc,mono)


class SyntheticOneShot:
    """One test-owned memory instance. Atomic threads, NOT durable/crash-safe."""
    def __init__(self):
        self._lock=threading.Lock(); self._consumed=set(); self.revoked=set()

    def consume(self, record, value, proofs, authority, clock, head, tls):
        with self._lock:
            raw,utc,mono=_verify(value,proofs,authority,clock,head,tls)
            if (hashlib.sha256(raw+canonical(tls)).hexdigest()!=record.digest
                    or authority.generation!=record.generation):
                raise Block('TARGET_OR_BINDING_CHANGED')
            if utc<record.utc or mono<record.monotonic or utc-record.utc!=mono-record.monotonic:
                raise Block('CLOCK_DRIFT')
            if mono >= record.deadline:
                raise Block('EXPIRED')
            if value['nonce'] in self.revoked:
                raise Block('APPROVAL_REVOKED')
            if value['nonce'] in self._consumed:
                raise Block('REPLAY_REJECTED')
            self._consumed.add(value['nonce'])
            return dict(state='SYNTHETIC_VALIDATED',next_state='READY_FOR_OWNER_REVIEW',
                        real_operator_authenticated=False,authority='MISSING_REAL_AUTHORITY',
                        real_gaps=list(REAL_GAPS),release_authorized=False,real_aiven_connected=False,
                        production_modified=False,strict_block_waived=False)


def real_endorsement(*_args, **_kwargs):
    raise Block('MISSING_REAL_AUTHORITY')


def plan_descriptor():
    return dict(kind='NOT_EXECUTABLE',state='UNREVIEWED',operation='METADATA_ONLY_READ',
                unique_sql_count=14,execution_count=17,sql_sha256=SQL,
                query_order=list(ORDER),caps=dict(CAPS),limits=dict(LIMITS),
                real_gaps=list(REAL_GAPS))


def state_for(code):
    if code in ('EXPIRED','REPLAY_REJECTED'):
        return code
    if code.startswith('TLS'):
        return 'TLS_UNVERIFIED'
    if code in ('TARGET_CHANGED','TARGET_OR_BINDING_CHANGED','SESSION_IDENTITY_CHANGED','SCOPE_OR_PIN_CHANGED'):
        return 'TARGET_CHANGED'
    return 'UNAUTHENTICATED'
