"""INERT synthetic-only SQLite consumption experiment after #869.

This is NOT a trusted one-shot authority. A local SQLite transaction prevents
simultaneous reuse only while every participant uses the same uncompromised
database file. Copying/restoring the file, altering the path, disk failure or
a different machine can reset the state. There is no authentic owner identity,
revocation, secure time or cryptographic custody. Nothing here authorizes any
network, SQL, Aiven, production release or B2 action.

This fixture is intentionally excluded from the public B1 archive.
"""
import hashlib
import os
import sqlite3

from .b2_signature_fixture import verify_synthetic_claims
from .private_binding import Block

# Reserved fixture namespace, deliberately unrelated to actual B2 authorization.
_NAMESPACE = 'UNADOPTED_SYNTHETIC_B2_LOCAL_LEDGER_V0'


def _fingerprint(records):
    h = hashlib.sha256(b'CANOVIA_SYNTHETIC_B2_CONSUME_V0\x00')
    for raw, signature in records:
        for part in (raw, signature):
            h.update(len(part).to_bytes(4, 'big'))
            h.update(part)
    return h.hexdigest()


def consume_synthetic_once(records, expected_binding, public_key, now, ledger_path):
    """Record one synthetic attempt with SQLite BEGIN IMMEDIATE / INSERT / COMMIT.

    For local tests only. The return value intentionally cannot authorize work.
    Signature verification precedes any ledger file creation; it is repeated
    under the write lock, so expiration during lock acquisition is not silently
    accepted according to the supplied synthetic clock. Neither clock nor
    storage path are independently trusted.
    """
    try:
        if (type(ledger_path) is not str or not os.path.isabs(ledger_path)
                or os.path.islink(ledger_path)
                or not os.path.isdir(os.path.dirname(ledger_path))
                or type(expected_binding) is not dict):
            raise Block()
        verify_synthetic_claims(records, expected_binding, public_key, now)
        attempt_id = expected_binding['attempt_id']
        digest = _fingerprint(records)
        # DB name and all contents are synthetic. No caller-supplied SQL or
        # secrets. DELETE journal + FULL synchronous on one local filesystem.
        with sqlite3.connect(ledger_path, timeout=5, isolation_level=None) as db:
            db.execute('PRAGMA journal_mode=DELETE')
            db.execute('PRAGMA synchronous=FULL')
            db.execute('BEGIN IMMEDIATE')
            try:
                verify_synthetic_claims(records, expected_binding, public_key, now)
                db.execute(
                    'CREATE TABLE IF NOT EXISTS synthetic_consumed_attempts ('
                    'namespace TEXT NOT NULL, attempt_id TEXT NOT NULL, '
                    'signed_set_sha256 TEXT NOT NULL, '
                    'PRIMARY KEY (namespace, attempt_id))'
                )
                db.execute(
                    'INSERT INTO synthetic_consumed_attempts '
                    '(namespace, attempt_id, signed_set_sha256) VALUES (?, ?, ?)',
                    (_NAMESPACE, attempt_id, digest),
                )
                db.execute('COMMIT')
            except BaseException:
                db.execute('ROLLBACK')
                raise
        return {
            'result': 'SYNTHETIC_LOCAL_ATTEMPT_CONSUMED_NOT_AUTHENTICATED',
            'local_sqlite_commit_observed': True,
            'rollback_resistance_verified': False,
            'trusted_store_verified': False,
            'independent_owner_authority_verified': False,
            'operator_authentication_verified': False,
            'durable_one_shot_verified': False,
            'execution_allowed': False,
            'real_aiven_connected': False,
            'production_go': False,
        }
    except BaseException:
        raise Block() from None


def authorize(*_args, **_kwargs):
    """No accepted record, ledger state or caller-supplied key can grant GO."""
    raise Block()
