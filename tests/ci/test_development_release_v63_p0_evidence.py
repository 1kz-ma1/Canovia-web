"""V6.3 exact-candidate release P0 evidence inventory, not release authorization."""
from copy import deepcopy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.development_release_v63_p0_evidence import (
    MANIFEST, EXPECTED_SHA, InvalidEvidence,
    decision, load_manifest, verify_candidate_source,
)

ROOT = Path(__file__).resolve().parents[2]


class V63P0EvidenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = load_manifest(MANIFEST.read_bytes())

    def test_all_eight_gates_are_unique_scoped_and_cannot_claim_runtime_go(self):
        d = self.data
        result = decision(d, False)
        self.assertEqual(513, result["release_pr"])
        self.assertEqual(EXPECTED_SHA, result["candidate_sha"])
        self.assertEqual(8, result["gates"])
        self.assertEqual(18, result["referenced_test_methods"])
        self.assertEqual(2, result["critical_flow_e2e_gaps"])
        self.assertFalse(result["source_methods_revalidated_at_exact_git_sha"])
        self.assertFalse(result["whole_onboarding_to_replan_e2e_verified"])
        self.assertFalse(result["release_authorized"])
        self.assertEqual("BLOCKED_NEEDS_P0_E2E", result["selection"])
        self.assertEqual("BLOCK", result["result"])
        self.assertTrue(all(g["runtime_evidence"] == "UNVERIFIED" for g in d["gates"]))

    def test_refuses_fake_approval_false_candidate_or_dropped_gate(self):
        cases = [
            lambda d: d.update(release_authorized=True),
            lambda d: d.update(all_p0_end_to_end_verified=True),
            lambda d: d.update(live_aiven_schema_verified=True),
            lambda d: d.update(physical_iphone_verified=True),
            lambda d: d.update(selection_status="SHORTLISTED"),
            lambda d: d.update(candidate_sha="0"*40),
            lambda d: d.update(release_pr=999),
            lambda d: d.update(release_pr=True),
            lambda d: d["gates"].pop(),
            lambda d: d["gates"][0].update(id="P0-08"),
            lambda d: d["gates"][0].update(runtime_evidence="PASS"),
            lambda d: d["gates"][1].update(status="VERIFIED"),
            lambda d: d["gates"][2]["source_tests"][0].update(path="../.env"),
            lambda d: d["gates"][2]["source_tests"][0].update(method="test_good; rm -rf /"),
            lambda d: d["gates"][2].update(source_tests=[]),
            lambda d: d["gates"][2].update(status=[]),
            lambda d: d["gates"][2].update(ci_evidence=[]),
        ]
        for i, mutate in enumerate(cases):
            with self.subTest(case=i):
                d = deepcopy(self.data)
                mutate(d)
                with self.assertRaises(InvalidEvidence):
                    load_manifest(json.dumps(d).encode())
        for raw in (b"", b"{}", b"secret=pass", MANIFEST.read_bytes()+b"x"*70000):
            with self.assertRaises(InvalidEvidence):
                load_manifest(raw)

    def test_can_revalidate_declared_test_methods_only_for_exact_git_checkout(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            subprocess.run(["git", "init", "-q"], cwd=root, check=True)
            d=deepcopy(self.data)
            for gate in d["gates"]:
                for item in gate["source_tests"]:
                    path=root/item["path"]
                    path.parent.mkdir(parents=True, exist_ok=True)
                    with path.open("a", encoding="utf-8") as handle:
                        handle.write("<?php function "+item["method"]+"() {}\n")
            subprocess.run(["git", "add", "tests"], cwd=root, check=True)
            subprocess.run(["git", "-c", "user.name=Fixture", "-c",
                            "user.email=v63@example.invalid", "commit", "-qm", "fixture"],
                           cwd=root, check=True)
            head=subprocess.run(["git", "rev-parse", "HEAD"], cwd=root,
                                check=True, text=True, capture_output=True).stdout.strip()
            d["candidate_sha"]=head
            self.assertTrue(verify_candidate_source(root, d))
            self.assertFalse(verify_candidate_source(root, self.data))
            first=d["gates"][0]["source_tests"][0]
            (root/first["path"]).write_text("<?php // missing test\n")
            self.assertFalse(verify_candidate_source(root, d))

    def test_cli_invalid_manifest_does_not_echo_untrusted_content(self):
        script=ROOT/"scripts/ci/development_release_v63_p0_evidence.py"
        clean=subprocess.run([sys.executable, str(script)], cwd=ROOT,
                             text=True, capture_output=True)
        self.assertEqual(0, clean.returncode, clean.stderr)
        result=json.loads(clean.stdout)
        self.assertEqual("BLOCK", result["result"])
        self.assertFalse(result["source_methods_revalidated_at_exact_git_sha"])
        with tempfile.TemporaryDirectory() as tmp:
            bad=Path(tmp)/"secret.json"
            bad.write_text('{"api_key":"never-echo-this-secret"}')
            p=subprocess.run([sys.executable, str(script), "--manifest", str(bad)],
                             cwd=ROOT, text=True, capture_output=True)
            self.assertEqual(2, p.returncode)
            self.assertEqual("BLOCK", json.loads(p.stdout)["result"])
            self.assertNotIn("never-echo-this-secret", p.stdout+p.stderr)

    def test_no_live_connector_or_release_actions(self):
        code=(ROOT/"scripts/ci/development_release_v63_p0_evidence.py").read_text()
        for phrase in ("git push", "git fetch", "requests.get(", "requests.post(",
                       "urllib.request", "migrate --force", "render deploy",
                       "pymysql", "mysql.connector"):
            self.assertNotIn(phrase, code)


if __name__ == "__main__":
    unittest.main()
