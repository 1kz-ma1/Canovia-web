#!/usr/bin/env python3
"""Owner-authorized B source build. Render deployment requires a separate owner GO."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

import preparation_static_build as b
from preparation_static_render import OWNER_SELECTED_ORIGIN

# Owner authorized this source-only activation on 2026-10-27. Keep the grant
# literal and narrowly scoped; it must not follow an env/JSON/CLI/Origin change.
# This is NOT authorization to change Render settings or trigger a deploy.
RELEASE_AUTHORIZATION = {
    'kind': 'PREPARATION_B_PUBLICATION_OWNER_APPROVAL_V1',
    'origin': 'https://start.rpf-canovia.com',
    'profile': 'B',
    'release_approved': True,
}
APPROVAL_KIND = 'PREPARATION_B_PUBLICATION_OWNER_APPROVAL_V1'
PUBLISH_DIR = '.render-static-B'


def expected_approval():
    return dict(kind=APPROVAL_KIND, origin=OWNER_SELECTED_ORIGIN,
                profile='B', release_approved=True)


def approved_public_files(source, profile, artifact_hash, authorization, source_tree):
    """Build an in-memory, exact-source release closure for isolated tests/review."""
    if b.canonical_manifest(authorization) != b.canonical_manifest(expected_approval()):
        raise ValueError('OWNER_RELEASE_APPROVAL_REQUIRED')
    if profile != 'B' or sys.version_info < (3, 9):
        raise ValueError('B_PROFILE_AND_PYTHON_REQUIRED')
    if not isinstance(source, str) or not re.fullmatch(r'[0-9a-f]{40}', source):
        raise ValueError('FULL_SOURCE_SHA_REQUIRED')
    if not isinstance(artifact_hash, str) or not re.fullmatch(r'[0-9a-f]{64}', artifact_hash):
        raise ValueError('PINNED_ARTIFACT_HASH_REQUIRED')
    if b.clean_source() != source:
        raise ValueError('CLEAN_SOURCE_MISMATCH')
    if (not isinstance(source_tree, str) or not re.fullmatch(r'[0-9a-f]{40}', source_tree)
            or subprocess.check_output(['git', 'rev-parse', source + '^{tree}'],
                                       cwd=b.ROOT, text=True).strip() != source_tree):
        raise ValueError('SOURCE_TREE_MISMATCH')
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


def verify_public_files(files, source, source_tree, artifact_hash, authorization):
    expected = approved_public_files(source, 'B', artifact_hash, authorization, source_tree)
    if (type(files) is not dict or set(files) != set(expected) or
            any(type(files[n]) is not bytes or files[n] != raw for n, raw in expected.items())):
        raise ValueError('PUBLIC_BYTES_MISMATCH')
    inventory = {n: b.digest(raw) for n, raw in sorted(files.items())}
    # Hash the complete inventory, INCLUDING manifest.json, without a self-hash.
    # This receipt stays outside the public directory and is never deployment GO.
    return dict(kind='PREPARATION_B_OFFLINE_PUBLIC_REVIEW_V1', source_sha=source,
                source_tree=source_tree, profile='B', origin=OWNER_SELECTED_ORIGIN,
                neutral_zip_sha256=artifact_hash, public_files=len(files),
                public_bytes=sum(map(len, files.values())), files=inventory,
                public_inventory_sha256=b.digest(b.canonical_manifest(inventory)),
                deployed_artifact='UNVERIFIED', deployment_authorized=False,
                provider_runtime='UNVERIFIED', production_gate='PRODUCTION_NO_GO_HOLD')


def read_public_directory(directory):
    root = Path(directory).absolute()
    if root.resolve(strict=True) != root or not root.is_dir():
        raise ValueError('BAD_PUBLIC_DIRECTORY')
    files = {}
    for count, path in enumerate(root.rglob('*'), 1):
        if count > 32 or path.is_symlink():
            raise ValueError('PUBLIC_ASSET_LINK_OR_COUNT')
        name = path.relative_to(root).as_posix()
        if path.is_dir():
            if name not in ('src', 'assets'):
                raise ValueError('EXTRA_PUBLIC_DIRECTORY')
        else:
            files[name] = b.public_file(root, name, b.MAX_MEMBER)
    return files


def verify_public_directory(directory, source, source_tree, artifact_hash, authorization):
    return verify_public_files(read_public_directory(directory), source, source_tree,
                               artifact_hash, authorization)


def stage_files(workspace, files, source, source_tree, artifact_hash, authorization):
    """Exclusive, locally staged static assets; never replace a previous build."""
    workspace = Path(workspace).absolute()
    if workspace.resolve(strict=True) != workspace or not workspace.is_dir():
        raise ValueError('INVALID_WORKSPACE')
    destination = workspace / PUBLISH_DIR
    if destination.exists() or destination.is_symlink():
        raise ValueError('PUBLISH_PATH_ALREADY_PRESENT')
    verify_public_files(files, source, source_tree, artifact_hash, authorization)
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
        if read_public_directory(stage) != files:
            raise ValueError('STAGE_BYTES_MISMATCH')
        if destination.exists() or destination.is_symlink():
            raise ValueError('PUBLISH_PATH_ALREADY_PRESENT')
        stage.rename(destination)
    return destination


def main():
    # Authorization is deliberately checked before any file output or build.
    if b.canonical_manifest(RELEASE_AUTHORIZATION) != b.canonical_manifest(expected_approval()):
        raise ValueError('OWNER_RELEASE_APPROVAL_REQUIRED')
    class Parser(argparse.ArgumentParser):
        def error(self, message):
            raise ValueError('ARGUMENTS_REJECTED')
    p = Parser()
    p.add_argument('--source-sha', required=True)
    p.add_argument('--source-tree', required=True)
    p.add_argument('--artifact-sha256', required=True)
    p.add_argument('--profile', choices=('B', 'C'), required=True)
    args = p.parse_args()
    files = approved_public_files(args.source_sha, args.profile,
                                  args.artifact_sha256, RELEASE_AUTHORIZATION, args.source_tree)
    receipt = verify_public_files(files, args.source_sha, args.source_tree,
                                  args.artifact_sha256, RELEASE_AUTHORIZATION)
    stage_files(b.ROOT, files, args.source_sha, args.source_tree,
                args.artifact_sha256, RELEASE_AUTHORIZATION)
    print(json.dumps(dict(receipt, source_build_authorized=True)))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, RecursionError,
            zipfile.BadZipFile, subprocess.SubprocessError):
        print('PREPARATION_RELEASE_BLOCK', file=sys.stderr)
        sys.exit(2)
