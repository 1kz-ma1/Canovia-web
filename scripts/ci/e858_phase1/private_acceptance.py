"""HOLD-only synthesis of untrusted offline Phase1 observation receipts.

No authority, real target/CA/credential reader, persistence, nonce store,
network, SQL, subprocess or operator entry. Receipt shape is NOT provenance.
A consumer MUST NOT use this assessment to authorize a connection.
"""
from .private_binding import Block

GATES = (
    'TRUSTED_ANCHOR_ANCESTRY',
    'NATIVE_ACL_OWNER_ACCEPTANCE',
    'OWNER_IDENTITY_AND_EXPLICIT_GESTURE',
    'SIGNED_RUNTIME_ORIGIN_AND_TRANSITIVE_LINKAGE',
    'REAL_TARGET_CA_TLS_IDENTITY_BRIDGE',
    'INDEPENDENT_APPROVAL_AUTHORITY',
    'DURABLE_ONE_SHOT_AND_REVOCATION',
    'REVIEWED_LIVE_TOOLING_SHA_AND_ARTIFACT_LOCK',
    'PRIVATE_P1_AND_INDIVIDUAL_R1_R6_ACCEPTANCES',
    'FRESH_OWNER_PHASE1_SINGLE_ATTEMPT_GO',
)

# Retain just the fixed non-secret statuses. These schemas describe receipts,
# not authenticators. A forged dict can never advance any gate to accepted.
REQUIRED = {
    'root': {
        'kind': 'INERT_PRIVATE_ROOT_PREFLIGHT',
        'ancestor_trust': 'UNVERIFIED',
        'native_acl': 'UNVERIFIED',
        'operator_identity': 'UNVERIFIED',
        'execution_allowed': False,
        'real_private_record_read': False,
    },
    'acl': {
        'kind': 'INERT_NATIVE_ACL_OBSERVATION',
        'ancestor_trust': 'UNVERIFIED',
        'operator_identity': 'UNVERIFIED',
        'execution_allowed': False,
        'real_target_read': False,
        'live_tooling_adopted': False,
    },
    'tty': {
        'kind': 'INERT_FOREGROUND_TERMINAL_OBSERVATION',
        'foreground_group': 'OBSERVED_NOT_AUTHENTICATED',
        'explicit_owner_gesture': 'UNVERIFIED',
        'operator_identity': 'UNVERIFIED',
        'execution_allowed': False,
        'real_credential_read': False,
        'live_tooling_adopted': False,
    },
    'native_runtime': {
        'kind': 'INERT_DARWIN_NATIVE_RUNTIME_OBSERVATION',
        'executable_origin': 'UNVERIFIED',
        'transitive_linkage': 'UNVERIFIED',
        'operator_identity': 'UNVERIFIED',
        'runtime_complete': False,
        'live_tooling_adopted': False,
        'execution_allowed': False,
        'real_aiven_connected': False,
    },
    'wheel': {
        'result': 'PINNED_WHEEL_RECORD_SOURCES_MATCH_OBSERVED',
        'untracked_files': 'UNVERIFIED',
        'runtime_linkage_closure': 'UNVERIFIED',
        'runtime_complete': False,
        'live_tooling_adopted': False,
        'execution_allowed': False,
    },
    'archive': {
        'result': 'INERT_ARCHIVE_VERIFIED',
        'execution_allowed': False,
        'live_tooling_adopted': False,
        'runtime_complete': False,
    },
}
ACL_RESULTS = ('ACL_RECORD_ENOENT_UNVERIFIED', 'ZERO_ACL_ENTRIES_OBSERVED')


def review_inert_receipts(receipts):
    """Summarize only the shapes of existing synthetic/offline evidence.

    This deliberately has no 'approved' input and no GO state transition.
    An input passing these checks is still freely forgeable by its caller.
    """
    try:
        if type(receipts) is not dict or len(receipts) > len(REQUIRED):
            raise Block()
        observations = {key: 'NOT_SUPPLIED' for key in REQUIRED}
        for name, value in receipts.items():
            if name not in REQUIRED or type(value) is not dict or len(value) > 40:
                raise Block()
            for key, expected in REQUIRED[name].items():
                if type(expected) is bool:
                    if type(value.get(key)) is not bool or value[key] is not expected:
                        raise Block()
                elif value.get(key) != expected:
                    raise Block()
            if name == 'acl' and value.get('result') not in ACL_RESULTS:
                raise Block()
            # Shapes are never independently sourced or operator-signed.
            observations[name] = 'UNTRUSTED_RECEIPT_SHAPE_ONLY'
        return dict(
            kind='E858_INERT_OWNER_ADOPTION_GAP_REVIEW',
            result='OWNER_ADOPTION_HOLD',
            receipt_authenticity='UNVERIFIED',
            observations=observations,
            pending_gates=list(GATES),
            required_design_decision='A_OR_B_OWNER_SELECTION_PENDING',
            owner_authority='UNVERIFIED',
            durable_one_shot='NOT_IMPLEMENTED',
            runtime_complete=False,
            live_tooling_adopted=False,
            real_aiven_connected=False,
            execution_allowed=False,
            production_go=False,
        )
    except BaseException:
        raise Block() from None
