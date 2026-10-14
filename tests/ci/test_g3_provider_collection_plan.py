"""R28 uses disposable local Git objects, never live provider credentials."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.g3_provider_collection_plan import (
    COLLECTION, REQUIREMENTS, InvalidInventory, evaluate, load_plan, template,
)


class CollectionPlanTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "CI")
        self.git("config", "user.email", "ci@example.invalid")
        (self.repo / "README.md").write_text("disposable only\n", encoding="utf-8")
        self.git("add", ".")
        self.git("commit", "-qm", "isolated plan candidate")
        self.plan = template(self.git("rev-parse", "HEAD"))

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", str(self.repo), *args],
            text=True, stderr=subprocess.DEVNULL,
        ).strip()

    def check(self, plan=None):
        return evaluate(self.repo, self.plan if plan is None else plan)

    def denied(self, result):
        for flag in (
            "real_provider_authorized", "collection_performed",
            "independent_evidence_verified", "provider_authenticated",
            "release_authorized", "retry_authorized", "delete_authorized",
            "production_modified",
        ):
            self.assertIs(result[flag], False, flag)
        self.assertEqual(set(result["coverage"]), set(REQUIREMENTS))
        self.assertTrue(all(state == "NOT_COLLECTED"
                            for state in result["coverage"].values()))
        self.assertNotIn(str(self.repo), json.dumps(result))
        self.assertNotIn("isolated plan candidate", json.dumps(result))

    def test_full_correct_plan_never_authorizes_even_disposable_access(self):
        result = self.check()
        self.assertEqual(result["result"], "PLAN_REVIEW_ONLY")
        self.denied(result)
        self.assertEqual(len(COLLECTION), 11)

    def test_each_requirement_has_exactly_scoped_target_and_two_negative_cases(self):
        for item in self.plan["requirements"]:
            self.assertEqual(len(item["negative_cases"]), 2)
            self.assertEqual(item["capture_state"], "not_started")
            self.assertEqual(item["review_state"], "unverified")
            self.assertEqual(item["separate_owner_gate"], "required")

    def test_production_and_staging_execution_requests_are_invalid(self):
        for change in (
            {"environment": "production"},
            {"environment": "staging"},
            {"environment": "user_data"},
            {"execution_mode": "live_capture"},
            {"execution_mode": "deploy"},
            {"authorization": "granted"},
            {"authorization": "owner_approved"},
            {"real_provider_authorized": True},
            {"release_authorized": True},
            {"schema": "verified_provider"},
            {"candidate_sha": "main"},
        ):
            plan = copy.deepcopy(self.plan)
            plan.update(change)
            result = self.check(plan)
            self.assertEqual(result["result"], "INVALID")
            self.denied(result)

    def test_cross_requirement_target_method_and_negative_test_spoofing(self):
        for field, value in (
            ("owner_role", "release"),
            ("collection_target", "production_provider"),
            ("source_class", "sdk_header_is_proof"),
            ("negative_cases", ["skipped", "skipped"]),
            ("negative_cases", ["duplicate_nonce"]),
            ("original_custody", "public_github"),
            ("summary_custody", "raw_s3_logs"),
            ("capture_state", "captured"),
            ("review_state", "provider_verified"),
            ("separate_owner_gate", "not_required"),
            ("delete_authorized", True),
        ):
            plan = copy.deepcopy(self.plan)
            plan["requirements"][0][field] = value
            result = self.check(plan)
            self.assertEqual(result["result"], "INVALID", field)
            self.denied(result)

    def test_reordering_is_allowed_but_duplicates_or_missing_are_invalid(self):
        plan = copy.deepcopy(self.plan)
        plan["requirements"].reverse()
        self.assertEqual(self.check(plan)["result"], "PLAN_REVIEW_ONLY")
        plan["requirements"][1] = copy.deepcopy(plan["requirements"][0])
        self.assertEqual(self.check(plan)["result"], "INVALID")
        plan = copy.deepcopy(self.plan)
        plan["requirements"].pop()
        self.assertEqual(self.check(plan)["result"], "INVALID")

    def test_external_or_missing_git_commit_is_not_trusted(self):
        plan = copy.deepcopy(self.plan)
        plan["candidate_sha"] = "f" * 40
        result = self.check(plan)
        self.assertEqual(result["result"], "BLOCK")
        self.denied(result)
        plan["candidate_sha"] = "1" * 39
        self.assertEqual(self.check(plan)["result"], "INVALID")

    def test_manifest_duplicate_json_keys_and_oversize_fail_closed(self):
        path = self.repo / "plan.json"
        path.write_text('{"authorization":"not_granted","authorization":"granted"}')
        with self.assertRaises(InvalidInventory):
            load_plan(path)
        path.write_bytes(b" " * 65537)
        with self.assertRaises(InvalidInventory):
            load_plan(path)
        with self.assertRaises(InvalidInventory):
            load_plan(self.repo / "nonexistent.json")

    def test_untrusted_extra_keys_and_wrong_types_are_invalid(self):
        for change in (
            {"requirements": "verified"},
            {"requirements": {}},
            {"environment": ["disposable_only"]},
            {"candidate_sha": 17},
        ):
            plan = copy.deepcopy(self.plan)
            plan.update(change)
            self.assertEqual(self.check(plan)["result"], "INVALID")
        plan = copy.deepcopy(self.plan)
        plan["requirements"][0]["negative_cases"] = tuple(
            plan["requirements"][0]["negative_cases"])
        self.assertEqual(self.check(plan)["result"], "INVALID")

    def test_plan_does_not_mutate_local_repo(self):
        before = self.git("status", "--porcelain")
        self.check()
        self.assertEqual(self.git("status", "--porcelain"), before)


if __name__ == "__main__":
    unittest.main()
