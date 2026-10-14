"""Offline contract: bounded parallel PHP lint preserves every original input."""
from collections import Counter
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/v56-12-workspace-resume.yml"
BASELINE = ROOT / "tests/ci/fixtures/workspace_php_lint_expected.txt"
MARKER = "cat <<'CANOVIA_PHP_LINT_FILES' | xargs -r -P 4 -n 1 php -l"


def extract():
    lines = WORKFLOW.read_text(encoding="utf-8").splitlines()
    assert lines.count("      - name: PHP lint") == 1
    start = lines.index("      - name: PHP lint")
    assert lines[start + 1:start + 3] == ["        shell: bash", "        run: |"]
    body = []
    for line in lines[start + 3:]:
        if re.match(r"^      - (?:name:|run:|uses:)", line):
            break
        assert not line or line.startswith("          ")
        body.append(line[10:] if line else "")
    assert body.count(MARKER) == 1 and body.count("CANOVIA_PHP_LINT_FILES") == 1
    first, last = body.index(MARKER), body.index("CANOVIA_PHP_LINT_FILES")
    assert first < last
    return "\n".join(body) + "\n", body[first + 1:last], body[last + 1:]


class ParallelLintTests(unittest.TestCase):
    def expected(self):
        return BASELINE.read_text(encoding="utf-8").splitlines()

    def test_exact_197_php_inputs_in_original_order(self):
        _, paths, _ = extract()
        self.assertEqual(len(paths), 197)
        self.assertEqual(paths, self.expected())
        self.assertEqual(len(set(paths)), 194)

    def test_all_paths_safe_relative_existing_php(self):
        for entry in self.expected():
            with self.subTest(path=entry):
                self.assertRegex(entry, r"^(?:app|config|database|routes|tests)/[A-Za-z0-9_./-]+\.php$")
                self.assertNotIn("..", entry)
                self.assertTrue((ROOT / entry).is_file())

    def test_original_shell_syntax_checks_remain(self):
        _, _, tail = extract()
        self.assertEqual(tail[:2], ["sh -n docker/mcp-staging-start.sh",
                                    "sh -n docker/render-start.sh"])

    def run_mock_php(self, fail):
        script, _, _ = extract()
        with tempfile.TemporaryDirectory() as tmp:
            mock = Path(tmp) / "php"
            mock.write_text(
                "#!/usr/bin/env bash\n"
                "printf '%s\\n' \"$2\" >> \"$LINT_CALL_LOG\"\n"
                "if [[ \"$2\" == \"$FAIL_PHP_FILE\" ]]; then exit 7; fi\n"
                "exit 0\n", encoding="utf-8",
            )
            mock.chmod(0o755)
            env = os.environ.copy()
            env["PATH"] = tmp + os.pathsep + env.get("PATH", "")
            env["LINT_CALL_LOG"] = str(Path(tmp) / "calls.log")
            env["FAIL_PHP_FILE"] = fail
            result = subprocess.run(["bash", "-e", "-c", script], cwd=ROOT, env=env,
                                    capture_output=True, text=True, timeout=25)
            observed = (Path(tmp) / "calls.log").read_text(encoding="utf-8").splitlines()
            return result.returncode, Counter(observed)

    def test_fail_closed_if_one_php_lint_fails(self):
        script, _, _ = extract()
        self.assertIn("set -euo pipefail", script)
        self.assertIn("xargs -r -P 4 -n 1 php -l", script)
        rc, actual = self.run_mock_php(self.expected()[0])
        self.assertNotEqual(rc, 0)
        self.assertEqual(actual, Counter(self.expected()))

    def test_all_original_php_lints_execute_when_successful(self):
        rc, actual = self.run_mock_php("NEVER_MATCH")
        self.assertEqual(rc, 0)
        self.assertEqual(actual, Counter(self.expected()))

    def test_critical_existing_regression_steps_preserved(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        for needle in ("php artisan migrate --force",
                       "MCP explicit Plan consent, fresh OAuth and scope isolation",
                       "V58.25 Personalization Bootstrap",
                       "Release #488 undeployed MCP/Auth0",
                       "node --test tests/js/learning-answer-autosave.test.mjs"):
            with self.subTest(step=needle):
                self.assertIn(needle, source)


if __name__ == "__main__":
    unittest.main()
