"""R29 tests are confined to disposable local Git, no cloud/credentials."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.g3_provider_collection_plan import template as collection_template
from scripts.ops.g3_disposable_approval_packet import (
    ACTIONS, STOPS, STOP_RESPONSES, evaluate, template, load_packet, InvalidInventory,
)


class R29OwnerDecisionPacketTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "CI")
        self.git("config", "user.email", "ci@example.invalid")
        (self.repo / "README.md").write_text("offline synthetic fixture\n")
        self.git("add", ".")
        self.git("commit", "-qm", "R29 isolated plan")
        sha = self.git("rev-parse", "HEAD")
        self.packet = template(sha, collection_template(sha))

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", str(self.repo), *args],
            text=True, stderr=subprocess.DEVNULL,
        ).strip()

    def inspect(self, changed=None):
        return evaluate(self.repo, self.packet if changed is None else changed)

    def assert_deny(self, result):
        for field in (
            "owner_approval_verified", "security_review_verified",
            "provider_access_authorized", "real_provider_test_executed",
            "budget_authorized", "conditional_delete_authorized",
            "production_migration_authorized", "release_authorized",
            "cleanup_authorized", "production_modified",
        ):
            self.assertIs(result[field], False, field)
        self.assertIs(result["scope_proposed_only"], True)
        self.assertEqual(set(result), set(self.inspect_fields()))
        data = json.dumps(result)
        for secret in (str(self.repo), "test_secret", "owner@example"):
            self.assertNotIn(secret, data)

    @staticmethod
    def inspect_fields():
        return (
            "result", "scope_proposed_only", "owner_approval_verified",
            "security_review_verified", "provider_access_authorized",
            "real_provider_test_executed", "budget_authorized",
            "conditional_delete_authorized", "production_migration_authorized",
            "release_authorized", "cleanup_authorized", "production_modified",
        )

    def test_complete_request_is_not_approval_and_has_no_side_effects(self):
        before = self.git("status", "--porcelain")
        result = self.inspect()
        self.assertEqual(result["result"], "OWNER_DECISION_REQUIRED")
        self.assert_deny(result)
        self.assertEqual(self.git("status", "--porcelain"), before)
        self.assertEqual(tuple(self.packet["requested_actions"]), ACTIONS)
        self.assertEqual(tuple(self.packet["stop_conditions"]), STOPS)
        self.assertEqual(tuple(self.packet["abort_and_recovery"]), STOP_RESPONSES)

    def test_all_limit_overruns_wrong_types_and_currency_are_rejected(self):
        for field, bad in (
            ("max_spend_usd", 26), ("max_duration_minutes", 121),
            ("max_test_objects", 11), ("max_provider_requests", 201),
            ("max_spend_usd", 0), ("max_test_objects", -1),
            ("max_duration_minutes", True), ("max_spend_usd", 10.0),
            ("max_provider_requests", "100"), ("currency", "JPY"),
        ):
            p = copy.deepcopy(self.packet)
            p["proposed_limits"][field] = bad
            result = self.inspect(p)
            self.assertEqual(result["result"], "INVALID", field)
            self.assert_deny(result)
        p = copy.deepcopy(self.packet)
        p["proposed_limits"]["unbounded_spend"] = True
        self.assertEqual(self.inspect(p)["result"], "INVALID")

    def test_production_staging_or_scope_confusion_is_rejected(self):
        for field, value in (
            ("environment", "production"),
            ("environment", "staging"),
            ("bucket_selector", "customer-bucket"),
            ("account_selector", "real-account-123"),
            ("region_selector", "ap-northeast-1"),
            ("principal", "root_user"),
            ("test_data", "real_customer_data"),
            ("credential_storage", "repository"),
            ("raw_evidence_custody", "public_github"),
            ("published_evidence", "raw_customer_logs"),
        ):
            p = copy.deepcopy(self.packet)
            p[field] = value
            self.assertEqual(self.inspect(p)["result"], "INVALID", field)
        self.assert_deny(self.inspect())

    def test_authorization_spoofing_and_bypass_controls_are_rejected(self):
        for field, value in (
            ("approval_state", "approved"),
            ("authorization", "granted"),
            ("owner_identity_verification", "verified"),
            ("security_review", "approved"),
            ("conditional_delete_phase", "automatically_approved"),
            ("release_permission", "granted"),
            ("production_migration_permission", "yes"),
            ("provider_access_authorized", True),
            ("delete_authorized", True),
            ("separate_owner_go", False),
        ):
            p = copy.deepcopy(self.packet)
            p[field] = value
            result = self.inspect(p)
            self.assertEqual(result["result"], "INVALID", field)
            self.assert_deny(result)

    def test_operations_stop_conditions_and_abort_recovery_are_immutable(self):
        for key, changed in (
            ("requested_actions", list(ACTIONS[:-1])),
            ("requested_actions", list(ACTIONS) + ["delete_customer_bucket"]),
            ("requested_actions", list(reversed(ACTIONS))),
            ("stop_conditions", list(STOPS[:-1])),
            ("stop_conditions", ["ignore_invalid_identity", *STOPS[1:]]),
            ("abort_and_recovery", ["delete_all_objects", *STOP_RESPONSES[1:]]),
            ("abort_and_recovery", []),
        ):
            p = copy.deepcopy(self.packet)
            p[key] = changed
            self.assertEqual(self.inspect(p)["result"], "INVALID", key)

    def test_wrong_sha_invalid_plan_and_foreign_commit_fail_closed(self):
        p = copy.deepcopy(self.packet)
        p["candidate_sha"] = "0" * 40
        self.assertEqual(self.inspect(p)["result"], "INVALID")
        p = copy.deepcopy(self.packet)
        p["candidate_sha"] = p["collection_plan"]["candidate_sha"] = "f" * 40
        self.assertEqual(self.inspect(p)["result"], "BLOCK")
        p = copy.deepcopy(self.packet)
        p["collection_plan"]["authorization"] = "granted"
        self.assertEqual(self.inspect(p)["result"], "INVALID")
        p = copy.deepcopy(self.packet)
        p["collection_plan"]["requirements"].pop()
        self.assertEqual(self.inspect(p)["result"], "INVALID")

    def test_duplicate_json_keys_oversize_and_absent_file_fail_closed(self):
        path = self.repo / "packet.json"
        path.write_text('{"authorization":"not_granted","authorization":"granted"}')
        with self.assertRaises(InvalidInventory):
            load_packet(path)
        path.write_bytes(b" " * 65537)
        with self.assertRaises(InvalidInventory):
            load_packet(path)
        with self.assertRaises(InvalidInventory):
            load_packet(self.repo / "missing.json")


if __name__ == "__main__":
    unittest.main()
