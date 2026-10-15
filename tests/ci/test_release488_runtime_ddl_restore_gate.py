"""No-network self-attestation gate negative controls (not Aiven evidence)."""
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from scripts.ci.release488_runtime_ddl_restore_gate import (
    FIELDS, LIVE, PINNED, InvalidPacket, decide, validate
)

ROOT = Path(__file__).resolve().parents[2]


def packet(value=False):
    return {
        "version": 1,
        "live_sha": LIVE,
        "candidate_sha": PINNED,
        **{part: {k: value for k in keys} for part, keys in FIELDS.items()}
    }


class Release488DdlRecoveryTest(unittest.TestCase):
    def test_empty_or_unknown_operator_observations_never_go(self):
        p = packet()
        result = decide(validate(json.dumps(p).encode()))
        self.assertEqual("BLOCK", result["result"])
        self.assertEqual("SERVICE_DATABASE_OR_RUNTIME_IDENTITY_UNVERIFIED", result["code"])
        self.assertFalse(result["release_authorized"])
        self.assertFalse(result["aiven_service_queried_by_this_tool"])

    def test_every_required_field_independently_holds_release(self):
        base = packet(True)
        for section, keys in FIELDS.items():
            for key in keys:
                with self.subTest(section=section, key=key):
                    p = packet(True)
                    p[section][key] = False
                    r = decide(validate(json.dumps(p).encode()))
                    self.assertEqual("BLOCK", r["result"])
                    self.assertFalse(r["release_authorized"])
        self.assertEqual(sum(len(k) for k in FIELDS.values()), 20)

    def test_even_every_checkbox_true_is_human_review_not_release(self):
        r = decide(validate(json.dumps(packet(True)).encode()))
        self.assertEqual("REVIEW_REQUIRED", r["result"])
        self.assertEqual("MANUAL_ATTESTATION_PACKET_REQUIRES_INDEPENDENT_REVIEW", r["code"])
        for key in (
            "release_authorized", "aiven_service_queried_by_this_tool",
            "production_runtime_ddl_technically_verified_by_this_tool",
            "backup_restoration_technically_verified_by_this_tool",
        ):
            self.assertFalse(r[key])

    def test_malformed_or_secret_bearing_packets_fail_closed(self):
        valid = packet()
        wrong = [
            {**valid, "candidate_sha": "main"},
            {**valid, "live_sha": PINNED},
            {**valid, "version": "1"},
            {**valid, "version": True},
            {**valid, "host": "example.private"},
            {**valid, "password": "should-never-be-here"},
            {**valid, "identity": {**valid["identity"], "password": "hidden"}},
            {**valid, "ddl": {**valid["ddl"], "trigger_privilege_confirmed": 1}},
            {**valid, "recovery": {}},
        ]
        for obj in wrong:
            with self.subTest(obj=set(obj)):
                with self.assertRaises(InvalidPacket):
                    validate(json.dumps(obj).encode())
        duplicates = b'{"version":1,"version":1}'
        with self.assertRaises(InvalidPacket):
            validate(duplicates)
        with self.assertRaises(InvalidPacket):
            validate(json.dumps(valid).encode() + b"X" * 5000)

    def test_no_raw_private_input_leaks_to_stdout_or_stderr(self):
        with TemporaryDirectory() as root:
            bad = Path(root) / "input.json"
            bad.write_text('{"secret_token":"do_not_repeat"}')
            command = [
                sys.executable, str(ROOT / "scripts/ci/release488_runtime_ddl_restore_gate.py"),
                "--input", str(bad),
            ]
            p = subprocess.run(command, capture_output=True, text=True)
            self.assertEqual(p.returncode, 2)
            self.assertEqual("PACKET_INVALID_OR_UNSAFE", json.loads(p.stdout)["code"])
            self.assertNotIn("do_not_repeat", p.stdout + p.stderr)
            self.assertNotIn(root, p.stdout + p.stderr)

    def test_no_implicit_global_ddl_or_aiven_api(self):
        source = (ROOT / "scripts/ci/release488_runtime_ddl_restore_gate.py").read_text()
        self.assertNotIn("import requests", source)
        self.assertNotIn("import mysql", source)
        self.assertNotIn("import pymysql", source)
        self.assertNotIn("DB_PASSWORD", source)
        self.assertNotIn("CREATE TRIGGER", source)
        self.assertNotIn("release_authorized\": True", source)
