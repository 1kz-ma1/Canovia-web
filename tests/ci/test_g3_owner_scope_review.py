"""R30 tests: local Git fixtures only; never provider access or signoff."""
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
from scripts.ops.g3_disposable_approval_packet import template as packet_template
from scripts.ops.g3_owner_scope_review import (
    ALLOWED, CHECKPOINTS, InvalidInventory, evaluate, load_review, sample_review,
)


class ScopeReviewTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "CI")
        self.git("config", "user.email", "ci@example.invalid")
        (self.repo / "README.md").write_text("local R30 fixture\n")
        self.git("add", ".")
        self.git("commit", "-qm", "isolated R30 fixture")
        sha = self.git("rev-parse", "HEAD")
        self.packet = packet_template(sha, collection_template(sha))
        self.review = sample_review(self.packet)

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", str(self.repo), *args],
            text=True, stderr=subprocess.DEVNULL,
        ).strip()

    def check(self, packet=None, review=None):
        return evaluate(self.repo,
            self.packet if packet is None else packet,
            self.review if review is None else review)

    def deny(self, data):
        for name in (
            "owner_identity_verified", "owner_approval_verified",
            "security_review_verified", "cloud_scope_enforced",
            "provider_access_authorized", "provider_requests_executed",
            "budget_authorized", "conditional_delete_authorized",
            "retry_authorized", "delete_authorized",
            "production_migration_authorized", "release_authorized",
            "production_modified",
        ):
            self.assertIs(data[name], False, name)
        self.assertEqual(set(data["checklist"]), set(CHECKPOINTS))
        self.assertTrue(all(x == "PENDING" for x in data["checklist"].values()))
        self.assertNotIn(str(self.repo), json.dumps(data))
        self.assertNotIn("disposable_synthetic_only", json.dumps(data))

    def test_full_pending_checklist_and_4_hypothetical_actions_still_not_granted(self):
        before = self.git("status", "--porcelain")
        output = self.check()
        self.assertEqual(output["result"], "OWNER_CHECKLIST_PENDING")
        self.assertIs(output["packet_integrity_checked_locally"], True)
        self.deny(output)
        self.assertEqual(self.git("status", "--porcelain"), before)
        self.assertEqual(len(ALLOWED), 4)

    def test_claimed_owner_security_or_provider_approval_is_rejected(self):
        for key, value in (
            ("owner_authorization", "approved"),
            ("security_attestation", "signed"),
            ("provider_access", "granted"),
            ("review_type", "real_execution"),
            ("approval_signature", "forged"),
            ("candidate_sha", "0" * 40),
            ("packet_sha256", "f" * 64),
        ):
            review = copy.deepcopy(self.review)
            review[key] = value
            out = self.check(review=review)
            self.assertEqual(out["result"], "INVALID", key)
            self.deny(out)

    def test_wrong_owner_roles_or_signed_checklist_are_rejected(self):
        for key, value in (
            ("role", "unaudited"),
            ("state", "approved"),
            ("state", "signed"),
            ("reviewer_id", "fake"),
            ("id", "unlisted_check"),
        ):
            review = copy.deepcopy(self.review)
            review["checklist"][0][key] = value
            out = self.check(review=review)
            self.assertEqual(out["result"], "INVALID", key)
            self.deny(out)

    def test_no_checklist_skip_duplicate_or_missing(self):
        for kind in ("skip", "duplicate", "missing", "string"):
            review = copy.deepcopy(self.review)
            if kind == "skip":
                review["checklist"][0]["state"] = "not_needed"
            elif kind == "duplicate":
                review["checklist"][1] = copy.deepcopy(review["checklist"][0])
            elif kind == "missing":
                review["checklist"].pop()
            else:
                review["checklist"] = "approved"
            self.assertEqual(self.check(review=review)["result"], "INVALID")

    def test_delete_and_other_unexpected_actions_are_blocked_even_if_in_r29(self):
        for name in (
            "test_generation_conditional_delete_on_disposable_object",
            "delete_customer_object", "set_bucket_iam", "deploy_production",
            "retry_delete_unverified_generation",
        ):
            review = copy.deepcopy(self.review)
            review["intents"][0]["action"] = name
            out = self.check(review=review)
            self.assertEqual(out["result"], "BLOCK", name)
            self.deny(out)

    def test_operation_scope_and_counter_spoofing_fail_closed(self):
        variants = (
            ("target", "production_bucket"),
            ("data_class", "customer_data"),
            ("operation_phase", "executable"),
            ("sequence", 9),
            ("requests", 10),
            ("new_objects", 10),
            ("estimated_cost_cents", True),
            ("estimated_cost_cents", -1),
            ("elapsed_minutes", "1"),
            ("access_token", "secret"),
        )
        for key, val in variants:
            review = copy.deepcopy(self.review)
            review["intents"][0][key] = val
            out = self.check(review=review)
            self.assertEqual(out["result"], "INVALID", key)
            self.deny(out)

    def test_untrusted_aggregate_estimates_cannot_exceed_packet_bounds(self):
        # Package limit choices remain UNAPPROVED; all attempts reject.
        for field, limit in (("max_provider_requests", 1),
                             ("max_test_objects", 1),
                             ("max_spend_usd", 1),
                             ("max_duration_minutes", 2)):
            packet = copy.deepcopy(self.packet)
            packet["proposed_limits"][field] = limit
            review = sample_review(packet)
            if field == "max_test_objects":
                review["intents"][2]["action"] = "put_disposable_synthetic_object"
                review["intents"][2]["new_objects"] = 1
            elif field == "max_spend_usd":
                for item in review["intents"]:
                    item["estimated_cost_cents"] = 60
            out = self.check(packet=packet, review=review)
            self.assertEqual(out["result"], "BLOCK", field)
            self.deny(out)

    def test_ordered_elapsed_time_and_narrow_intent_count(self):
        review = copy.deepcopy(self.review)
        review["intents"][2]["elapsed_minutes"] = 1
        self.assertEqual(self.check(review=review)["result"], "OWNER_CHECKLIST_PENDING")
        review["intents"][2]["elapsed_minutes"] = 0
        self.assertEqual(self.check(review=review)["result"], "INVALID")
        review = copy.deepcopy(self.review)
        review["intents"] = []
        self.assertEqual(self.check(review=review)["result"], "INVALID")
        review["intents"] = self.review["intents"] * 3
        self.assertEqual(self.check(review=review)["result"], "INVALID")

    def test_changed_packet_missing_commit_and_cross_sha_fail_closed(self):
        packet = copy.deepcopy(self.packet)
        packet["candidate_sha"] = "a" * 40
        self.assertEqual(self.check(packet=packet)["result"], "INVALID")
        packet = copy.deepcopy(self.packet)
        packet["candidate_sha"] = "a" * 40
        packet["collection_plan"]["candidate_sha"] = "a" * 40
        review = sample_review(packet)
        self.assertEqual(self.check(packet=packet, review=review)["result"], "BLOCK")
        packet = copy.deepcopy(self.packet)
        packet["requested_actions"] = ["delete_customer_object"]
        review = sample_review(packet)
        self.assertEqual(self.check(packet=packet, review=review)["result"], "INVALID")

    def test_invalid_json_and_oversize_are_denied(self):
        path = self.repo / "r30.json"
        path.write_text('{"result":"pending","result":"approved"}')
        with self.assertRaises(InvalidInventory):
            load_review(path)
        path.write_bytes(b" " * 65537)
        with self.assertRaises(InvalidInventory):
            load_review(path)
        with self.assertRaises(InvalidInventory):
            load_review(self.repo / "missing.json")


if __name__ == "__main__":
    unittest.main()
