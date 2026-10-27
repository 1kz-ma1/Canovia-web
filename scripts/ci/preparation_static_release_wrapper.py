#!/usr/bin/env python3
"""Dormant Render publication wrapper. No live build without a code-reviewed approval."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

import preparation_static_build as b
from preparation_static_render import OWNER_SELECTED_ORIGIN

# Deliberately hard-coded. Environment variables, Render settings, or CLI flags
# must never grant publication. A future reviewed PR and owner GO are required.
RELEASE_AUTHORIZATION = None
APPROVAL_KIND = 'PREPARATION_B_PUBLICATION_OWNER_APPROVAL_V1'
PUBLISH_DIR = '.render-static-B'


def expected_approval():
    return dict(kind=APPROVAL_KIND, origin=OWNER_SELECTED_ORIGIN,
                profile='B', release_approved=True)


def approved_public_files(source, profile, artifact_hash, authorization):
    """Build an in-memory, exact-source release closure for isolated tests/review."""
    if authorization != expected_approval():
        raise ValueError('OWNER_RELEASE_APPROVAL_REQUIRED')
    if profile != 'B' or sys.version_info < (3, 9):
        raise ValueError('B_PROFILE_AND_PYTHON_REQUIRED')
    if not isinstance(source, str) or not re.fullmatch(r'[0-9a-f]{40}', source):
        raise ValueError('FULL_SOURCE_SHA_REQUIRED')
    if not isinstance(artifact_hash, str) or not re.fullmatch(r'[0-9a-f]{64}', artifact_hash):
        raise ValueError('PINNED_ARTIFACT_HASH_REQUIRED')
    if b.clean_source() != source:
        raise ValueError('CLEAN_SOURCE_MISMATCH')
    # Compare the deterministic neutral B archive to the pinned digest. Never
    # trust a remotely fetched ZIP, the live filesystem, or a passed directory.
    with tempfile.TemporaryDirectory(prefix='preparation-neutral-reference-') as temp:
        built = b.build(Path(temp) / 'neutral-B', 'B')
        if built['source_sha'] != source or built['sha256'] != artifact_hash:
            raise ValueError('NEUTRAL_ARTIFACT_HASH_MISMATCH')
    files, inputs = b.candidate_files(source, 'B')
    neutral_settings = b"export const PROFILE='B';\nexport const ADOPTED_ORIGIN=null;\n"
    if files['src/settings.mjs'] != neutral_settings:
        raise ValueError('SETTINGS_SOURCE_CHANGED')
    files['src/settings.mjs'] = (
        "export const PROFILE='B';\n"
        f"export const ADOPTED_ORIGIN='{OWNER_SELECTED_ORIGIN}';\n"
    ).encode('ascii')
    b.hosting_contract(files)
    manifest = b.manifest_for(source, 'B', files, inputs)
    manifest['kind'] = 'PREPARATION_STATIC_OWNER_APPROVED_B_V1'
    manifest['adopted_origin'] = OWNER_SELECTED_ORIGIN
    manifest['release_approved'] = True
    # The neutral ZIP hash is included for review; it is not the hash of the
    # transformed public directory, whose inventory is independently recorded.
    manifest['neutral_artifact_sha256'] = artifact_hash
    files['manifest.json'] = b.canonical_manifest(manifest)
    if len(files) != 25:
        raise ValueError('PUBLIC_CLOSURE_COUNT')
    return files


def stage_files(workspace, files):
    """Exclusive, locally staged static assets; never replace a previous build."""
    workspace = Path(workspace)
    if workspace.is_symlink() or not workspace.is_dir():
        raise ValueError('INVALID_WORKSPACE')
    destination = workspace / PUBLISH_DIR
    if destination.exists() or destination.is_symlink():
        raise ValueError('PUBLISH_PATH_ALREADY_PRESENT')
    if set(files) != set(b.candidate_files(b.clean_source(), 'B')[0]) | {'manifest.json'}:
        raise ValueError('PUBLIC_CLOSURE_CHANGED')
    with tempfile.TemporaryDirectory(prefix='.preparation-release-stage-', dir=workspace) as temp:
        stage = Path(temp) / 'candidate'
        stage.mkdir(mode=0o700)
        for name, value in sorted(files.items()):
            if (not isinstance(value, bytes) or len(value) == 0 or
                    len(value) > b.MAX_MEMBER or Path(name).is_absolute() or
                    any(p in ('', '.', '..') for p in Path(name).parts)):
                raise ValueError('PUBLIC_ASSET_REJECTED')
            path = stage / name
            path.parent.mkdir(mode=0o700, exist_ok=True)
            with path.open('xb') as f:
                f.write(value)
            if b.public_file(stage, name, b.MAX_MEMBER) != value:
                raise ValueError('STAGE_BYTES_MISMATCH')
        if destination.exists() or destination.is_symlink():
            raise ValueError('PUBLISH_PATH_ALREADY_PRESENT')
        stage.rename(destination)
    return destination


def main():
    # Authorization is deliberately checked before any file output or build.
    if RELEASE_AUTHORIZATION is None:
        raise ValueError('OWNER_RELEASE_APPROVAL_REQUIRED')
    class Parser(argparse.ArgumentParser):
        def error(self, message):
            raise ValueError('ARGUMENTS_REJECTED')
    p = Parser()
    p.add_argument('--source-sha', required=True)
    p.add_argument('--artifact-sha256', required=True)
    p.add_argument('--profile', choices=('B', 'C'), required=True)
    args = p.parse_args()
    files = approved_public_files(args.source_sha, args.profile,
                                  args.artifact_sha256, RELEASE_AUTHORIZATION)
    stage_files(b.ROOT, files)
    print(json.dumps(dict(profile='B', public_files=len(files),
                          adopted_origin=OWNER_SELECTED_ORIGIN,
                          source_sha=args.source_sha)))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, RecursionError,
            zipfile.BadZipFile, subprocess.SubprocessError):
        print('PREPARATION_RELEASE_BLOCK', file=sys.stderr)
        sys.exit(2)
