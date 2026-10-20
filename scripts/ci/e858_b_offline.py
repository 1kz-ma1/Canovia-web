#!/usr/bin/env python3
"""B operator checklist rehearsal ONLY. No database client or approval issuer.

All declarations are anonymous and unverified. Every output forbids execution.
Only read-only local Git/source subprocesses are used; nothing fetches or connects.
"""
import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parent))
from p0_mysql_metadata_grants_input import read_grants as read_input
from release488_runtime_ddl_restore_gate import unique_keys

ROOT = Path(__file__).resolve().parents[2]
SOURCE = 'e858b9c855970fbe73f37a2d60c9db6525b132d6'
BASE = 'b72260b8c0ac152c24927d207c6dbf06df12f877'
TREE = 'f6990e8bcf7904be2fdc2706d6a525a29ccef9ee'
SEAL = 'a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b'
RELEASE = 'release/2026-10-19-e858b9c8'
PINS = {
    RELEASE: SOURCE, 'production': '15abdc9de6f24b5250fb255c579d1aa55facaf1a',
    'audit/e858-source-bound-readiness': 'be89af9a2cb4b13ef16579d2570f4a5605a711b8',
    'audit/e858-transport-offline-preflight': '5e63b7db068121d000c316fa8700f7c5f1c0c3b4',
    'audit/e858-approval-tls-offline-gate': '9f7d248e87bd54211b76d9b85effd72ee385daed',
}
COLLECTOR, TRANSPORT, CONTRACT = [PINS['audit/' + name] for name in (
    'e858-source-bound-readiness', 'e858-transport-offline-preflight', 'e858-approval-tls-offline-gate')]
SHA = re.compile('[0-9a-f]{40}')
DECLARATIONS = dict(operator='OWNER_OPERATOR_PRIVATE_REVIEW_DECLARED',
    service='SINGLE_SERVICE_PRIVATE_REVIEW_DECLARED', database='SINGLE_DB_PRIVATE_REVIEW_DECLARED',
    terminal='MANAGED_MAC_PRIVATE_REVIEW_DECLARED', tls='VERIFY_IDENTITY',
    grants='EXISTING_MINIMAL_METADATA_LEDGER_ONLY', scope='FROZEN_METADATA_ONLY',
    raw_custody='PRIVATE_NON_SYNCED_TIMED_DELETION_DECLARED',
    review='SYNTHETIC_REVIEW_ONLY_NOT_REAL_APPROVAL')


class Block(Exception):
    """Only fixed codes; never wrap private exceptions or inputs."""


class Parser(argparse.ArgumentParser):
    def error(self, message):
        raise Block('ARGUMENTS_REJECTED')


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode('ascii')


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def decode(raw):
    if type(raw) is not bytes or not raw or len(raw) > 16384 or not raw.isascii():
        raise Block('INPUT_REJECTED')
    def reject(_):
        raise Block('INPUT_REJECTED')
    value = json.loads(raw, object_pairs_hook=unique_keys, parse_constant=reject, parse_float=reject)
    stack = [(value, 0)]
    while stack:
        item, depth = stack.pop()
        if depth > 16:
            raise Block('INPUT_REJECTED')
        if isinstance(item, dict): stack.extend((v, depth + 1) for v in item.values())
        elif isinstance(item, list): stack.extend((v, depth + 1) for v in item)
    return value


def local_env():
    return {'PATH': os.defpath, 'LC_ALL': 'C', 'GIT_CONFIG_NOSYSTEM': '1',
            'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_TERMINAL_PROMPT': '0',
            'GIT_NO_REPLACE_OBJECTS': '1'}


def git(root, *args):
    result = subprocess.run(['git', '--no-replace-objects', '-c', 'core.fsmonitor=false',
        '-c', 'core.hooksPath=/nonexistent', '-C', str(root), *args], capture_output=True,
        timeout=20, env=local_env())
    if result.returncode or len(result.stdout) > 8 * 1024 * 1024:
        raise Block('LOCAL_SOURCE_REJECTED')
    return result.stdout


def integrity(root, head):
    if type(head) is not str or not SHA.fullmatch(head):
        raise Block('TOOLING_MISMATCH')
    if git(root, 'rev-parse', 'HEAD').decode().strip() != head:
        raise Block('TOOLING_MISMATCH')
    if git(root, 'rev-parse', '--is-shallow-repository').strip() != b'false':
        raise Block('FULL_HISTORY_REQUIRED')
    if git(root, 'status', '--porcelain', '--untracked-files=all'):
        raise Block('CLEAN_CHECKOUT_REQUIRED')
    if any(row[:1] != b'H' for row in git(root, 'ls-files', '-v').splitlines()):
        raise Block('INDEX_FLAGS_REJECTED')
    git(root, 'merge-base', '--is-ancestor', BASE, head)
    for ref, pin in PINS.items():
        if git(root, 'rev-parse', 'refs/remotes/origin/' + ref).decode().strip() != pin:
            raise Block('FIXED_REF_MISMATCH')
    if git(root, 'rev-parse', SOURCE + '^{tree}').decode().strip() != TREE:
        raise Block('SOURCE_MISMATCH')
    if git(root, 'for-each-ref', 'refs/replace'):
        raise Block('GIT_OVERRIDE_REJECTED')
    protected = ('scripts/ci/integrated_release20_audit.py',
                 'scripts/ci/p0_mysql_full_ledger_offline_check.py',
                 'scripts/ci/p0_mysql_metadata_grants_input.py',
                 'scripts/ci/p0_mysql_metadata_grants_guard.py',
                 'scripts/ci/release488_runtime_ddl_restore_gate.py')
    for path in protected:
        if git(root, 'show', head + ':' + path) != git(root, 'show', BASE + ':' + path):
            raise Block('DEPENDENCY_CHANGED')
    # Confirm actual tracked bytes, even when status lies/hidden flags are set.
    tracked = {}
    for line in git(root, 'ls-tree', '-r', head).decode().splitlines():
        meta, path = line.split('\t'); mode, kind, blob = meta.split()
        file = root / path
        if (mode not in ('100644', '100755') or kind != 'blob' or file.is_symlink()
                or any(p.is_symlink() for p in file.parents)
                or not stat.S_ISREG(file.stat().st_mode) or file.stat().st_size > 8 * 1024 * 1024
                or bool(file.stat().st_mode & 0o111) != (mode == '100755')):
            raise Block('TRACKED_BYTES_REJECTED')
        raw = file.read_bytes()
        if hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() != blob:
            raise Block('TRACKED_BYTES_REJECTED')
        tracked[path] = blob
    if 'scripts/ci/e858_b_offline.py' not in tracked:
        raise Block('CHECKER_NOT_TRACKED')
    # Reuse the unchanged source implementation in a fixed local-only child,
    # after checking its bytes, with no ambient credential/Git option env.
    code = """import json,pathlib,sys
root=pathlib.Path(sys.argv[1]);sys.path.insert(0,str(root/'scripts/ci'))
from integrated_release20_audit import source
facts=source(root,'e858b9c855970fbe73f37a2d60c9db6525b132d6')
print(json.dumps([facts['migration_seal'],len(facts['baseline']),len(facts['candidate'])]))
"""
    facts = subprocess.run([sys.executable, '-I', '-B', '-c', code, str(root)],
                           capture_output=True, timeout=20, env=local_env())
    if facts.returncode or len(facts.stdout) > 1024 or json.loads(facts.stdout) != [SEAL, 94, 114]:
        raise Block('SOURCE_MISMATCH')
    # Read only literal manifest constants from the fixed 797 blob, not its code.
    constants = {}
    tree = ast.parse(git(root, 'show', CONTRACT + ':scripts/ci/e858_approval_offline/contracts.py'))
    wanted = {'CANDIDATE', 'TOOLING', 'TRANSPORT', 'SQL', 'REFS', 'ORDER'}
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            key = node.targets[0].id
            if key in wanted:
                constants[key] = ast.literal_eval(node.value)
    if set(constants) != wanted or (constants['CANDIDATE'], constants['TOOLING'], constants['TRANSPORT']) != (SOURCE, COLLECTOR, TRANSPORT):
        raise Block('CONTRACT_MISMATCH')
    sql = {}
    for key in sorted(set(constants['ORDER'])):
        sql[key] = hashlib.sha256(git(root, 'show', COLLECTOR + ':scripts/ci/e858_audit/queries/' + key + '.sql')).hexdigest()
    if len(sql) != 14 or len(constants['ORDER']) != 17 or digest(sql) != constants['SQL']:
        raise Block('SQL_MISMATCH')
    for engine, expected in constants['REFS'].items():
        if hashlib.sha256(git(root, 'show', COLLECTOR + ':scripts/ci/e858_audit_reference/' + engine + '.json')).hexdigest() != expected:
            raise Block('REFERENCE_MISMATCH')
    return dict(source_sha=SOURCE, release_ref=RELEASE, tooling_sha=head,
                collector_sha=COLLECTOR, transport_sha=TRANSPORT, contract_sha=CONTRACT,
                sql_sha256=constants['SQL'], reference_sha256=constants['REFS'])


def template(pins, now):
    return dict(kind='E858_B_OFFLINE_REVIEW_V1', pins=pins, declarations={k: 'NOT_REVIEWED' for k in DECLARATIONS},
                engine='8.4', starts_at=now, ends_at=now + 120,
                attempt_id='PRIVATE_ATTEMPT_ID_REQUIRED',
                request='FIRST_ATTEMPT_REVIEW_ONLY', prior_attempt='NOT_RUN')


def validate(packet, pins, now):
    if type(packet) is not dict or set(packet) != set(template(pins, now)):
        raise Block('PACKET_FIELDS_REJECTED')
    if packet['kind'] != 'E858_B_OFFLINE_REVIEW_V1':
        raise Block('REAL_APPROVAL_NOT_ACCEPTED')
    if type(packet['attempt_id']) is not str or not re.fullmatch('[0-9a-f]{32}', packet['attempt_id']):
        raise Block('ATTEMPT_ID_REQUIRED')
    if canonical(packet['pins']) != canonical(pins):
        raise Block('PIN_MISMATCH')
    if packet['request'] != 'FIRST_ATTEMPT_REVIEW_ONLY' or packet['prior_attempt'] != 'NOT_RUN':
        raise Block('NEW_OWNER_GO_REQUIRED')
    if canonical(packet['declarations']) != canonical(DECLARATIONS):
        raise Block('PRIVATE_REVIEW_INCOMPLETE')
    if packet['engine'] not in ('8.0', '8.4'):
        raise Block('ENGINE_REJECTED')
    begin, end = packet['starts_at'], packet['ends_at']
    if type(begin) is not int or type(end) is not int or not 0 < end - begin <= 120:
        raise Block('WINDOW_REJECTED')
    if not begin <= now < end:
        raise Block('EXPIRED_OR_NOT_YET_VALID')
    return digest(packet)


def outcome(code, result='BLOCK'):
    return dict(result=result, code=code, real_aiven_connected=False, execution_allowed=False,
        owner_approval_verified=False, release_authorized=False, production_modified=False,
        strict_block_waived=False, fresh_owner_go_required=True)


def receipt(packet, pins, now, synthetic_case='NOT_RUN'):
    if synthetic_case not in ('NOT_RUN', 'COMPLETED', 'BLOCK', 'INTERRUPTED'):
        raise Block('SYNTHETIC_RECEIPT_REJECTED')
    tag = validate(packet, pins, now)
    return dict(kind='E858_B_SYNTHETIC_RECEIPT_V1', pins=pins, packet_sha256=tag,
        synthetic_case=synthetic_case,
        target_class='OWNER_DECLARED_SINGLE_TARGET_UNVERIFIED',
        planned_starts_at=packet['starts_at'], planned_ends_at=packet['ends_at'],
        execution_started_at=None, execution_ended_at=None, metadata_count=None,
        attempt='NOT_RUN', cleanup='NOT_RUN', tls_session='UNIMPLEMENTED',
        remote_termination='UNVERIFIED', retry_control='HUMAN_PROCEDURAL_ONLY',
        **outcome('CHECKLIST_DECLARATIONS_ONLY', 'B_OFFLINE_REVIEW_ONLY'))


def verify_receipt(value, packet, pins, now):
    # These receipts are reproducible statements, not signed attestations.
    # Their existence can never grant a retry or real execution.
    if type(value) is not dict or canonical(value) != canonical(receipt(packet, pins, now, value.get('synthetic_case'))):
        raise Block('SYNTHETIC_RECEIPT_REJECTED')
    return True


def load(path):
    if Path(path).resolve().is_relative_to(ROOT):
        raise Block('INPUT_IN_REPOSITORY_REJECTED')
    return decode(read_input(path))


def main(argv=None):
    try:
        parser = Parser(add_help=False, allow_abbrev=False)
        parser.add_argument('--tooling-sha', required=True, action='append')
        modes = parser.add_mutually_exclusive_group(required=True)
        modes.add_argument('--template', action='store_true')
        modes.add_argument('--packet', action='append')
        parser.add_argument('--prior-receipt', action='append')
        args = parser.parse_args(argv)
        if len(args.tooling_sha) != 1 or (args.packet and len(args.packet) != 1) or (args.prior_receipt and len(args.prior_receipt) != 1) or (args.template and args.prior_receipt):
            raise Block('ARGUMENTS_REJECTED')
        pins = integrity(ROOT, args.tooling_sha[0]); now = int(time.time())
        if args.template:
            result = dict(template=template(pins, now),
                          **outcome('UNREVIEWED_TEMPLATE_ONLY', 'B_OFFLINE_TEMPLATE'))
        else:
            packet = load(args.packet[0]); result = receipt(packet, pins, now)
            if args.prior_receipt:
                verify_receipt(load(args.prior_receipt[0]), packet, pins, now)
                raise Block('NEW_OWNER_GO_REQUIRED')
        code = 0
    except Block as error:
        result = outcome(str(error)); code = 2
    except (Exception, SystemExit, KeyboardInterrupt):
        result = outcome('OFFLINE_INPUT_OR_SOURCE_REJECTED'); code = 2
    print(json.dumps(result, sort_keys=True))
    return code


if __name__ == '__main__':
    sys.exit(main())
