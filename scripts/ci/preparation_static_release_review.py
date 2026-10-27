#!/usr/bin/env python3
"""Offline review only. Never writes inside a checkout or changes live authorization."""
import argparse
from pathlib import Path
import subprocess
import sys
import tempfile
import zipfile

import preparation_static_build as b
import preparation_static_release_wrapper as w


def review(source, source_tree, artifact_hash, neutral_zip, output, verify=False):
    # The supplied neutral archive, the Git rebuild and the transformed inventory
    # have distinct identities. A receipt cannot authorize a Render build.
    neutral = b.verify_archive(neutral_zip, 'B')
    if neutral['source_sha'] != source or neutral['sha256'] != artifact_hash:
        raise ValueError('NEUTRAL_PIN_MISMATCH')
    files = w.approved_public_files(source, 'B', artifact_hash, w.expected_approval(), source_tree)
    expected = w.verify_public_files(files, source, source_tree, artifact_hash, w.expected_approval())
    target = Path(output).absolute()
    if target == b.ROOT or b.ROOT in target.parents or target.is_symlink():
        raise ValueError('OFFLINE_OUTPUT_OUTSIDE_CHECKOUT_REQUIRED')
    if target.parent.resolve(strict=True) != target.parent:
        raise ValueError('OUTPUT_PARENT_SYMLINK')
    if verify:
        if set(p.name for p in target.iterdir()) != {w.PUBLISH_DIR, 'review.json'}:
            raise ValueError('EXTRA_REVIEW_OUTPUT')
        actual = w.verify_public_directory(target / w.PUBLISH_DIR, source, source_tree,
                                           artifact_hash, w.expected_approval())
        if (actual != expected or
                b.public_file(target, 'review.json', 16384) != b.canonical_manifest(expected)):
            raise ValueError('REVIEW_RECEIPT_MISMATCH')
    else:
        if target.exists():
            raise ValueError('NEW_OUTPUT_REQUIRED')
        with tempfile.TemporaryDirectory(prefix='.offline-review-', dir=target.parent) as temp:
            workspace = Path(temp) / 'review'
            workspace.mkdir(mode=0o700)
            w.stage_files(workspace, files, source, source_tree, artifact_hash, w.expected_approval())
            (workspace / 'review.json').write_bytes(b.canonical_manifest(expected))
            if target.exists() or target.is_symlink():
                raise ValueError('NEW_OUTPUT_REQUIRED')
            workspace.rename(target)
    return expected


def main():
    class Parser(argparse.ArgumentParser):
        def error(self, message):
            raise ValueError('ARGUMENTS_REJECTED')
    p = Parser(description=__doc__)
    p.add_argument('--source-sha', required=True)
    p.add_argument('--source-tree', required=True)
    p.add_argument('--artifact-sha256', required=True)
    p.add_argument('--neutral-zip', required=True)
    output = p.add_mutually_exclusive_group(required=True)
    output.add_argument('--out')
    output.add_argument('--verify-review')
    a = p.parse_args()
    result = review(a.source_sha, a.source_tree, a.artifact_sha256, a.neutral_zip,
                    a.out or a.verify_review, verify=a.verify_review is not None)
    sys.stdout.buffer.write(b.canonical_manifest(result))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, RecursionError,
            zipfile.BadZipFile, subprocess.SubprocessError):
        print('PREPARATION_OFFLINE_REVIEW_BLOCK', file=sys.stderr)
        sys.exit(2)
