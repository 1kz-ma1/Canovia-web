#!/usr/bin/env python3
"""G3 R27 offline evidence inventory. No provider validation or authorization."""
from __future__ import annotations
import hashlib
import json
import re
import subprocess
from pathlib import Path

REQUIREMENTS = {
    'provider_identity_and_trust_root': 'security',
    'response_authenticity': 'security',
    'request_and_generation_binding': 'storage',
    'anti_replay_continuity': 'security',
    'bucket_versioning_and_iam': 'storage',
    'external_writer_fencing': 'storage',
    'generation_conditional_operations': 'storage',
    'crash_restart_recovery_test': 'platform',
    'cross_database_cleanup_reconciliation': 'platform',
    'key_rotation_and_revocation_test': 'security',
    'owner_go_and_rollout_plan': 'release',
}
