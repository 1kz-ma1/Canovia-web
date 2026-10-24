"""Synthetic ONLY: verify signed P1/R1..R6/B2 claims against a supplied test key.

This proves detached Ed25519 signatures bind bytes and scope. It does NOT
authenticate the Canovia owner, operator, runtime, CA, real target or issuance
authority. The caller supplies the verification key, which is UNTRUSTED;
the claims require an explicitly synthetic issuer and operator. Neither
successful verification nor a signature grants GO, one-shot consumption,
a socket, SQL or any executable capability.

Not part of public B1 artifact. No live entrypoint imports this module.
"""
import re

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from e858_b_offline import canonical, decode, SOURCE, RELEASE
from .private_binding import Block

STEPS=('P1','R1','R2','R3','R4','R5','R6','B2')
KEYS=frozenset(('kind','step','binding','issued_at','expires_at','issuer','operator'))
BINDING_KEYS=frozenset((
    'source_sha','release_ref','tooling_sha','bundle_sha256','sql_sha256',
    'reference_sha256','binding_sha256','runtime_sha256','scope','engine',
    'attempt_id','target_ca_sha256'))
HEXA40=re.compile(r'[0-9a-f]{40}\Z')
HEXA64=re.compile(r'[0-9a-f]{64}\Z')
HEXA32=re.compile(r'[0-9a-f]{32}\Z')
KIND='E858_B2_SYNTHETIC_SIGNATURE_EVIDENCE_V0'
ISSUER='UNADOPTED_SYNTHETIC_TEST_KEY'
OPERATOR='SYNTHETIC_OPERATOR_NOT_AUTHENTICATED'
WINDOW_MAX=900


def _binding(value):
    if type(value) is not dict or set(value)!=BINDING_KEYS:raise Block()
    if (any(type(value[k]) is not str for k in BINDING_KEYS)
            or not HEXA40.fullmatch(value['source_sha'])
            or not HEXA40.fullmatch(value['tooling_sha'])
            or not all(HEXA64.fullmatch(value[k]) for k in (
                'bundle_sha256','sql_sha256','reference_sha256',
                'binding_sha256','runtime_sha256','target_ca_sha256'))
            or not HEXA32.fullmatch(value['attempt_id'])
            or value['source_sha']!=SOURCE
            or value['release_ref']!=RELEASE
            or value['scope']!='phase1'
            or value['engine'] not in ('8.0','8.4')):
        raise Block()
    # Exact string types and byte equality matter: bool(1) must not match int(1).
    return canonical(value)


def verify_synthetic_claims(records, expected_binding, public_key, now):
    """Pure offline verification. Always returns execution_allowed=False.

    Each step has its own signed canonical byte payload and MUST be present.
    It is intentionally impossible to turn this into actual operator/owner GO:
    all records are synthetic and verification trust root is caller-supplied.
    """
    try:
        if (type(records) not in (list,tuple) or len(records)!=len(STEPS)
                or type(public_key) is not bytes or len(public_key)!=32
                or type(now) is not int):
            raise Block()
        expected=_binding(expected_binding)
        key=Ed25519PublicKey.from_public_bytes(public_key)
        for step,pair in zip(STEPS,records):
            if type(pair) not in (list,tuple) or len(pair)!=2:raise Block()
            raw,signature=pair
            if (type(raw) is not bytes or not 0<len(raw)<=4096
                    or type(signature) is not bytes or len(signature)!=64):
                raise Block()
            packet=decode(raw)  # Strict e858 JSON, no duplicate/depth tricks.
            if type(packet) is not dict or set(packet)!=KEYS or raw!=canonical(packet):
                raise Block()
            if (packet['kind']!=KIND or packet['step']!=step
                    or packet['issuer']!=ISSUER or packet['operator']!=OPERATOR
                    or type(packet['issued_at']) is not int
                    or type(packet['expires_at']) is not int
                    or not packet['issued_at']<=now<packet['expires_at']
                    or not 0<packet['expires_at']-packet['issued_at']<=WINDOW_MAX
                    or _binding(packet['binding'])!=expected):
                raise Block()
            key.verify(signature,raw)
        return {
            'result':'SYNTHETIC_SIGNED_BYTES_CONSISTENT_NOT_AUTHENTICATED',
            'verified_steps':len(STEPS),
            'independent_owner_authority_verified':False,
            'operator_authentication_verified':False,
            'durable_one_shot_verified':False,
            'execution_allowed':False,
            'real_aiven_connected':False,
            'production_go':False,
        }
    except BaseException:
        raise Block() from None


def authorize(*_args,**_kwargs):
    """Explicitly non-adoptable: not even valid synthetic signatures can GO."""
    raise Block()


def __repr__():
    return '<unadopted synthetic signature verifier; no live authority>'
