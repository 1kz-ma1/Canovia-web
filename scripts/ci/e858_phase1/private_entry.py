"""B-option supervised operator entry rehearsal, strictly offline.

One caller-owned private anchor FD and foreground terminal FD are observed
in ONE bounded fail-closed sequence. Only the fixed phase1-private child is
opened; no target.json, ca.pem, passwords, TTY reads, connections or approval.
This does not verify the anchor's ancestor origin or the human operator.
"""
import os
import stat
import sys
from .private_binding import Block
from . import private_root, private_acl, private_operator

_ALLOWED_ACL = ('ACL_RECORD_ENOENT_UNVERIFIED', 'ZERO_ACL_ENTRIES_OBSERVED')


def review_b_operator_entry(anchor_fd, terminal_fd):
    """Observation-only B1 rehearsal; NEVER an executable or trusted entry."""
    try:
        if (sys.platform != 'darwin'
                or type(anchor_fd) is not int or type(terminal_fd) is not int
                or min(anchor_fd, terminal_fd) <= 2 or anchor_fd == terminal_fd
                or os.get_inheritable(anchor_fd)
                or os.get_inheritable(terminal_fd)):
            raise Block()
        anchor_before = os.fstat(anchor_fd)
        terminal_before = os.fstat(terminal_fd)
        if (not private_root._private_directory(anchor_before)
                or not stat.S_ISCHR(terminal_before.st_mode)):
            raise Block()
        anchor_acl = private_acl.inspect_native_acl(anchor_fd)
        if (type(anchor_acl) is not dict
                or anchor_acl.get('kind') != 'INERT_NATIVE_ACL_OBSERVATION'
                or anchor_acl.get('result') not in _ALLOWED_ACL
                or anchor_acl.get('execution_allowed') is not False):
            raise Block()
        with private_root.open_private_root(anchor_fd) as child:
            child_acl = private_acl.inspect_native_acl(child.descriptor())
            if (type(child_acl) is not dict
                    or child_acl.get('kind') != 'INERT_NATIVE_ACL_OBSERVATION'
                    or child_acl.get('result') not in _ALLOWED_ACL
                    or child_acl.get('execution_allowed') is not False):
                raise Block()
            tty = private_operator.observe_foreground_terminal(terminal_fd)
            if (type(tty) is not dict
                    or tty.get('kind') != 'INERT_FOREGROUND_TERMINAL_OBSERVATION'
                    or tty.get('foreground_group') != 'OBSERVED_NOT_AUTHENTICATED'
                    or tty.get('operator_identity') != 'UNVERIFIED'
                    or tty.get('explicit_owner_gesture') != 'UNVERIFIED'
                    or tty.get('real_credential_read') is not False
                    or tty.get('execution_allowed') is not False):
                raise Block()
            if (private_root._identity(anchor_before) !=
                    private_root._identity(os.fstat(anchor_fd))
                    or private_operator._identity(terminal_before) !=
                    private_operator._identity(os.fstat(terminal_fd))
                    or os.get_inheritable(anchor_fd)
                    or os.get_inheritable(terminal_fd)):
                raise Block()
            child.descriptor()  # Fail if the previously preflighted FD changed.
        return dict(
            kind='E858_B_INERT_OPERATOR_ENTRY_REHEARSAL',
            current_design='B_OWNER_SUPERVISED_SINGLE_METADATA_AUDIT_ADOPTED',
            fixed_child_parent='ONE_LEVEL_FD_RELATION_OBSERVED',
            anchor_ancestry='UNVERIFIED',
            anchor_acl=anchor_acl['result'], child_acl=child_acl['result'],
            foreground_terminal='OBSERVED_NOT_AUTHENTICATED',
            real_owner_identity='UNVERIFIED',
            explicit_owner_gesture='UNVERIFIED',
            b1_private_acceptance='PENDING',
            b2_fresh_owner_go='NOT_GRANTED',
            target_ca_read=False, credential_read=False,
            network_connected=False, real_aiven_connected=False,
            live_tooling_adopted=False, execution_allowed=False,
        )
    except BaseException:
        raise Block() from None
