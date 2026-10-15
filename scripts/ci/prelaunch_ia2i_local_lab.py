#!/usr/bin/env python3
"""IA-2i: owner-operated, loopback-only, disposable Mac Laravel lab.

This is NOT a production release/deploy script, not a tunnel and not a
physical iPhone acceptance result. Nothing runs until the owner invokes it.
"""
from __future__ import annotations

import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import sqlite3
import stat
import subprocess
import sys

PINNED_SHA = "069e9b5790345feb9bb9edd437ac1c5a39a73efd"
BASE_URL = "http://127.0.0.1:8797"
STATE_FILENAME = "ia2i-private-state.json"
DB_FILENAME = "ia2i-disposable.sqlite"


class SafetyError(RuntimeError):
    pass


def inside(path: Path, parent: Path) -> bool:
    return path == parent or parent in path.parents


def check_no_symlink_components(path: Path) -> None:
    for node in (path, *path.parents):
        if node.is_symlink():
            raise SafetyError("A symlinked lab or checkout path is not allowed")


def git_value(checkout: Path, *args: str) -> str:
    return subprocess.check_output(
        ["git", "-C", str(checkout), *args], text=True, stderr=subprocess.DEVNULL
    ).strip()


def validate_checkout(path: str) -> Path:
    checkout = Path(path).expanduser().absolute()
    check_no_symlink_components(checkout)
    if not checkout.is_dir() or not (checkout / "artisan").is_file():
        raise SafetyError("Expected an existing standalone Laravel checkout")
    if git_value(checkout, "rev-parse", "--show-toplevel") != str(checkout):
        raise SafetyError("Checkout must be the Git worktree root")
    if git_value(checkout, "rev-parse", "HEAD") != PINNED_SHA:
        raise SafetyError("Code SHA does not match the frozen IA-2h review snapshot")
    if git_value(checkout, "status", "--porcelain", "--untracked-files=no"):
        raise SafetyError("Tracked worktree files changed; use a clean detached worktree")
    if any(checkout.glob(".env")) or any(p.name != ".env.example" for p in checkout.glob(".env.*")):
        raise SafetyError("Local .env files are forbidden: do not risk production secrets")
    if (checkout / "bootstrap/cache/config.php").exists():
        raise SafetyError("Cached Laravel config might point outside this lab")
    if (checkout / "public/hot").exists():
        raise SafetyError("Vite dev-server marker is not permitted in the isolated lab")
    return checkout


def validate_lab(location: str, checkout: Path, *, create: bool) -> Path:
    lab = Path(location).expanduser().absolute()
    check_no_symlink_components(lab)
    if lab in (Path("/"), Path.home().absolute()) or inside(lab, checkout) or inside(checkout, lab):
        raise SafetyError("Lab must be a separate narrow directory outside the checkout")
    if create:
        lab.mkdir(parents=True, exist_ok=True, mode=0o700)
        lab.chmod(0o700)
    if not lab.is_dir():
        raise SafetyError("Lab directory has not been initialized")
    if lab.stat().st_mode & 0o077:
        raise SafetyError("Lab directory must be owner-only (chmod 700)")
    return lab


def init_lab(lab: Path) -> None:
    statefile, db = lab / STATE_FILENAME, lab / DB_FILENAME
    if statefile.exists() or db.exists():
        raise SafetyError("Lab already exists; do not overwrite or reuse an unknown database")
    secret = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode("ascii")
    payload = {"version": 1, "source_sha": PINNED_SHA, "app_key": secret}
    fd = os.open(statefile, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle)
    except BaseException:
        statefile.unlink(missing_ok=True)
        raise
    try:
        fd = os.open(db, os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)
        os.close(fd)
        with sqlite3.connect(db) as connection:
            connection.execute("PRAGMA user_version = 0")
    except BaseException:
        db.unlink(missing_ok=True)
        statefile.unlink(missing_ok=True)
        raise


def load_lab(lab: Path) -> tuple[dict, Path]:
    statefile, db = lab / STATE_FILENAME, lab / DB_FILENAME
    if not statefile.is_file() or not db.is_file() or statefile.is_symlink() or db.is_symlink():
        raise SafetyError("Missing or symlinked private state/SQLite file")
    if (statefile.stat().st_mode | db.stat().st_mode) & 0o077:
        raise SafetyError("Lab state and SQLite permissions must be owner-only (chmod 600)")
    payload = json.loads(statefile.read_text(encoding="utf-8"))
    if set(payload) != {"version", "source_sha", "app_key"} or payload["version"] != 1:
        raise SafetyError("Unknown lab state format")
    if payload["source_sha"] != PINNED_SHA or not isinstance(payload["app_key"], str):
        raise SafetyError("Lab does not belong to frozen source")
    key = payload["app_key"]
    if not key.startswith("base64:"):
        raise SafetyError("Invalid app key format")
    decoded = base64.b64decode(key[7:], validate=True)
    if len(decoded) != 32:
        raise SafetyError("Invalid app key size")
    return payload, db


def isolated_env(payload: dict, db: Path) -> dict[str, str]:
    # Explicit allowlist. Never inherit DB/Render/Aiven/AWS/GitHub/Auth0 secrets.
    env = {k: os.environ[k] for k in ("PATH", "HOME", "TMPDIR", "SYSTEMROOT") if k in os.environ}
    env.update({
        "APP_NAME": "Canovia IA2i Local Acceptance",
        "APP_ENV": "local",  # Real CSRF/session middleware, not PHPUnit test shortcuts.
        "APP_DEBUG": "false",
        "APP_URL": BASE_URL,
        "APP_KEY": payload["app_key"],
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": str(db),
        "SESSION_DRIVER": "file",
        "SESSION_COOKIE": "canovia-ia2i-session",
        "SESSION_DOMAIN": "null",
        "CACHE_STORE": "file",
        "QUEUE_CONNECTION": "sync",
        "FILESYSTEM_DISK": "local",
        "BROADCAST_CONNECTION": "log",
        "MAIL_MAILER": "array",
        "LOG_CHANNEL": "single",
        "CANOVIA_PUBLIC_RELEASE_LEVEL": "1",
        "NATIVE_AI_DRIVER": "disabled",
        "FEATURE_CANOVIA_AI": "false",
        "CANOVIA_MCP_TOOLS_ENABLED": "false",
        "CANOVIA_MCP_DISCOVERY_ENABLED": "false",
        "CANOVIA_MCP_PLAN_CONSENT_ENABLED": "false",
        "CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED": "false",
        "CANOVIA_MCP_ACCOUNT_LINK_ENABLED": "false",
        "CANOVIA_MCP_DELEGATED_POLICY_ENABLED": "false",
        "GITHUB_APP_ID": "",
        "GITHUB_APP_PRIVATE_KEY": "",
        "GITHUB_APP_PRIVATE_KEY_BASE64": "",
        "GITHUB_APP_WEBHOOK_SECRET": "",
        "GITHUB_READ_TOKEN": "",
        "AWS_ACCESS_KEY_ID": "",
        "AWS_SECRET_ACCESS_KEY": "",
        "AWS_BUCKET": "",
        "DB_HOST": "",
        "DB_USERNAME": "",
        "DB_PASSWORD": "",
    })
    return env


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("init", "check", "migrate", "serve"))
    parser.add_argument("--checkout", required=True, help="Absolute path to clean detached frozen-source worktree")
    parser.add_argument("--lab-dir", required=True, help="Separate owner-private lab directory")
    parser.add_argument("--confirm-disposable", action="store_true", help="Required before isolated SQLite migration/server")
    args = parser.parse_args()

    checkout = validate_checkout(args.checkout)
    lab = validate_lab(args.lab_dir, checkout, create=args.action == "init")
    if args.action == "init":
        init_lab(lab)
        print("IA2I_LAB_INITIALIZED source_sha=" + PINNED_SHA)
        print("ONLY_DISPOSABLE_SQLITE_CREATED; NO_LARAVEL_MIGRATION_OR_SERVER")
        return 0
    payload, db = load_lab(lab)
    env = isolated_env(payload, db)
    if args.action == "check":
        print("IA2I_SOURCE_PINNED=" + PINNED_SHA)
        print("IA2I_DB=ISOLATED_OWNER_ONLY_SQLITE")
        print("IA2I_NETWORK=LOOPBACK_ONLY")
        print("IA2I_APP_ENV=local / CSRF ENABLED")
        print("IA2I_VENDOR_READY=" + str((checkout / "vendor/autoload.php").is_file()).lower())
        print("IA2I_ASSETS_READY=" + str((checkout / "public/build/manifest.json").is_file()).lower())
        print("IA2I_DEVICE_ACCEPTANCE=NOT_RUN")
        print("IA2I_PRODUCTION_RELEASE=NO_GO")
        return 0

    if not args.confirm_disposable:
        raise SafetyError("migrate/serve require --confirm-disposable")
    if not (checkout / "vendor/autoload.php").is_file():
        raise SafetyError("Install locked Composer dependencies inside the isolated worktree first")
    if args.action == "serve" and not (checkout / "public/build/manifest.json").is_file():
        raise SafetyError("Build local Vite assets inside the isolated worktree first")
    command = (["php", "artisan", "migrate", "--force", "--no-interaction"]
               if args.action == "migrate"
               else ["php", "artisan", "serve", "--host=127.0.0.1", "--port=8797"])
    print("IA2I_LOCAL_ONLY action=" + args.action + " source_sha=" + PINNED_SHA, flush=True)
    return subprocess.call(command, cwd=checkout, env=env)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (SafetyError, subprocess.SubprocessError, OSError, ValueError, KeyError) as error:
        # Never print raw environment variables, secrets or config contents.
        print("IA2I_BLOCKED: " + str(error), file=sys.stderr)
        sys.exit(2)
