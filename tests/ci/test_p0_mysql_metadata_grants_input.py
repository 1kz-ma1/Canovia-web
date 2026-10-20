"""Offline CLI/file boundary tests. All identifiers and contents are synthetic."""
import contextlib
import io
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

from scripts.ci import p0_mysql_metadata_grants_guard as guard

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ci/p0_mysql_metadata_grants_guard.py"
CANARY = "SYNTHETIC_PRIVATE_CANARY"
ROWS = ("GRANT REFERENCES ON `synthetic_ci`.* TO `inspector_ci`@`%`\n"
        "GRANT SELECT ON `synthetic_ci`.`migrations` TO `inspector_ci`@`%`\n")
BLOCK = (2, "", "P0_METADATA_GRANTS: BLOCK\n")
ACCEPT = (0, "P0_METADATA_GRANTS: ACCEPT_METADATA_ONLY_LEDGER_SELECT\n", "")


class InputSafetyTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.path = self.root / CANARY
        self.path.write_text(ROWS, encoding="ascii")
        self.args = ["--database", "synthetic_ci", "--input", str(self.path)]

    def cli(self, args):
        result = subprocess.run([sys.executable, "-I", "-B", str(SCRIPT)] + args,
                                capture_output=True, text=True, timeout=3)
        output = result.stdout + result.stderr
        self.assertNotIn(CANARY, output)
        self.assertNotIn(str(self.root), output)
        self.assertNotIn("Traceback", output)
        self.assertNotIn("inspector_ci", output)
        return result.returncode, result.stdout, result.stderr

    def test_regular_file_and_both_quote_forms_keep_existing_verdict(self):
        self.assertEqual(ACCEPT, self.cli(self.args))
        self.path.write_text(ROWS.replace("`", '"'), encoding="ascii")
        self.assertEqual(ACCEPT, self.cli(self.args))

    def test_unknown_missing_duplicate_and_abbreviated_arguments_are_private(self):
        cases = [[], self.args + ["--unexpected", CANARY],
                 ["--database", CANARY], self.args + ["--input", CANARY],
                 self.args + ["--database", CANARY], ["--help", CANARY],
                 ["--dat", "synthetic_ci", "--input", str(self.path)],
                 ["--database", "synthetic_ci", "--inp", str(self.path)],
                 self.args + [CANARY]]
        for args in cases:
            with self.subTest(case=cases.index(args)):
                self.assertEqual(BLOCK, self.cli(args))

    def test_missing_unreadable_and_invalid_database_do_not_echo_paths(self):
        self.path.unlink()
        self.assertEqual(BLOCK, self.cli(self.args))
        self.assertEqual(BLOCK, self.cli(["--database", CANARY + ";",
                                         "--input", str(self.path)]))
        with patch.object(guard.os, "open", side_effect=PermissionError(CANARY)):
            self.assertEqual(BLOCK, self.in_process())

    def test_non_ascii_nul_cr_and_missing_newline_fail_closed(self):
        for raw in (b"\xff", ROWS.encode() + b"\0", ROWS.replace("\n", "\r\n").encode(),
                    ROWS.rstrip().encode(), b""):
            self.path.write_bytes(raw)
            self.assertEqual(BLOCK, self.cli(self.args))

    def test_oversized_sparse_file_is_rejected_before_read(self):
        with self.path.open("wb") as stream:
            stream.truncate(1024 ** 3)
        self.assertEqual(BLOCK, self.cli(self.args))

    def test_file_growth_after_stat_is_still_bounded(self):
        self.path.write_bytes(b"x" * (guard.MAX_BYTES + 1))
        stale = types.SimpleNamespace(st_mode=stat.S_IFREG, st_size=1)
        with patch.object(guard.os, "fstat", return_value=stale):
            self.assertEqual(BLOCK, self.in_process())

    def test_directory_symlink_and_fifo_are_rejected_without_waiting(self):
        self.path.unlink()
        self.path.mkdir()
        self.assertEqual(BLOCK, self.cli(self.args))
        self.path.rmdir()
        target = self.root / "regular"
        target.write_text(ROWS)
        self.path.symlink_to(target)
        self.assertEqual(BLOCK, self.cli(self.args))
        self.path.unlink()
        os.mkfifo(self.path, 0o600)
        # No writer: a blocking open would hang until the test timeout.
        self.assertEqual(BLOCK, self.cli(self.args))

    def test_special_device_and_unsupported_platform_fail_closed(self):
        self.assertEqual(BLOCK, self.cli(["--database", "synthetic_ci", "--input", "/dev/null"]))
        # Explicit absence is checked without attempting an open.
        with patch.object(guard, "hasattr", return_value=False, create=True), \
             patch.object(guard.os, "open") as opened:
            self.assertEqual(BLOCK, self.in_process())
            opened.assert_not_called()

    def test_invalid_arguments_do_not_open_files_spawn_or_connect(self):
        with patch.object(guard.os, "open") as opened, \
             patch("socket.socket") as socket, patch("subprocess.Popen") as child:
            self.assertEqual(BLOCK, self.in_process(self.args + [CANARY]))
            opened.assert_not_called()
            socket.assert_not_called()
            child.assert_not_called()

    def test_interrupt_and_private_os_errors_have_only_fixed_output(self):
        for error in (KeyboardInterrupt(), OSError(CANARY), UnicodeError(CANARY)):
            with patch.object(guard, "read_grants", side_effect=error):
                self.assertEqual(BLOCK, self.in_process())

    def test_missing_or_expanded_privileges_do_not_change_minimum(self):
        for rows in (ROWS.splitlines(keepends=True)[0],
                     ROWS + "GRANT TRIGGER ON `synthetic_ci`.* TO `inspector_ci`@`%`\n",
                     ROWS + "GRANT SELECT ON `synthetic_ci`.* TO `inspector_ci`@`%`\n",
                     ROWS + "GRANT `role_ci`@`%` TO `inspector_ci`@`%`\n"):
            self.path.write_text(rows)
            self.assertEqual(BLOCK, self.cli(self.args))

    def in_process(self, args=None):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = guard.main(self.args if args is None else args)
        return code, out.getvalue(), err.getvalue()


if __name__ == "__main__":
    unittest.main()
