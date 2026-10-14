"""Offline, fail-closed, exact release/* SHA V5.9 operator packet tests."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.development_operational_v59_candidate_packet import (
    V58_BLOCK, V58_REVIEW, REQUIREMENTS, InvalidPacket,
    blocked, checked_ref, packet, parse_v58_report,
)

ROOT = Path(__file__).resolve().parents[2]
VALID_V58 = {
    "result": "REVIEW_REQUIRED",
    "code": "EXACT_V53_V57_PENDING",
    "release_authorized": False,
    "production_database_modified": False,
    "production_identity_verified": False,
    "backup_restore_verified": False,
    "deployed_sha_verified": False,
    "device_acceptance_verified": False,
}


class V59ReleasePacketTest(unittest.TestCase):
    def test_fixed_checklist_has_no_approval_or_independent_proof(self):
        sha = "a" * 40
        for state in [None, "EXACT_V53_V57_PENDING", "V53_APPLIED_V57_PENDING",
                      "BOTH_MIGRATIONS_APPLIED_NOT_RELEASE_GO", "V58_BLOCKED"]:
            with self.subTest(state=state):
                result = packet(sha, "release/2026-10-14-aaaaaaa", state)
                self.assertEqual("BLOCK", result["result"])
                self.assertFalse(result["release_authorized"])
                self.assertFalse(result["production_database_modified"])
                self.assertFalse(result["backup_restore_verified"])
                self.assertFalse(result["deployed_sha_verified"])
                self.assertFalse(result["device_acceptance_verified"])
                self.assertEqual(len(REQUIREMENTS), len(result["requirements"]))
                self.assertEqual(len(REQUIREMENTS), len({
                    item["id"] for item in result["requirements"]
                }))
                self.assertTrue(all(x["independently_verified"] is False
                                    for x in result["requirements"]))
                self.assertTrue(all(x["state"] not in ("GO", "VERIFIED", "PASS")
                                    for x in result["requirements"]))
                self.assertEqual(sha, result["candidate_sha"])
        self.assertEqual("UNVERIFIED", packet(sha, "release/2026-10-14-aaaaaaa", None)["requirements"][2]["state"])
        self.assertEqual("REPORTED_REVIEW_REQUIRED_UNVERIFIED",
                         packet(sha, "release/2026-10-14-aaaaaaa", VALID_V58["code"])["requirements"][2]["state"])
        self.assertEqual("BLOCK", packet(sha, "release/2026-10-14-aaaaaaa", "V58_BLOCKED")["requirements"][2]["state"])

    def test_v58_report_rejects_invented_pass_extra_keys_and_secrets(self):
        self.assertEqual("EXACT_V53_V57_PENDING", parse_v58_report(json.dumps(VALID_V58).encode()))
        for code in V58_REVIEW:
            r = dict(VALID_V58, code=code)
            self.assertEqual(code, parse_v58_report(json.dumps(r).encode()))
        for code in V58_BLOCK:
            r = dict(VALID_V58, result="BLOCK", code=code)
            self.assertEqual("V58_BLOCKED", parse_v58_report(json.dumps(r).encode()))
        bad = [
            dict(VALID_V58, result="GO"),
            dict(VALID_V58, result="SUCCESS"),
            dict(VALID_V58, release_authorized=True),
            dict(VALID_V58, device_acceptance_verified=True),
            dict(VALID_V58, code="UNRECOGNIZED_PASS"),
            dict(VALID_V58, code=["EXACT_V53_V57_PENDING"]),
            dict(VALID_V58, extra="password=secret"),
        ]
        for value in bad:
            with self.subTest(case=type(value.get("code")).__name__):
                with self.assertRaises(InvalidPacket):
                    parse_v58_report(json.dumps(value).encode())
        for data in [b"", b"secret=password", b"{", b"\x00",
                     json.dumps(VALID_V58).encode() + b"x" * 5000]:
            with self.assertRaises(InvalidPacket):
                parse_v58_report(data)

    def test_real_local_git_pin_and_missing_or_moving_release_ref(self):
        with tempfile.TemporaryDirectory() as temp:
            tmp = Path(temp)
            def git(*args):
                return subprocess.run(
                    ["git", *args], cwd=tmp, capture_output=True, text=True, check=True,
                ).stdout.strip()
            git("init", "-q")
            git("-c", "user.name=V59 Fixture", "-c", "user.email=v59@example.invalid",
                "commit", "--allow-empty", "-m", "Synthetic CI candidate")
            sha = git("rev-parse", "HEAD")
            name = "release/2026-10-14-" + sha[:7]
            self.assertFalse(checked_ref(sha, name, tmp))
            git("branch", name)
            self.assertTrue(checked_ref(sha, name, tmp))
            self.assertFalse(checked_ref(sha, "main", tmp))
            self.assertFalse(checked_ref("0" * 40, name, tmp))
            self.assertFalse(checked_ref(sha, "release/2026-10-14-evil123", tmp))
            self.assertFalse(checked_ref(sha, "release/2026-10-14-" + sha[:6], tmp))
            # A later moving checkout must not reuse an earlier approval packet.
            git("-c", "user.name=V59 Fixture", "-c", "user.email=v59@example.invalid",
                "commit", "--allow-empty", "-m", "Another synthetic commit")
            self.assertFalse(checked_ref(sha, name, tmp))
            new_sha = git("rev-parse", "HEAD")
            self.assertFalse(checked_ref(new_sha, name, tmp))

    def test_cli_missing_release_ref_blocks_with_no_sensitive_echo(self):
        script = ROOT / "scripts/ci/development_operational_v59_candidate_packet.py"
        sha = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT,
                             capture_output=True, text=True, check=True).stdout.strip()
        command = [sys.executable, str(script), "--candidate-sha", sha,
                   "--release-ref", "release/2026-10-14-" + sha[:7]]
        result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(2, result.returncode, result.stderr)
        value = json.loads(result.stdout)
        self.assertEqual("BLOCK", value["result"])
        self.assertEqual("CANDIDATE_REF_NOT_PINNED", value["reason"])
        self.assertNotIn(sha, result.stdout)
        self.assertFalse(value["release_authorized"])
        self.assertFalse(blocked("CANDIDATE_REF_NOT_PINNED")["release_authorized"])

    def test_no_network_api_keys_or_automatic_mutation_in_packet_implementation(self):
        code = (ROOT / "scripts/ci/development_operational_v59_candidate_packet.py").read_text()
        for forbidden in ("requests.get(", "requests.post(", "urllib.request.urlopen(",
                          "mysql.connector", "pymysql", "subprocess.Popen(", "git push",
                          "migrate --force", "release_authorized\": True"):
            self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
