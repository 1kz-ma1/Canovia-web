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


    def test_second_review_sha_needs_explicit_selection_and_separate_session(self):
        (self.root / "artisan").write_text("<?php // IA-2j snapshot", encoding="utf-8")
        git(self.root, "add", "artisan")
        subprocess.check_call([
            "git", "-C", str(self.root), "-c", "user.name=CI",
            "-c", "user.email=ci@example.invalid", "commit", "-qm", "second snapshot"
        ])
        second_sha = git(self.root, "rev-parse", "HEAD")
        second_lab = Path(self.temp.name) / "ia2j-private-lab"

        with patch.object(lab, "IA2J_SHA", second_sha):
            with self.assertRaises(lab.SafetyError):
                self.args("init", private=second_lab)
            with self.assertRaises(lab.SafetyError):
                self.args("init", private=second_lab, extra=["--source-sha", "f" * 40])
            self.assertFalse(second_lab.exists())

            self.assertEqual(
                self.args("init", private=second_lab, extra=["--source-sha", second_sha]), 0
            )
            self.assertEqual(
                self.args("check", private=second_lab, extra=["--source-sha", second_sha]), 0
            )
            with self.assertRaises(lab.SafetyError):
                self.args("check", private=second_lab)
            self.assertEqual(
                lab.load_lab(second_lab, second_sha)[0]["source_sha"], second_sha
            )
            with self.assertRaises(lab.SafetyError):
                lab.load_lab(second_lab, self.sha)

            (self.root / "vendor").mkdir()
            (self.root / "vendor/autoload.php").touch()
            (self.root / "public/build").mkdir(parents=True)
            (self.root / "public/build/manifest.json").write_text("{}", encoding="utf-8")
            with patch.object(lab.subprocess, "call", return_value=0) as call:
                self.assertEqual(
                    self.args("serve", private=second_lab,
                              extra=["--source-sha", second_sha, "--confirm-disposable"]), 0
                )
            self.assertEqual(
                call.call_args.args[0],
                ["php", "artisan", "serve", "--host=127.0.0.1", "--port=8798"]
            )
            runtime = call.call_args.kwargs["env"]
            self.assertEqual(runtime["SESSION_COOKIE"], "canovia-ia2j-session")
            self.assertEqual(runtime["APP_URL"], "http://127.0.0.1:8798")
            self.assertEqual(runtime["DB_CONNECTION"], "sqlite")
            self.assertEqual(runtime["DB_DATABASE"], str(second_lab / lab.DB_FILENAME))
            self.assertEqual(runtime["NATIVE_AI_DRIVER"], "disabled")
            self.assertNotIn("DATABASE_URL", runtime)
            self.assertNotEqual(runtime["SESSION_COOKIE"], "canovia-ia2i-session")

    def test_v621_requires_exact_sha_and_third_private_lab_without_inherited_secrets(self):
        # First lab remains immutable even as the source worktree advances.
        self.assertEqual(self.args("init"), 0)
        first_state = (self.private / lab.STATE_FILENAME).read_bytes()
        first_db = (self.private / lab.DB_FILENAME).read_bytes()

        (self.root / "artisan").write_text("<?php // V6.21 pinned test snapshot", encoding="utf-8")
        git(self.root, "add", "artisan")
        subprocess.check_call([
            "git", "-C", str(self.root), "-c", "user.name=CI",
            "-c", "user.email=ci@example.invalid", "commit", "-qm", "V621 snapshot",
        ])
        third_sha = git(self.root, "rev-parse", "HEAD")
        third_lab = Path(self.temp.name) / "v621-private-lab"

        with patch.object(lab, "V621_SHA", third_sha):
            with self.assertRaises(lab.SafetyError):
                self.args("init", private=third_lab)
            with self.assertRaises(lab.SafetyError):
                self.args("init", private=third_lab, extra=["--source-sha", "f" * 40])
            self.assertFalse(third_lab.exists())

            self.assertEqual(
                self.args("init", private=third_lab, extra=["--source-sha", third_sha]), 0
            )
            self.assertEqual(
                self.args("check", private=third_lab, extra=["--source-sha", third_sha]), 0
            )
            self.assertEqual(lab.load_lab(third_lab, third_sha)[0]["source_sha"], third_sha)
            with self.assertRaises(lab.SafetyError):
                lab.load_lab(third_lab, self.sha)
            with self.assertRaises(lab.SafetyError):
                lab.load_lab(third_lab, lab.IA2J_SHA)
            with self.assertRaises(lab.SafetyError):
                self.args("check", private=third_lab)
            with self.assertRaises(lab.SafetyError):
                self.args("init", private=third_lab, extra=["--source-sha", third_sha])

            (self.root / "vendor").mkdir()
            (self.root / "vendor/autoload.php").touch()
            (self.root / "public/build").mkdir(parents=True)
            (self.root / "public/build/manifest.json").write_text("{}", encoding="utf-8")

            with patch.dict(os.environ, {
                "DB_URL": "postgres://real.example.invalid/production",
                "DATABASE_URL": "mysql://real.example.invalid/production",
                "DB_CONNECTION": "mysql",
                "GITHUB_APP_PRIVATE_KEY": "do-not-inherit",
                "AWS_SECRET_ACCESS_KEY": "do-not-inherit",
            }), patch.object(lab.subprocess, "call", return_value=0) as call:
                with self.assertRaises(lab.SafetyError):
                    self.args("serve", private=third_lab, extra=["--source-sha", third_sha])
                self.assertEqual(self.args("serve", private=third_lab, extra=[
                    "--source-sha", third_sha, "--confirm-disposable"
                ]), 0)

            self.assertEqual(call.call_args.args[0],
                             ["php", "artisan", "serve", "--host=127.0.0.1", "--port=8799"])
            runtime = call.call_args.kwargs["env"]
            self.assertEqual(runtime["APP_URL"], "http://127.0.0.1:8799")
            self.assertEqual(runtime["SESSION_COOKIE"], "canovia-v621-session")
            self.assertEqual(runtime["DB_CONNECTION"], "sqlite")
            self.assertEqual(runtime["DB_DATABASE"], str(third_lab / lab.DB_FILENAME))
            self.assertEqual(runtime["NATIVE_AI_DRIVER"], "disabled")
            self.assertEqual(runtime["CANOVIA_PUBLIC_RELEASE_LEVEL"], "1")
            self.assertNotIn("DATABASE_URL", runtime)
            self.assertNotIn("DB_URL", runtime)
            self.assertEqual(runtime["GITHUB_APP_PRIVATE_KEY"], "")
            self.assertEqual(runtime["AWS_SECRET_ACCESS_KEY"], "")
            self.assertEqual((third_lab.stat().st_mode & 0o077), 0)
            self.assertEqual((third_lab / lab.STATE_FILENAME).stat().st_mode & 0o077, 0)
            self.assertEqual((third_lab / lab.DB_FILENAME).stat().st_mode & 0o077, 0)
            self.assertEqual(
                len({lab.lab_profile(lab.PINNED_SHA)[0],
                     lab.lab_profile(lab.IA2J_SHA)[0],
                     lab.lab_profile(third_sha)[0]}), 3
            )
            self.assertEqual(
                len({lab.lab_profile(lab.PINNED_SHA)[1],
                     lab.lab_profile(lab.IA2J_SHA)[1],
                     lab.lab_profile(third_sha)[1]}), 3
            )

        self.assertEqual((self.private / lab.STATE_FILENAME).read_bytes(), first_state)
        self.assertEqual((self.private / lab.DB_FILENAME).read_bytes(), first_db)

    def test_ia2h_defaults_remain_unchanged(self):
        self.args("init")
        payload, db = lab.load_lab(self.private)
        runtime = lab.isolated_env(payload, db)
        self.assertEqual(runtime["APP_URL"], lab.BASE_URL)
        self.assertEqual(runtime["SESSION_COOKIE"], "canovia-ia2i-session")
        self.assertEqual(payload["source_sha"], self.sha)


if __name__ == "__main__":
    unittest.main()
