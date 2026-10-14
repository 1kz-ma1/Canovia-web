"""Disposable fixtures verify no-jobs YAML syntax regressions without network."""
from pathlib import Path
import subprocess
import tempfile
import unittest


class WorkflowYamlSyntaxTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / "fixture.yml"
        self.script = Path(__file__).resolve().parents[2] / "scripts/ops/platform_workflow_yaml_check.rb"

    def run_check(self, *paths):
        return subprocess.run(["ruby", str(self.script), *map(str, paths)], capture_output=True, text=True)

    def test_valid_workflow(self):
        self.path.write_text('name: valid\non:\n  pull_request: {}\njobs:\n  demo:\n    runs-on: ubuntu-latest\n')
        p = self.run_check(self.path)
        self.assertEqual(p.returncode, 0)
        self.assertIn('workflow_yaml_syntax_pass', p.stdout)

    def test_unquoted_colon_fails_without_leaking_content(self):
        secret = 'SENSITIVE_TEST_MARKER_DO_NOT_PRINT'
        self.path.write_text('jobs:\n  unit:\n    steps:\n      - name: Independent sessions: replay ' + secret + '\n')
        p = self.run_check(self.path)
        self.assertEqual(p.returncode, 2)
        self.assertIn('workflow_yaml_syntax_error', p.stderr)
        self.assertNotIn(secret, p.stdout + p.stderr)

    def test_quoted_colon_passes(self):
        self.path.write_text('jobs:\n  unit:\n    steps:\n      - name: "Independent sessions: replay"\n')
        self.assertEqual(self.run_check(self.path).returncode, 0)

    def test_empty_file_fails(self):
        self.path.write_text('')
        self.assertEqual(self.run_check(self.path).returncode, 2)

    def test_absent_file_fails(self):
        self.assertEqual(self.run_check(self.path).returncode, 2)

    def test_missing_argument_fails(self):
        self.assertEqual(self.run_check().returncode, 2)

    def test_symlink_fails(self):
        source = Path(self.tmp.name) / 'source.yml'
        source.write_text('name: valid\n')
        self.path.symlink_to(source)
        self.assertEqual(self.run_check(self.path).returncode, 2)


if __name__ == '__main__':
    unittest.main()
