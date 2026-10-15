"""Synthetic negative controls for offline, pinned Mac audit staging."""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ops/prelaunch_pinned_mysql19_stage_local.sh"


def stage(*args: str, env: dict | None = None) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        capture_output=True, text=True, timeout=10,
        env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
             "HOME": os.environ.get("HOME", "/nonexistent"),
             **(env or {})},
    )


class PinnedMacStageTest(unittest.TestCase):
    def test_no_implicit_destination_or_relative_paths(self):
        for args in [(), ("--destination",), ("--destination", "relative"),
                     ("--destination", "./relative"),
                     ("--destination", "/tmp/../untrusted")]:
            with self.subTest(args=args):
                p = stage(*args)
                self.assertEqual(2, p.returncode)
                self.assertIn("PINNED_MYSQL19_STAGE: BLOCK", p.stderr)
                self.assertNotIn("PRODUCTION_DATABASE_QUERIED: true", p.stdout)

    def test_never_overwrite_existing_directory_or_symlink(self):
        with TemporaryDirectory() as folder:
            root = Path(folder)
            dest = root / "existing"
            dest.mkdir()
            sentinel = dest / "should-remain"
            sentinel.write_text("unchanged")
            p = stage("--destination", str(dest))
            self.assertEqual(2, p.returncode)
            self.assertIn("DESTINATION_ALREADY_EXISTS", p.stderr)
            self.assertEqual("unchanged", sentinel.read_text())
            link = root / "alias"
            link.symlink_to(dest, target_is_directory=True)
            p = stage("--destination", str(link))
            self.assertEqual(2, p.returncode)
            self.assertTrue(link.is_symlink())
            self.assertEqual("unchanged", sentinel.read_text())

    def test_ci_is_blocked_by_default(self):
        with TemporaryDirectory() as folder:
            p = stage("--destination", str(Path(folder) / "staged"), env={"CI": "true"})
            self.assertEqual(2, p.returncode)
            self.assertIn("INTERACTIVE_OPERATOR_MACHINE_REQUIRED", p.stderr)
            self.assertFalse((Path(folder) / "staged").exists())

    def test_dest_inside_repository_rejected(self):
        dest = ROOT / "__ci_should_not_appear_pinned_stage__"
        p = stage("--destination", str(dest))
        self.assertEqual(2, p.returncode)
        self.assertIn("DESTINATION_INSIDE_SOURCE_REPO", p.stderr)
        self.assertFalse(dest.exists())

    def test_pinned_shas_and_no_automatic_remote_fetch_or_mysql(self):
        s = SCRIPT.read_text()
        for sha in ["15abdc9de6f24b5250fb255c579d1aa55facaf1a",
                    "6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6",
                    "e04c646c78e021268801cacb1d8ef1b0ad276101"]:
            self.assertIn(sha, s)
        self.assertIn("clone --quiet --local --no-hardlinks --no-checkout", s)
        self.assertIn("--preflight-offline", s)
        self.assertNotIn("git fetch", s)
        self.assertNotIn("git pull", s)
        self.assertNotIn("artisan migrate", s)
        self.assertNotIn("mysql -", s)
        self.assertNotIn("curl ", s)
        self.assertNotIn("wget ", s)
        self.assertNotIn("releaseAuthorized: true", s)

    def test_shell_syntax(self):
        result = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
