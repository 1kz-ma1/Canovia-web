"""Offline R23 V5872 gate and exact source drift contracts."""
from __future__ import annotations
import json
from pathlib import Path
import subprocess
import sys
import unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT))
from scripts.ops.platform_workspace_r23_v5872_scope import (
    TARGET,METHODS,WORKFLOW,IncompleteEvidence,review,
)
SOURCE=(ROOT/TARGET).read_text(encoding="utf-8")
MANIFEST=WORKFLOW.read_text(encoding="utf-8")
class R23ScopeTests(unittest.TestCase):
    def test_real_four_contexts_held(self):
        r=review(MANIFEST,SOURCE)
        self.assertEqual(r["result"],"HOLD_V5872_DOMAIN_REVIEW_REQUIRED")
        self.assertEqual(r["invocations"],4)
        self.assertEqual(r["additional_invocations"],3)
        self.assertEqual(r["source_observed_method_count"],6)
        self.assertEqual(r["source_observed_method_names"],list(METHODS))
        self.assertEqual(len({tuple(c["companions"]) for c in r["contexts"]}),4)
        self.assertFalse(r["safe_to_remove"])
        self.assertFalse(r["permission_to_remove_repeated_invocations"])
        self.assertFalse(r["production_release_authorized"])
        for c in r["contexts"]:
            self.assertFalse(c["step_specific_semantics_verified"])
            self.assertFalse(c["cross_context_db_state_equivalence_proven"])
            self.assertFalse(c["safe_to_omit"])
    def test_method_drift_fails_closed(self):
        with self.assertRaises(IncompleteEvidence):
            review(MANIFEST,SOURCE.replace("public function "+METHODS[0],"public function changed_method",1))
    def test_missing_refresh_database_signal_blocks(self):
        with self.assertRaises(IncompleteEvidence):
            review(MANIFEST,SOURCE.replace("use RefreshDatabase;","",1))
    def test_real_command_deletion_blocks(self):
        line=next(x for x in MANIFEST.splitlines()
                  if x.startswith("        run: php artisan test ") and
                  "PlanActionDraftV5872Test.php" in x)
        changed=MANIFEST.replace(line,line.replace(" tests/Feature/PlanActionDraftV5872Test.php",""),1)
        self.assertNotEqual(MANIFEST,changed)
        with self.assertRaises(IncompleteEvidence):
            review(changed,SOURCE)
    def test_different_source_is_not_valid(self):
        with self.assertRaises(IncompleteEvidence):
            review(MANIFEST,"<?php class Placeholder {}")
    def test_default_cli_is_readonly_hold(self):
        p=subprocess.run([sys.executable,str(ROOT/"scripts/ops/platform_workspace_r23_v5872_scope.py")],
                         cwd=ROOT,capture_output=True,text=True,timeout=25)
        self.assertEqual(p.returncode,0,p.stderr)
        result=json.loads(p.stdout)
        self.assertFalse(result["permission_to_remove_repeated_invocations"])
        self.assertFalse(result["production_release_authorized"])
if __name__=="__main__":
    unittest.main()
