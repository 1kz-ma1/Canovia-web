"""UNADOPTED SYNTHETIC ONLY: P-256 DER interoperability and scope integrity.

This is deliberately NOT an owner signer, trusted public-key enrollment,
revocation authority, Mac Secure Enclave implementation, real B2 verifier or
connection permission. The caller controls the public key and challenge.
No live B1 entrypoint imports this module and it is excluded from its archive.
"""
import hashlib
import re

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric import ec, utils
from cryptography.hazmat.primitives.hashes import SHA256
from e858_b_offline import canonical, decode
from .b2_signature_fixture import _binding, STEPS
from .private_binding import Block

KIND = 'E858_B2_P256_UNADOPTED_SYNTHETIC_INTEROP_V0'
ALGORITHM = 'ECDSA_P256_SHA256_DER_V0'
ISSUER = 'UNADOPTED_SYNTHETIC_SOFTWARE_P256_KEY'
OPERATOR = 'SYNTHETIC_OPERATOR_NOT_AUTHENTICATED'
EPOCH = 'SYNTHETIC_UNADOPTED_EPOCH_0'
FIELDS = frozenset((
    'kind', 'algorithm', 'step', 'binding', 'issued_at', 'expires_at',
    'issuer', 'operator', 'key_epoch', 'public_key_sha256', 'challenge_sha256'
))
HEX64 = re.compile(r'[0-9a-f]{64}\Z')
MAX_WINDOW = 900


def verify_synthetic_p256(records, expected_binding, public_key_x963,
                          challenge_sha256, now):
    """Validate eight test-only DER signatures. Always returns NO-GO.

    The supplied key/challenge are TEST DATA, not independently authenticated
    owner authority, an unforgeable challenge, current revocation, or use-once.
    """
    try:
        if (type(records) not in (list, tuple) or len(records) != len(STEPS)
                or type(public_key_x963) is not bytes
                or len(public_key_x963) != 65
                or public_key_x963[0] != 4
                or type(challenge_sha256) is not str
                or not HEX64.fullmatch(challenge_sha256)
                or type(now) is not int):
            raise Block()
        expected_bytes = _binding(expected_binding)
        public = ec.EllipticCurvePublicKey.from_encoded_point(
            ec.SECP256R1(), public_key_x963)
        fingerprint = hashlib.sha256(public_key_x963).hexdigest()
        for step, pair in zip(STEPS, records):
            if type(pair) not in (list, tuple) or len(pair) != 2:
                raise Block()
            raw, signature = pair
            if (type(raw) is not bytes or not 0 < len(raw) <= 4096
                    or type(signature) is not bytes or not 8 <= len(signature) <= 72):
                raise Block()
            packet = decode(raw)
            if (type(packet) is not dict or set(packet) != FIELDS
                    or raw != canonical(packet)
                    or packet['kind'] != KIND or packet['algorithm'] != ALGORITHM
                    or packet['step'] != step or packet['issuer'] != ISSUER
                    or packet['operator'] != OPERATOR
                    or packet['key_epoch'] != EPOCH
                    or packet['public_key_sha256'] != fingerprint
                    or packet['challenge_sha256'] != challenge_sha256
                    or type(packet['issued_at']) is not int
                    or type(packet['expires_at']) is not int
                    or not packet['issued_at'] <= now < packet['expires_at']
                    or not 0 < packet['expires_at'] - packet['issued_at'] <= MAX_WINDOW
                    or _binding(packet['binding']) != expected_bytes):
                raise Block()
            # Accept only canonical DER encoding; raw r||s, trailing bytes,
            # BER variants and cross-algorithm signatures never qualify.
            r, s = utils.decode_dss_signature(signature)
            if utils.encode_dss_signature(r, s) != signature:
                raise Block()
            public.verify(signature, raw, ec.ECDSA(SHA256()))
        return {
            'result': 'SYNTHETIC_P256_DER_BYTES_CONSISTENT_NOT_AUTHENTICATED',
            'verified_steps': len(STEPS),
            'public_key_source': 'CALLER_SUPPLIED_UNTRUSTED_TEST_KEY',
            'owner_identity_verified': False,
            'operator_authentication_verified': False,
            'fresh_owner_consent_verified': False,
            'independent_owner_authority_verified': False,
            'revocation_verified': False,
            'durable_one_shot_verified': False,
            'secure_enclave_key_verified': False,
            'execution_allowed': False,
            'real_aiven_connected': False,
            'production_go': False,
        }
    except BaseException:
        raise Block() from None


def authorize(*_args, **_kwargs):
    """Even perfectly valid synthetic P-256 signatures never grant B2 GO."""
    raise Block()
