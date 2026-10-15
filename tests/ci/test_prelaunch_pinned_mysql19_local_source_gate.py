"""Purely local regression tests of source integrity before an Aiven read."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest

from scripts.ci.prelaunch_pinned_mysql19_local_source_gate import (
    AUDIT_FILES, SourceRejected, checked_audit_tools,
    checked_pinned_migrations, checked_repo, verify_sources,
)

ROOT = Path(__file__).resolve().parents[2]


def git(repo: Path, *args: str) -> str:
    p = subprocess.run(
        ["git", "-C", str(repo), *args],
        capture_output=True, text=True, check=True,
    )
    return p.stdout.strip()


def init_git(root: Path) -> None:
    root.mkdir(parents=True, exist_ok=True)
    git(root, "init", "-q")
    git(root, "config", "user.email", "ci-synthetic@example.invalid")
    git(root, "config", "user.name", "CI synthetic")
    git(root, "config", "commit.gpgsign", "false")


def commit_all(root: Path) -> str:
    git(root, "add", "-A")
    git(root, "commit", "-q", "-m", "synthetic fixture")
    return git(root, "rev-parse", "HEAD")


class PinnedAivenLocalIntegrityTest(unittest.TestCase):
    def test_clean_tracked_audit_files_required(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            init_git(root)
            for relative in AUDIT_FILES:
                p = root / relative
                p.parent.mkdir(parents=True, exist_ok=True)
                p.write_text("-- synthetic tracked source\n")
            commit_all(root)
            checked_audit_tools(root)
            # Any accidental operator edit to a SQL/CI/helper file blocks.
            for relative in AUDIT_FILES:
                with self.subTest(relative=relative):
                    target = root / relative
                    target.write_text("-- unreviewed local edit\n")
                    with self.assertRaises(SourceRejected):
                        checked_audit_tools(root)
                    git(root, "restore", "--", relative)
                    checked_audit_tools(root)

    def test_staged_modification_and_untracked_source_block(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            init_git(root)
            for relative in AUDIT_FILES:
                p = root / relative
                p.parent.mkdir(parents=True, exist_ok=True)
                p.write_text("tracked")
            commit_all(root)
            source = root / AUDIT_FILES[0]
            source.write_text("staged alternative")
            git(root, "add", "--", AUDIT_FILES[0])
            with self.assertRaises(SourceRejected):
                checked_audit_tools(root)
            git(root, "reset", "--hard", "HEAD")
            source.unlink()
            # Missing tracked script blocks before any DB connection.
            with self.assertRaises(SourceRejected):
                checked_audit_tools(root)

    def test_checkout_migrations_rejects_dirty_extra_and_untracked_ignored_php(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            init_git(root)
            directory = root / "database/migrations"
            directory.mkdir(parents=True)
            first = directory / "2026_10_01_010000_synthetic.php"
            first.write_text("<?php // known synthetic\n")
            sha = commit_all(root)
            self.assertEqual({"2026_10_01_010000_synthetic"},
                             checked_pinned_migrations(root, sha))
            first.write_text("<?php // local unreviewed change\n")
            with self.assertRaises(SourceRejected):
                checked_pinned_migrations(root, sha)
            git(root, "restore", "--", str(first.relative_to(root)))
            added = directory / "2026_10_01_020000_unknown.php"
            added.write_text("<?php // unknown migration\n")
            with self.assertRaises(SourceRejected):
                checked_pinned_migrations(root, sha)
            added.unlink()
            (root / ".gitignore").write_text("database/migrations/*ignored*.php\n")
            ignored = directory / "2026_10_01_030000_ignored.php"
            ignored.write_text("<?php // not tracked, gitignored\n")
            # Even if Git status hides ignored paths, physical file names
            # must match tracked entries exactly.
            with self.assertRaises(SourceRejected):
                checked_pinned_migrations(root, sha)
            ignored.unlink()
            self.assertEqual({"2026_10_01_010000_synthetic"},
                             checked_pinned_migrations(root, sha))

    def test_wrong_head_and_nonroot_are_rejected(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            init_git(root)
            p = root / "database/migrations/2026_10_01_010000_alpha.php"
            p.parent.mkdir(parents=True)
            p.write_text("<?php")
            sha = commit_all(root)
            with self.assertRaises(SourceRejected):
                checked_pinned_migrations(root, "0" * 40)
            with self.assertRaises(SourceRejected):
                checked_repo(p.parent)
            self.assertEqual(sha, git(root, "rev-parse", "HEAD"))

    def test_no_real_pins_means_block_and_no_source_reflection(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            init_git(root)
            (root / "arbitrary-secret").write_text("never printed")
            commit_all(root)
            out = verify_sources(root, root, root)
            self.assertEqual("BLOCK_AUDIT_OR_PINNED_SOURCE_UNVERIFIED", out)
            self.assertNotIn(str(root), out)

    def test_operator_wrapper_offline_opt_in_cannot_connect_when_checkouts_missing(self):
        script = ROOT / "scripts/ops/prelaunch_pinned_mysql19_collect.sh"
        p = subprocess.run(
            ["bash", str(script), "--preflight-offline"],
            env={"PATH": "/usr/bin:/bin", "HOME": "/nonexistent"},
            capture_output=True, text=True, timeout=10,
        )
        self.assertEqual(2, p.returncode)
        self.assertIn("IMMUTABLE_SOURCE_CHECKOUTS_REQUIRED", p.stderr)
        self.assertNotIn("mysql:", p.stderr)
        self.assertNotIn("SHOW GRANTS", p.stdout)
        self.assertNotIn("PRODUCTION_RELEASE_AUTHORIZED: true", p.stdout)
