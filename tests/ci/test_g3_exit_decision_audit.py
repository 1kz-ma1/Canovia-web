"""R31 pinned source audit: check drift and prove no positive exit path."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.g3_exit_decision_audit import (
    CONFIG_FLAGS, DOCS, MIGRATIONS, SCRIPTS, STATES, TESTS,
    evaluate, read_at_commit,
)


class G3ExitDecisionAuditTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "CI")
        self.git("config", "user.email", "ci@example.invalid")

        for path in MIGRATIONS:
            self.write(path, "<?php // additive migration fixture\n")
        self.write("config/canovia.php", "\n".join(
            "env('" + x + "', false)" for x in CONFIG_FLAGS
        ))
        self.write("app/Services/PrivateFileUnavailableProviderVerifier.php",
            "final class PrivateFileUnavailableProviderVerifier\n"
            "'verdict' => 'independent_verifier_unavailable'\n"
            "'provider_authenticated' => false\n"
            "'generation_write_bound' => false\n")
        workflow = "\n".join(TESTS)
        for path in (
            ".github/workflows/adaptive-context-g3-private-file-lifecycle.yml",
            ".github/workflows/adaptive-context-g3-private-file-mysql.yml",
        ):
            self.write(path, workflow)
        for name in TESTS:
            self.write("tests/Feature/" + name, "<?php // synthetic test\n")
        for path, code in SCRIPTS:
            self.write(path, code + "\nreturn 2\n")
            self.write("tests/ci/test_" + Path(path).stem + ".py",
                       "# isolated offline synthetic test\n")
        for path in DOCS:
            self.write(path, "# Candidate evidence only. G3 OPEN\n")
        self.write("docs/development/ADAPTIVE_CONTEXT_PIPELINE_V05_G3_EXIT_AUDIT_2026_10_12.md",
                   "# Audit\nG3 OPEN. Five migrations still unapproved.\n")
        self.commit()
        self.sha = self.git("rev-parse", "HEAD")

    def write(self, path, content):
        file = self.repo / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content, encoding="utf-8")

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", str(self.repo), *args],
            text=True, stderr=subprocess.DEVNULL,
        ).strip()

    def commit(self):
        self.git("add", ".")
        self.git("commit", "-qm", "R31 isolated fixture")

    def check(self, sha=None):
        return evaluate(self.repo, self.sha if sha is None else sha)

    def denied(self, outcome):
        self.assertEqual(set(outcome["local_contracts"]), set(STATES))
        for key in (
            "synthetic_contract_expansion_recommended", "provider_attested",
            "real_replay_verified", "external_writer_fencing_verified",
            "generation_safe_delete_verified", "crash_recovery_verified",
            "owner_go_verified", "production_migrations_approved",
            "g3_exit_accepted", "retry_authorized", "delete_authorized",
            "production_release_authorized", "provider_calls_executed",
            "production_modified",
        ):
            self.assertIs(outcome[key], False, key)
        self.assertEqual(outcome["next_action"],
                         "OWNER_DECISION_DISPOSABLE_TEST_OR_KEEP_ON_HOLD")
        payload = json.dumps(outcome)
        self.assertNotIn(str(self.repo), payload)
        self.assertNotIn("AWS_SECRET", payload)

    def test_complete_local_fixture_still_cannot_close_g3(self):
        before = self.git("status", "--porcelain")
        data = self.check()
        self.assertEqual(data["result"], "HOLD_EXTERNAL_PROOF_REQUIRED")
        self.assertTrue(data["pinned_commit_resolved"])
        self.assertTrue(all(s == "SOURCE_PRESENT_UNATTESTED"
                            for s in data["local_contracts"].values()))
        self.denied(data)
        self.assertEqual(before, self.git("status", "--porcelain"))

    def test_missing_or_wrong_git_commit_denies_every_authority(self):
        for sha in ("", "main", "1" * 39, "f" * 40, "a" * 40 + " --bad"):
            data = self.check(sha)
            self.assertEqual(data["result"], "HOLD_LOCAL_CONTRACT_DRIFT")
            self.assertFalse(data["pinned_commit_resolved"])
            self.denied(data)

    def test_deleted_migration_is_detected_without_migrating_database(self):
        (self.repo / MIGRATIONS[-1]).unlink()
        self.git("add", "-u")
        self.git("commit", "-qm", "delete one additive migration")
        data = self.check(self.git("rev-parse", "HEAD"))
        self.assertEqual(data["local_contracts"]["migration_sources"], "MISSING_OR_DRIFTED")
        self.assertEqual(data["result"], "HOLD_LOCAL_CONTRACT_DRIFT")
        self.denied(data)

    def test_non_default_off_config_drift_blocks(self):
        file = self.repo / "config/canovia.php"
        file.write_text(file.read_text().replace("false)", "true)", 1))
        self.commit()
        data = self.check(self.git("rev-parse", "HEAD"))
        self.assertEqual(data["local_contracts"]["test_only_provider_gates"],
                         "MISSING_OR_DRIFTED")
        self.denied(data)

    def test_verifier_true_attribution_drift_is_detected(self):
        path = "app/Services/PrivateFileUnavailableProviderVerifier.php"
        self.write(path, "final class PrivateFileUnavailableProviderVerifier\n"
                    "'verdict' => 'provider_verified'\n"
                    "'provider_authenticated' => true\n"
                    "'generation_write_bound' => true\n")
        self.commit()
        data = self.check(self.git("rev-parse", "HEAD"))
        self.assertEqual(data["local_contracts"]["independent_verifier_unavailable"],
                         "MISSING_OR_DRIFTED")
        self.denied(data)

    def test_ci_missing_a_negative_suite_fails_closed(self):
        path = ".github/workflows/adaptive-context-g3-private-file-mysql.yml"
        content = (self.repo / path).read_text()
        self.write(path, content.replace(TESTS[-1], "other.php"))
        self.commit()
        data = self.check(self.git("rev-parse", "HEAD"))
        self.assertEqual(data["local_contracts"]["provider_ci_test_references"],
                         "MISSING_OR_DRIFTED")
        self.denied(data)

    def test_offline_approval_script_no_longer_exits_denied_is_drift(self):
        path = SCRIPTS[-1][0]
        self.write(path, SCRIPTS[-1][1] + "\nreturn 0\n")
        self.commit()
        data = self.check(self.git("rev-parse", "HEAD"))
        self.assertEqual(data["local_contracts"]["offline_review_scripts"],
                         "MISSING_OR_DRIFTED")
        self.denied(data)

    def test_missing_readiness_document_or_g3_open_ack_is_drift(self):
        self.write(DOCS[0], "Fake provider approval. G3 CLOSED\n")
        self.commit()
        data = self.check(self.git("rev-parse", "HEAD"))
        self.assertEqual(data["local_contracts"]["offline_review_documents"],
                         "MISSING_OR_DRIFTED")
        self.denied(data)

    def test_git_symlink_is_not_source_evidence(self):
        file = self.repo / MIGRATIONS[0]
        file.unlink()
        file.symlink_to("README.md")
        self.commit()
        data = self.check(self.git("rev-parse", "HEAD"))
        self.assertEqual(data["local_contracts"]["migration_sources"],
                         "MISSING_OR_DRIFTED")
        self.denied(data)

    def test_pinned_old_sha_is_not_mutated_by_changed_worktree(self):
        self.write("app/Services/PrivateFileUnavailableProviderVerifier.php",
                   "unsafe uncommitted text")
        data = self.check(self.sha)
        self.assertEqual(data["result"], "HOLD_EXTERNAL_PROOF_REQUIRED")
        self.denied(data)

    def test_untrusted_source_stubs_never_count_as_actual_provider_attestation(self):
        # R31 checks markers; a forged local Git tree can mirror all markers.
        # Even then, R31 holds until independent real-world evidence exists.
        self.write("docs/development/ADAPTIVE_CONTEXT_PIPELINE_V05_G3_EXIT_AUDIT_2026_10_12.md",
                   "Five source assertions; G3 OPEN; invented claim VERIFIED\n")
        self.commit()
        self.denied(self.check(self.git("rev-parse", "HEAD")))


if __name__ == "__main__":
    unittest.main()
