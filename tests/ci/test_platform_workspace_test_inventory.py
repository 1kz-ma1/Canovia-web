"""Pure PHP Unit consolidation and read-only inventory regression tests."""
from pathlib import Path
import json
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.ops.platform_workspace_test_inventory import inventory

WORKFLOW = ROOT / ".github/workflows/v56-12-workspace-resume.yml"
FILES = (
    "tests/Unit/DevelopmentAgentGitHubPullHandoffTest.php",
    "tests/Unit/DevelopmentRoadmapRevisionDifferTest.php",
)


class WorkspaceTestInventoryTests(unittest.TestCase):
    def test_unchanged_coverage_file_invocation_baseline(self):
        data = inventory(WORKFLOW.read_text(encoding="utf-8"))
        self.assertEqual(data["result"], "REVIEW_REQUIRED")
        self.assertEqual(data["artisan_test_process_invocations"], 72)
        self.assertEqual(data["explicit_test_file_invocations"], 160)
        self.assertEqual(data["unique_test_file_paths"], 118)
        self.assertEqual(data["repeated_test_file_invocations"], 42)
        self.assertFalse(data["permission_to_remove_repeats"])
        self.assertFalse(data["release_authorized"])

    def test_exact_two_pure_unit_classes_grouped_once(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        grouped = "run: php artisan test " + " ".join(FILES)
        self.assertEqual(source.count(grouped), 1)
        for file in FILES:
            self.assertEqual(source.count("tests/Unit/" + Path(file).name), 1)

    def test_grouped_files_are_pure_phpunit_without_laravel_database(self):
        for path in FILES:
            source = (ROOT / path).read_text(encoding="utf-8")
            with self.subTest(file=path):
                self.assertIn("use PHPUnit\\Framework\\TestCase;", source)
                self.assertIn("extends TestCase", source)
                self.assertNotIn("use Tests\\TestCase;", source)
                self.assertNotIn("RefreshDatabase", source)
                self.assertNotIn("DatabaseTransactions", source)
                self.assertNotIn("Illuminate\\Foundation\\Testing", source)

    def test_original_sensitive_feature_suites_still_run_separately(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        for label in (
            "MCP explicit Plan consent, fresh OAuth and scope isolation",
            "MCP session-only revocation, audit and ownership isolation",
            "L5 Verified 2026 AP A CBT exam readiness and full synthetic 80-question freeze",
            "V58.25 Personalization Bootstrap",
        ):
            with self.subTest(label=label):
                self.assertIn("      - name: " + label, source)
        self.assertIn("php artisan migrate --force", source)
        self.assertIn("node --test tests/js/learning-answer-autosave.test.mjs", source)

    def test_invalid_test_argv_fails_closed(self):
        with self.assertRaises(ValueError):
            inventory("      - name: Bad\n        run: php artisan test --parallel tests/Feature/A.php")
        with self.assertRaises(ValueError):
            inventory("      - name: Bad\n        run: php artisan test tests/Feature/A.php && rm -rf /")
        with self.assertRaises(ValueError):
            inventory("      - name: Bad\n        run: php artisan test ../../private.php")

    def test_extra_unsafe_log_name_never_used_as_path(self):
        data = inventory("      - name: Ignore <script>secret</script>\n"
                         "        run: php artisan test tests/Unit/RealTest.php")
        self.assertNotIn("<script>", json.dumps(data))

    def test_cli_prints_restricted_aggregate_only(self):
        script = ROOT / "scripts/ops/platform_workspace_test_inventory.py"
        result = subprocess.run([sys.executable, str(script)],
                                capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        data = json.loads(result.stdout)
        self.assertEqual(data["artisan_test_process_invocations"], 72)
        self.assertFalse(data["permission_to_group_feature_tests"])


if __name__ == "__main__":
    unittest.main()
