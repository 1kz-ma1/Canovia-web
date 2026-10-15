"""Offline safety regression for the owner-invoked IA-2i Mac lab."""
import importlib.util
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout, redirect_stderr
from unittest.mock import patch

SOURCE = Path(__file__).with_name("prelaunch_ia2i_local_lab.py")
SPEC = importlib.util.spec_from_file_location("ia2i_local_lab", SOURCE)
assert SPEC and SPEC.loader
lab = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(lab)


def git(root, *args):
    return subprocess.check_output(["git", "-C", str(root), *args], text=True).strip()


class LocalLabSecurityTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        parent = Path(self.temp.name)
        self.root = parent / "frozen-source"
        self.root.mkdir()
        git(self.root, "init", "-q")
        (self.root / "artisan").write_text("<?php", encoding="utf-8")
        (self.root / "bootstrap/cache").mkdir(parents=True)
        git(self.root, "add", "artisan")
        subprocess.check_call([
            "git", "-C", str(self.root), "-c", "user.name=CI",
            "-c", "user.email=ci@example.invalid", "commit", "-qm", "snapshot"
        ])
        self.sha = git(self.root, "rev-parse", "HEAD")
        self.snapshot = patch.object(lab, "PINNED_SHA", self.sha)
        self.snapshot.start()
        self.addCleanup(self.snapshot.stop)
        self.private = parent / "private-lab"

    def args(self, action, *, checkout=None, private=None, extra=None):
        args = ["script", action, "--checkout", str(checkout or self.root),
                "--lab-dir", str(private or self.private), *(extra or [])]
        with patch.object(sys, "argv", args), redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            return lab.main()

    def test_init_is_local_secret_safe_and_refuses_reinit(self):
        self.assertEqual(self.args("init"), 0)
        self.assertTrue((self.private / lab.STATE_FILENAME).is_file())
        self.assertTrue((self.private / lab.DB_FILENAME).is_file())
        self.assertEqual((self.private.stat().st_mode & 0o077), 0)
        self.assertEqual((self.private / lab.STATE_FILENAME).stat().st_mode & 0o077, 0)
        self.assertEqual(self.args("check"), 0)
        with self.assertRaises(lab.SafetyError):
            self.args("init")

    def test_refuses_stale_sha_tracked_modification_and_env(self):
        with patch.object(lab, "PINNED_SHA", "0"*40):
            with self.assertRaises(lab.SafetyError):
                lab.validate_checkout(str(self.root))
        (self.root / "artisan").write_text("changed", encoding="utf-8")
        with self.assertRaises(lab.SafetyError):
            lab.validate_checkout(str(self.root))
        (self.root / "artisan").write_text("<?php", encoding="utf-8")
        for name in (".env", ".env.local", ".env.production"):
            path = self.root / name
            path.write_text("DB_CONNECTION=mysql", encoding="utf-8")
            with self.assertRaises(lab.SafetyError):
                lab.validate_checkout(str(self.root))
            path.unlink()
        (self.root / "bootstrap/cache/config.php").write_text("cached", encoding="utf-8")
        with self.assertRaises(lab.SafetyError):
            lab.validate_checkout(str(self.root))

    def test_refuses_lab_inside_checkout_or_symlink(self):
        with self.assertRaises(lab.SafetyError):
            lab.validate_lab(str(self.root / "lab"), self.root, create=True)
        self.private.mkdir()
        shortcut = Path(self.temp.name) / "symlinked-lab"
        shortcut.symlink_to(self.private)
        with self.assertRaises(lab.SafetyError):
            lab.validate_lab(str(shortcut), self.root, create=True)

    def test_migrate_uses_only_isolated_sqlite_no_inherited_secrets(self):
        self.args("init")
        (self.root / "vendor").mkdir()
        (self.root / "vendor/autoload.php").touch()
        with patch.dict(os.environ, {
            "DATABASE_URL": "mysql://private.example.invalid/production",
            "DB_CONNECTION": "mysql",
            "AWS_SECRET_ACCESS_KEY": "do-not-pass",
            "GITHUB_APP_PRIVATE_KEY": "do-not-pass",
        }), patch.object(lab.subprocess, "call", return_value=0) as call:
            with self.assertRaises(lab.SafetyError):
                self.args("migrate")
            self.assertEqual(self.args("migrate", extra=["--confirm-disposable"]), 0)
        argv = call.call_args.args[0]
        runtime = call.call_args.kwargs["env"]
        self.assertEqual(argv, ["php", "artisan", "migrate", "--force", "--no-interaction"])
        self.assertEqual(runtime["DB_CONNECTION"], "sqlite")
        self.assertEqual(runtime["DB_DATABASE"], str(self.private / lab.DB_FILENAME))
        self.assertEqual(runtime["APP_ENV"], "local")
        self.assertEqual(runtime["CANOVIA_PUBLIC_RELEASE_LEVEL"], "1")
        self.assertEqual(runtime["NATIVE_AI_DRIVER"], "disabled")
        self.assertNotIn("DATABASE_URL", runtime)
        self.assertEqual(runtime["AWS_SECRET_ACCESS_KEY"], "")
        self.assertEqual(runtime["GITHUB_APP_PRIVATE_KEY"], "")

    def test_serve_requires_build_and_loopback(self):
        self.args("init")
        (self.root / "vendor").mkdir()
        (self.root / "vendor/autoload.php").touch()
        with self.assertRaises(lab.SafetyError):
            self.args("serve", extra=["--confirm-disposable"])
        (self.root / "public/build").mkdir(parents=True)
        (self.root / "public/build/manifest.json").write_text("{}", encoding="utf-8")
        with patch.object(lab.subprocess, "call", return_value=0) as call:
            self.assertEqual(self.args("serve", extra=["--confirm-disposable"]), 0)
        self.assertEqual(call.call_args.args[0],
                         ["php", "artisan", "serve", "--host=127.0.0.1", "--port=8797"])
        self.assertEqual(call.call_args.kwargs["env"]["APP_URL"], lab.BASE_URL)


if __name__ == "__main__":
    unittest.main()
