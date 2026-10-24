"""P-256 SOFTWARE-only interoperability research; no real owner or B2 GO."""
import copy
import hashlib
from pathlib import Path
import socket
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from e858_b_offline import SOURCE, RELEASE, canonical
from e858_phase1 import b1_package
from e858_phase1 import b2_p256_synthetic_interop as p256
from e858_phase1 import b2_signature_fixture as ed25519
from e858_phase1 import launcher_candidate, opener_candidate, practical
from e858_phase1.private_binding import Block


class SyntheticP256Interop(unittest.TestCase):
    def setUp(self):
        self.key = ec.generate_private_key(ec.SECP256R1())  # ephemeral SOFTWARE key
        self.pub = self.public(self.key)
        self.now = 1800000000  # synthetic, not authoritative
        self.challenge = 'd'*64  # synthetic marker, not a fresh trusted challenge
        self.binding = dict(
            source_sha=SOURCE, release_ref=RELEASE, tooling_sha='a'*40,
            bundle_sha256='b'*64, sql_sha256='c'*64,
            reference_sha256='d'*64, binding_sha256='e'*64,
            runtime_sha256='f'*64, target_ca_sha256='1'*64,
            scope='phase1', engine='8.4', attempt_id='2'*32)

    @staticmethod
    def public(key):
        return key.public_key().public_bytes(
            serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)

    def sign(self, changes=None, signer=None, steps=p256.STEPS, binding=None):
        signer = signer or self.key
        records = []
        for step in steps:
            packet = dict(
                kind=p256.KIND, algorithm=p256.ALGORITHM,
                step=step, binding=copy.deepcopy(binding or self.binding),
                issued_at=self.now-60, expires_at=self.now+60,
                issuer=p256.ISSUER, operator=p256.OPERATOR,
                key_epoch=p256.EPOCH,
                public_key_sha256=hashlib.sha256(self.public(signer)).hexdigest(),
                challenge_sha256=self.challenge)
            if changes: packet.update(changes)
            raw = canonical(packet)
            records.append((raw, signer.sign(raw, ec.ECDSA(hashes.SHA256()))))
        return records

    def verify(self, records, **args):
        return p256.verify_synthetic_p256(
            records, args.get('binding', self.binding),
            args.get('pub', self.pub),
            args.get('challenge', self.challenge),
            args.get('now', self.now))

    def test_valid_eight_software_signatures_are_never_b2_go(self):
        result = self.verify(self.sign())
        self.assertEqual(result['verified_steps'], 8)
        self.assertEqual(result['public_key_source'], 'CALLER_SUPPLIED_UNTRUSTED_TEST_KEY')
        for field in ('owner_identity_verified', 'operator_authentication_verified',
                      'fresh_owner_consent_verified', 'independent_owner_authority_verified',
                      'revocation_verified', 'durable_one_shot_verified',
                      'secure_enclave_key_verified', 'execution_allowed',
                      'real_aiven_connected', 'production_go'):
            self.assertIs(result[field], False)
        with self.assertRaises(Block): p256.authorize(result)
        self.assertEqual(self.verify(self.sign())['verified_steps'], 8)

    def test_wrong_key_tamper_and_wrong_signature_format_rejected(self):
        valid = self.sign()
        wrong = ec.generate_private_key(ec.SECP256R1())
        corrupt = list(valid)
        corrupt[2] = (valid[2][0]+b' ', valid[2][1])
        wrongsig = list(valid)
        wrongsig[4] = (valid[4][0], valid[4][1][:-1] + b'X')
        trailer = list(valid)
        trailer[1] = (valid[1][0], valid[1][1]+b'\x00')
        raw = list(valid)
        raw[3] = (valid[3][0], b'\x00'*64)  # not ECDSA DER
        for attempt in (corrupt, wrongsig, trailer, raw):
            with self.subTest(case=str(attempt[0][0][:15])):
                with self.assertRaises(Block): self.verify(attempt)
        with self.assertRaises(Block):
            self.verify(valid, pub=self.public(wrong))
        with self.assertRaises(Block):
            self.verify(valid, pub=b'\x04'+b'\x00'*64)
        with self.assertRaises(Block):
            self.verify(valid, pub=b'\x00'*32)

    def test_reorder_missing_duplicate_and_cross_algorithm_rejected(self):
        valid = self.sign()
        for records in ([], valid[:-1], valid[::-1], valid+[valid[0]],
                        [valid[0]]+valid[:7], self.sign(steps=tuple(reversed(p256.STEPS)))):
            with self.assertRaises(Block): self.verify(records)
        # #869 Ed25519 synthetic claim labels never cross the P-256 boundary.
        with self.assertRaises(Block):
            self.verify(self.sign({'kind':ed25519.KIND}))
        with self.assertRaises(Block):
            self.verify(self.sign({'algorithm':'Ed25519'}))
        with self.assertRaises(Block):
            self.verify(self.sign({'algorithm':'ECDSA_P256_SHA256_RAW_V0'}))
        with self.assertRaises(Block):
            self.verify(self.sign({'b2_go':True}))

    def test_signature_cannot_spoof_trust_epoch_fresh_go_or_operator(self):
        for change in (
            {'operator':'ACTUAL_OWNER'}, {'issuer':'TRUSTED_ISSUER'},
            {'kind':'B2_OWNER_GO'}, {'key_epoch':'LIVE_EPOCH'},
            {'fresh_owner_consent_verified':True}, {'execution_allowed':True},
            {'public_key_sha256':'0'*64}, {'challenge_sha256':'0'*64},
            {'step':'B2'}
        ):
            with self.subTest(change=change):
                with self.assertRaises(Block): self.verify(self.sign(change))
        forged = ec.generate_private_key(ec.SECP256R1())
        # Re-signing with another caller-provided key cannot create real trust.
        result = self.verify(self.sign(signer=forged), pub=self.public(forged))
        self.assertFalse(result['independent_owner_authority_verified'])

    def test_scope_challenge_time_and_signed_binding_reject(self):
        valid = self.sign()
        for field, other in {
            'source_sha':'0'*40, 'release_ref':'refs/heads/main',
            'tooling_sha':'0'*40, 'bundle_sha256':'0'*64,
            'sql_sha256':'0'*64, 'reference_sha256':'0'*64,
            'runtime_sha256':'0'*64, 'binding_sha256':'0'*64,
            'target_ca_sha256':'0'*64, 'scope':'full17',
            'engine':'9.0', 'attempt_id':'0'*32
        }.items():
            with self.subTest(field=field):
                changed = dict(self.binding, **{field:other})
                with self.assertRaises(Block):
                    self.verify(valid, binding=changed)
                with self.assertRaises(Block):
                    self.verify(self.sign(binding=changed))
        for case in ({'issued_at':self.now+1},
                     {'expires_at':self.now},
                     {'issued_at':self.now-901},
                     {'issued_at':True},
                     {'expires_at':'later'}):
            with self.assertRaises(Block): self.verify(self.sign(case))
        for now in (True, None, float('nan'), self.now+61, self.now-120):
            with self.assertRaises(Block): self.verify(valid, now=now)
        for challenge in ('0'*64, 'abc', True, None):
            with self.assertRaises(Block): self.verify(valid, challenge=challenge)
        with self.assertRaises(Block):
            self.verify(valid, binding={'source_sha':SOURCE})

    def test_b1_public_and_live_methods_still_unconditionally_block(self):
        self.assertNotIn('scripts/ci/e858_phase1/b2_p256_synthetic_interop.py',
                         b1_package.FILES)
        with patch('socket.getaddrinfo') as dns, patch('socket.socket') as sock:
            self.verify(self.sign())
            with self.assertRaises(Block): p256.authorize({'go':True})
            with self.assertRaises(Block):
                object.__new__(launcher_candidate.LauncherCandidate).run()
            with self.assertRaises(Block):
                object.__new__(opener_candidate.OpenerCandidate).open()
            with self.assertRaises(Block):
                object.__new__(practical.Adapter).prepare()
            with self.assertRaises(Block):
                object.__new__(practical.PrivateWire).connect()
            dns.assert_not_called()
            sock.assert_not_called()


if __name__ == '__main__':
    unittest.main()
