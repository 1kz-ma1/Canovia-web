"""HOLD-only synthesis of untrusted offline Phase1 observation receipts.

No authority, real target/CA/credential reader, persistence, nonce store,
network, SQL, subprocess or operator entry. Receipt shape is NOT provenance.
A consumer MUST NOT use this assessment to authorize a connection.
"""
from .private_binding import Block

# Owner decision PR #803 (2026-10-20): B is the CURRENT baseline.
# Independent signing authority and rollback-resistant nonce ledger are
# FUTURE A only. They must not block this owner-supervised one-time B design.
GATES = (
    'TRUSTED_ANCHOR_ANCESTRY',
    'NATIVE_ACL_OWNER_ACCEPTANCE',
    'OWNER_IDENTITY_AND_EXPLICIT_GESTURE',
    'REVIEWED_RUNTIME_ORIGIN_AND_LINKAGE_LIMITS',
    'REAL_TARGET_CA_TLS_IDENTITY_BRIDGE',
    'B1_OPERATOR_TARGET_AND_FROZEN_PACKET_REVIEW',
    'B_PROCEDURAL_SINGLE_ATTEMPT_AND_NEW_GO_FOR_RETRY',
    'REVIEWED_LIVE_TOOLING_SHA_AND_ARTIFACT_LOCK',
    'PRIVATE_P1_AND_INDIVIDUAL_R1_R6_ACCEPTANCES',
    'B2_FRESH_OWNER_SINGLE_ATTEMPT_GO',
)
B_STAGES = {
    'B0': 'DESIGN_BASELINE_ADOPTED_2026_10_20',
    'B1': 'OFFLINE_PACKET_IMPLEMENTED_PRIVATE_ACCEPTANCE_PENDING',
    'B2': 'FRESH_OWNER_GO_NOT_GRANTED',
    'B3': 'REAL_AIVEN_AUDIT_NOT_RUN',
    'B4': 'REAL_AUDIT_EVIDENCE_NOT_AVAILABLE',
}

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
    # PR #804's synthetic B1 receipt is only a review of a frozen packet;
    # it does not attest owner consent, target identity, or a real attempt.
    'b1_offline': {
        'kind': 'E858_B_SYNTHETIC_RECEIPT_V1',
        'result': 'B_OFFLINE_REVIEW_ONLY',
        'code': 'CHECKLIST_DECLARATIONS_ONLY',
        'owner_approval_verified': False,
        'execution_allowed': False,
        'real_aiven_connected': False,
        'fresh_owner_go_required': True,
        'tls_session': 'UNIMPLEMENTED',
        'attempt': 'NOT_RUN',
        'cleanup': 'NOT_RUN',
        'retry_control': 'HUMAN_PROCEDURAL_ONLY',
        'release_authorized': False,
        'production_modified': False,
        'strict_block_waived': False,
    },
}
ACL_RESULTS = ('ACL_RECORD_ENOENT_UNVERIFIED', 'ZERO_ACL_ENTRIES_OBSERVED')
B1_CASES = ('NOT_RUN', 'COMPLETED', 'BLOCK', 'INTERRUPTED')


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
            if name == 'b1_offline' and value.get('synthetic_case') not in B1_CASES:
                raise Block()
            # Shapes are never independently sourced or operator-signed.
            observations[name] = 'UNTRUSTED_RECEIPT_SHAPE_ONLY'
        return dict(
            kind='E858_INERT_OWNER_ADOPTION_GAP_REVIEW',
            result='OWNER_ADOPTION_HOLD',
            receipt_authenticity='UNVERIFIED',
            observations=observations,
            pending_gates=list(GATES),
            current_design='B_OWNER_SUPERVISED_SINGLE_METADATA_AUDIT_ADOPTED',
            future_design='A_INDEPENDENT_SIGNING_AND_DURABLE_NONCE_DEFERRED',
            b_stages=dict(B_STAGES),
            owner_authority='B2_FRESH_GO_UNVERIFIED',
            retry_control='HUMAN_PROCEDURAL_NEW_GO_REQUIRED',
            durable_one_shot='FUTURE_A_ONLY_NOT_B_PREREQUISITE',
            runtime_complete=False,
            live_tooling_adopted=False,
            real_aiven_connected=False,
            execution_allowed=False,
            production_go=False,
        )
    except BaseException:
        raise Block() from None
