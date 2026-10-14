#!/usr/bin/env python3
"""Fail-closed local hash and access-mode checks for encrypted backup copies.

Never creates a dump, accesses DB/cloud, reads crypto keys, decrypts, writes
files or prints local paths/content/digests. A passing result is NOT restore proof.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import stat
import sys

HEX = re.compile(r"[0-9a-f]{64}\Z")
CHUNK = 1024 * 1024


class UnsafeEvidence(Exception):
    """Expected file safety or integrity cannot be verified."""


def probe(path: Path) -> tuple[int, int, int]:
    try:
        meta = path.lstat()
    except OSError as exc:
        raise UnsafeEvidence() from exc
    if not stat.S_ISREG(meta.st_mode) or not path.name.endswith(".age"):
        raise UnsafeEvidence()
    if meta.st_size == 0 or (stat.S_IMODE(meta.st_mode) & 0o077):
        raise UnsafeEvidence()
    return meta.st_dev, meta.st_ino, meta.st_size


def digest(path: Path) -> str:
    h = hashlib.sha256()
    try:
        with path.open("rb") as f:
            while chunk := f.read(CHUNK):
                h.update(chunk)
    except OSError as exc:
        raise UnsafeEvidence() from exc
    return h.hexdigest()


def result(verdict: str, reason: str) -> dict:
    return {
        "result": verdict,
        "code": reason,
        "ciphertext_hash_verified": verdict == "REVIEW_REQUIRED",
        "offsite_source_provenance_verified": False,
        "age_decryption_verified": False,
        "isolated_restore_verified": False,
        "release_authorized": False,
    }


def verify(source: Path, copy: Path, expected_sha256: str) -> dict:
    if not HEX.fullmatch(expected_sha256):
        return result("BLOCK", "INVALID_EXPECTED_DIGEST")
    try:
        source_meta = probe(source)
        copy_meta = probe(copy)
        if source_meta[:2] == copy_meta[:2] or source_meta[2] != copy_meta[2]:
            return result("BLOCK", "COPIES_NOT_INDEPENDENT_OR_SIZE_MISMATCH")
        if digest(source) != expected_sha256 or digest(copy) != expected_sha256:
            return result("BLOCK", "CIPHERTEXT_DIGEST_MISMATCH")
        # Detect concurrent writes/truncation while hashing. No file modification.
        if probe(source) != source_meta or probe(copy) != copy_meta:
            return result("BLOCK", "EVIDENCE_CHANGED_DURING_CHECK")
    except UnsafeEvidence:
        return result("BLOCK", "CIPHERTEXT_FILE_UNSAFE_OR_UNAVAILABLE")
    return result("REVIEW_REQUIRED", "CIPHERTEXT_COPIES_MATCH_ONLY")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=Path, help="Encrypted local .age file")
    parser.add_argument("--offsite-copy", required=True, type=Path, help="Locally available independent .age copy")
    parser.add_argument("--expected-sha256", required=True, help="Previously recorded ciphertext SHA-256, not a password")
    args = parser.parse_args()
    outcome = verify(args.source, args.offsite_copy, args.expected_sha256)
    print(json.dumps(outcome, sort_keys=True))
    return 0 if outcome["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
