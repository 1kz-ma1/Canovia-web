#!/usr/bin/env python3
"""Offline Render review plan and staging. No API, deploy, or Origin adoption."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

import preparation_static_build as b


def plan_for(source, profile, artifact_hash):
    if not isinstance(source, str) or not re.fullmatch(r'[0-9a-f]{40}', source):
        raise ValueError('FULL_SOURCE_SHA_REQUIRED')
    if profile not in ('B', 'C'):
        raise ValueError('PROFILE_REQUIRED')
    if not isinstance(artifact_hash, str) or not re.fullmatch(r'[0-9a-f]{64}', artifact_hash):
        raise ValueError('ARTIFACT_HASH_REQUIRED')
    command = ('python3 -B scripts/ci/preparation_static_render.py --render-build'
               f' --profile {profile} --source-sha {source} --artifact-sha256 {artifact_hash}')
    # This is our review format, NOT a Render API request or auto-synced Blueprint.
    return dict(kind='PREPARATION_RENDER_OFFLINE_PLAN_V1', source_sha=source,
                profile=profile, artifact_sha256=artifact_hash, adopted_origin=None,
                release_approved=False, rootDir='', buildCommand=command,
                publishPath=f'.render-static-{profile}', autoDeployTrigger='off',
                previews={'generation': 'off'}, routes=[],
                headers=[dict(path='/*', name=k, value=v) for k, v in b.HEADERS.items()],
                buildEnvironment={'SKIP_INSTALL_DEPS': 'true'}, envGroups=[],
                customDomains=[], unknownPathPolicy='404_NO_INDEX_REWRITE',
                provider_runtime='UNVERIFIED', production_gate='PRODUCTION_NO_GO_HOLD')


def no_duplicate_keys(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('DUPLICATE_SETTING')
        result[key] = value
    return result


def verify_plan(plan, source, profile, artifact_hash):
    # Canonical JSON comparison also rejects bool/int substitutions, extra keys,
    # duplicate headers and unexpected settings. No provider defaults are inferred.
    if b.canonical_manifest(plan) != b.canonical_manifest(plan_for(source, profile, artifact_hash)):
        raise ValueError('RENDER_PLAN_MISMATCH')


def local_target(path):
    """Only new local outputs outside the source checkout, never overwrite."""
    target = Path(path).absolute()
    parent = target.parent.resolve(strict=True)
    if parent != target.parent or target.exists() or target.is_symlink():
        raise ValueError('NEW_PRIVATE_OUTPUT_REQUIRED')
    if target == b.ROOT or b.ROOT in target.parents:
        raise ValueError('OUTPUT_OUTSIDE_CHECKOUT_REQUIRED')
    return target


def inspect(artifact, source, profile, artifact_hash, plan_path=None):
    expected = plan_for(source, profile, artifact_hash)
    if sys.version_info < (3, 9):
        raise ValueError('PYTHON_39_REQUIRED')
    if b.clean_source() != source:
        raise ValueError('SOURCE_MISMATCH')
    if plan_path:
        path = Path(plan_path)
        raw = b.public_file(path.parent, path.name, 16384)
        actual = json.loads(raw, object_pairs_hook=no_duplicate_keys)
        verify_plan(actual, source, profile, artifact_hash)
    result = b.verify_archive(artifact, profile)
    if result['sha256'] != artifact_hash:
        raise ValueError('ARTIFACT_HASH_MISMATCH')
    return expected, result


def prepare(artifact, source, profile, artifact_hash, plan_path=None, plan_out=None, stage=None):
    plan, result = inspect(artifact, source, profile, artifact_hash, plan_path)
    outputs = [local_target(p) for p in (plan_out, stage) if p is not None]
    if len(set(outputs)) != len(outputs) or any(a in c.parents for a in outputs for c in outputs if a != c):
        raise ValueError('OUTPUT_COLLISION')
    if stage:
        if Path(stage).name != plan['publishPath']:
            raise ValueError('PUBLISH_DIRECTORY_MISMATCH')
        # Rebuild from Git, not by extracting the supplied ZIP. Verify before copy.
        with tempfile.TemporaryDirectory(prefix='preparation-render-') as temp:
            candidate = Path(temp) / 'candidate'
            built = b.build(candidate, profile)
            if built['sha256'] != artifact_hash:
                raise ValueError('REBUILD_MISMATCH')
            files = b.verify_directory(candidate)
            files['manifest.json'] = b.public_file(candidate, 'manifest.json', 65536)
            target = local_target(stage)
            target.mkdir(mode=0o700)
            for name, raw in files.items():
                path = target / name
                path.parent.mkdir(exist_ok=True)
                with path.open('xb') as stream:
                    stream.write(raw)
            b.verify_directory(target)
    if plan_out:
        with local_target(plan_out).open('xb') as stream:
            stream.write(b.canonical_manifest(plan))
    return dict(result, offline_render_plan='PASS', publish_directory=plan['publishPath'],
                staged=stage is not None, render_build_environment='UNKNOWN',
                deployment='BLOCKED_ORIGIN_NOT_ADOPTED', production_gate='PRODUCTION_NO_GO_HOLD')


def render_build(source, profile, artifact_hash):
    """Reserved Build Command: fail closed until a separately approved change."""
    plan_for(source, profile, artifact_hash)
    if sys.version_info < (3, 9) or b.clean_source() != source:
        raise ValueError('BUILD_PREREQUISITES_NOT_MET')
    # No option, environment variable or supplied JSON can turn this into deploy GO.
    raise ValueError('ORIGIN_NOT_ADOPTED')


def main():
    class Parser(argparse.ArgumentParser):
        def error(self, message):
            raise ValueError('ARGUMENTS_REJECTED')
    p = Parser()
    p.add_argument('--source-sha', required=True)
    p.add_argument('--profile', choices=['B', 'C'], required=True)
    p.add_argument('--artifact-sha256', required=True)
    p.add_argument('--artifact')
    p.add_argument('--plan')
    p.add_argument('--plan-out')
    p.add_argument('--stage')
    p.add_argument('--render-build', action='store_true')
    a = p.parse_args()
    if a.render_build:
        if any((a.artifact, a.plan, a.plan_out, a.stage)):
            raise ValueError('BUILD_ARGUMENTS_REJECTED')
        render_build(a.source_sha, a.profile, a.artifact_sha256)
    if not a.artifact:
        raise ValueError('ARTIFACT_REQUIRED')
    print(json.dumps(prepare(a.artifact, a.source_sha, a.profile, a.artifact_sha256,
                             a.plan, a.plan_out, a.stage)))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, RecursionError,
            zipfile.BadZipFile, subprocess.SubprocessError):
        print('PREPARATION_RENDER_BLOCK', file=sys.stderr)
        sys.exit(2)
