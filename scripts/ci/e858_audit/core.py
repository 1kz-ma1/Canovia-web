"""Frozen e858 source/metadata facts. Never connects, never accepts a waiver."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

sys.dont_write_bytecode = True
from integrated_release20_audit import BASE, WIDTHS, compare, snapshot, source
from integrated_release20_diagnose import projections, digest
from integrated_release20_semantics import parse as semantic_parse, DEPENDENCY_KEY

ROOT = Path(__file__).resolve().parents[3]
CANDIDATE = 'e858b9c855970fbe73f37a2d60c9db6525b132d6'
TREE = 'f6990e8bcf7904be2fdc2706d6a525a29ccef9ee'
RELEASE = 'release/2026-10-19-e858b9c8'
SEAL = 'a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b'
OLD = 'c09a8fc718c338f98a4045a4b5f2260c7571fdaa'
KIND = 'E858_DISPOSABLE_SOURCE_BOUND_REFERENCE_V1'
NORMALIZATION = 'original-strict-v1+semantic-multiset-v1+column-extra-v1'
CAPS = dict(engine=1, identity=1, grants=8, ledger=115, table=512, column=4096,
            index=4096, foreign_key=2048, check=512, trigger=128,
            semantic_version=1, semantic_index=4096, semantic_foreign_key=2048, column_extra=4096)
LEGACY = ['scripts/ci/integrated_release20_audit.py', 'scripts/ci/integrated_release20_diagnose.py',
          'scripts/ci/integrated_release20_semantics.py', 'scripts/ci/p0_mysql_metadata_grants_guard.py',
          'scripts/ci/p0_mysql_full_ledger_offline_check.py',
          'scripts/sql/integrated_release20_metadata.sql', 'scripts/sql/integrated_release20_semantic_metadata.sql']
LEGACY += [f'scripts/ci/{directory}/{series}.json' for directory in (
    'integrated_release20_reference','integrated_release20_diagnostic_reference',
    'integrated_release20_semantic_reference','release20_proposed_reference') for series in ('8.0','8.4')]
LEGACY += ['scripts/ops/integrated_release20_collect.py','scripts/ops/integrated_release20_diagnostic_collect.py']
LEGACY += ['scripts/ci/integrated_release20_semantic_fixtures.py']
IMPLEMENTATION = ['scripts/ci/e858_audit/core.py', 'scripts/ci/e858_audit/transport.py',
                  'scripts/ci/e858_audit_mysql.py', 'scripts/ops/e858_audit_packet.py']
IMPLEMENTATION += ['scripts/ci/e858_audit/queries/' + key + '.sql' for key in sorted(CAPS)]
PREFIXES = ('app/', 'bootstrap/', 'config/', 'database/migrations/', 'routes/')
SOURCE_FILES = ['artisan', 'composer.json', 'composer.lock', 'resources/release20_pending_schema_contract.json',
                'scripts/ci/integration_upgrade_fixture.php']


class Block(Exception):
    """Only a fixed code is safe to render; never echo another exception."""
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args], text=True,
                                   stderr=subprocess.DEVNULL, timeout=20).strip()


def hashed(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def blob_file(root, path, sha):
    file = root / path
    if file.is_symlink() or not file.is_file() or any(p.is_symlink() for p in file.parents if p != root.parent):
        raise Block('SOURCE_FILE_REJECTED')
    raw = file.read_bytes()
    actual = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
    if actual != git(root, 'rev-parse', sha + ':' + path):
        raise Block('SOURCE_FILE_REJECTED')
    return actual


def source_facts(root=ROOT, candidate=CANDIDATE):
    if candidate != CANDIDATE or git(root, 'rev-parse', candidate + '^{tree}') != TREE:
        raise Block('CANDIDATE_PIN_REJECTED')
    facts = source(root, candidate)
    if facts['migration_seal'] != SEAL:
        raise Block('MIGRATION_SEAL_REJECTED')
    old = source(root, OLD)
    pending = sorted(set(facts['candidate']) - set(facts['baseline']))
    changed = [i + 1 for i, name in enumerate(pending) if facts['candidate'][name] != old['candidate'][name]]
    if changed != [1, 8, 18, 19, 20]:
        raise Block('PENDING_SOURCE_SET_REJECTED')
    return facts


def boundary(root, candidate, tooling, clean=True):
    if not re.fullmatch('[0-9a-f]{40}', tooling) or git(root, 'rev-parse', 'HEAD') != tooling:
        raise Block('TOOLING_PIN_REJECTED')
    if git(root, 'rev-parse', '--is-shallow-repository') != 'false':
        raise Block('FULL_HISTORY_REQUIRED')
    if clean and git(root, 'status', '--porcelain', '--untracked-files=all'):
        raise Block('CLEAN_CHECKOUT_REQUIRED')
    facts = source_facts(root, candidate)
    if git(root, 'rev-parse', 'refs/remotes/origin/' + RELEASE) != candidate:
        raise Block('RELEASE_PIN_REJECTED')
    git(root, 'merge-base', '--is-ancestor', candidate, 'refs/remotes/origin/main')
    git(root, 'merge-base', '--is-ancestor', candidate, tooling)
    changed = git(root, 'diff', '--name-only', candidate, tooling).splitlines()
    allowed = ('scripts/ci/e858_audit', 'scripts/ops/e858_audit_packet.py',
               'tests/ci/test_e858_audit.py', '.github/workflows/e858-source-bound-audit.yml',
               'docs/development/integration/2026-10-18-b/e858-source-bound-audit.md')
    if any(not any(p == a or (a.endswith('e858_audit') and p.startswith(a)) for a in allowed) for p in changed):
        raise Block('TOOLING_SCOPE_REJECTED')
    for path in LEGACY:
        if git(root, 'rev-parse', candidate + ':' + path) != git(root, 'rev-parse', tooling + ':' + path):
            raise Block('LEGACY_SOURCE_CHANGED')
    inputs = LEGACY + IMPLEMENTATION + SOURCE_FILES
    inputs += [p for p in git(root, 'ls-tree', '-r', '--name-only', candidate).splitlines() if p.startswith(PREFIXES)]
    blobs = {p: blob_file(root, p, tooling) for p in sorted(set(inputs))}
    return facts, blobs


def queries(root=ROOT):
    directory = root / 'scripts/ci/e858_audit/queries'
    if {p.name for p in directory.iterdir()} != {k + '.sql' for k in CAPS}:
        raise Block('SQL_BUNDLE_REJECTED')
    result = {}
    for key in CAPS:
        raw = (directory / (key + '.sql')).read_bytes()
        # Each immutable file is a SINGLE statement; no arbitrary SQL ingress.
        if (not raw.isascii() or len(raw) > 8192 or raw.count(b';') != 1 or not raw.endswith(b';\n')
                or (key != 'grants' and not raw.startswith(b'SELECT '))
                or (key == 'grants' and raw != b'SHOW GRANTS FOR CURRENT_USER();\n')):
            raise Block('SQL_BUNDLE_REJECTED')
        result[key] = raw
    return result


def query_hashes(root=ROOT):
    return {k: hashlib.sha256(v).hexdigest() for k, v in queries(root).items()}


def model(raw, supplemental, extra):
    shape = projections(raw)
    semantic, definitions = semantic_parse(supplemental, raw)
    rows = [line.split('\t') for line in extra.splitlines()]
    columns = [line.split('\t') for line in raw.splitlines() if line.startswith('column\t')]
    if (not extra.isascii() or len(extra.encode()) > 8*1024*1024 or not extra.endswith('\n')
            or any(ord(c)<32 and c not in '\t\n' for c in extra)
            or any(len(r) != 6 or r[0] != 'column_extra' for r in rows)
            or [(r[1], r[2], r[3]) for r in rows] != [(r[1], r[2], r[3]) for r in columns]
            or any(not re.fullmatch('[0-9A-F]*', r[5]) for r in rows)):
        raise Block('COLUMN_SUPPLEMENT_REJECTED')
    return dict(physical=shape['physical'], projections=shape['projections'],
                semantics=semantic['semantics'], dependency_sha256=semantic['dependency_sha256'],
                extended_strict_sha256=hashed([supplemental.splitlines(), extra.splitlines()])), definitions


def absence(before, after):
    # Reuse the immutable source-derived contract algorithm, not its old pin.
    def rows(raw, label):
        return [r.split('\t') for r in raw.splitlines() if r.startswith(label+'\t')]
    tables={r[1] for r in rows(before,'table')}
    columns={(r[1],r[3]) for r in rows(before,'column')}
    indexes={(r[1],r[2]) for r in rows(before,'index')}
    fk={r[2] for r in rows(before,'foreign_key')}
    result=dict(absent_tables=sorted({r[1] for r in rows(after,'table')}-tables),
                absent_columns={},absent_index_names={},
                absent_foreign_key_names=sorted({r[2] for r in rows(after,'foreign_key')}-fk),
                absent_trigger_names=sorted({r[2] for r in rows(after,'trigger')}))
    for row in rows(after,'column'):
        if row[1] in tables and (row[1],row[3]) not in columns:
            result['absent_columns'].setdefault(row[1],[]).append(row[3])
    for table,name in sorted({(r[1],r[2]) for r in rows(after,'index')}-indexes):
        if table in tables:result['absent_index_names'].setdefault(table,[]).append(name)
    return result


def audit(facts, ledger_before, ledger_after, raw, supplemental, extra, reference, contract):
    if ledger_before != ledger_after:
        raise Block('LEDGER_CHANGED')
    observed, definitions = model(raw, supplemental, extra)
    stage = ('baseline94' if set(ledger_before)==set(facts['baseline']) else
             'candidate114' if set(ledger_before)==set(facts['candidate']) else None)
    legacy=dict(kind='DISPOSABLE_SOURCE_REFERENCE_ONLY',baseline_sha=BASE,migration_seal=SEAL,
                engine=reference['engine'],baseline94=reference['baseline94']['physical'],
                candidate114=reference['candidate114']['physical'])
    original=compare(facts, ledger_before, observed['physical'], legacy)
    strict=original['result']
    sections=[]
    if stage:
        expected=reference[stage]
        if expected['extended_strict_sha256']!=observed['extended_strict_sha256']:
            strict='BLOCK'
        for section in ('index','foreign_key'):
            before,after=expected['semantics'][section],observed['semantics'][section]
            classification=('SEMANTIC_DEFINITION_DIFFERENCE' if before!=after else
                            'MATCH' if expected['physical']['sections'][section]==observed['physical']['sections'][section]
                            else 'NAME_ONLY_DIFFERENCE')
            if before!=after:strict='BLOCK'
            sections.append(dict(section=section,classification=classification,
                                 expected_object_count=before['object_count'],observed_object_count=after['object_count'],
                                 expected_row_count=before['row_count'],observed_row_count=after['row_count']))
    def collisions():
        rows=[r.split('\t') for r in raw.splitlines()]
        return sum(r[1].lower() in {n.lower() for n in contract['absent_tables']} for r in rows if r[0]=='table') + sum(
            r[3].lower() in {n.lower() for n in contract['absent_columns'].get(r[1],[])} for r in rows if r[0]=='column') + sum(
            r[2].lower() in {n.lower() for n in contract['absent_index_names'].get(r[1],[])} for r in rows if r[0]=='index') + sum(
            r[2].lower() in {n.lower() for n in contract['absent_foreign_key_names']} for r in rows if r[0]=='foreign_key') + sum(
            r[2].lower() in {n.lower() for n in contract['absent_trigger_names']} for r in rows if r[0]=='trigger')
    count=collisions() if stage=='baseline94' else None
    expected_dependency=reference['baseline94']['dependency_sha256']
    actual=definitions['index'].get(DEPENDENCY_KEY)
    aliases=list(definitions['index'].values()).count(expected_dependency)
    dependency=('EXPECTED_NAME_AND_DEFINITION' if actual==expected_dependency and aliases==1 else 'MISSING_INCOMPATIBLE_OR_AMBIGUOUS_BLOCK') if stage=='baseline94' else 'NOT_PREMIGRATION94'
    if stage=='baseline94' and (count or dependency!='EXPECTED_NAME_AND_DEFINITION'):strict='BLOCK'
    return dict(result='BLOCK_REAL_GATES_UNVERIFIED',stage=stage or 'UNKNOWN_OR_PARTIAL',
                original_strict_result=original['result'],strict_result=strict,strict_code=original['code'],
                applied_count=len(ledger_before),semantic_sections=sections,
                pending_collision_count=count,m08_name_dependency=dependency,
                identity_data_collision='UNVERIFIED_METADATA_ONLY',trigger_visibility='UNVERIFIED',
                runtime_ddl_binlog='UNVERIFIED',backup_restore='UNVERIFIED',writer_quiescence='UNVERIFIED',
                real_aiven_connected=False,production_modified=False,strict_block_waived=False,release_authorized=False)


def reference_binding(facts, blobs, generator):
    return dict(kind=KIND,candidate_sha=CANDIDATE,candidate_tree=TREE,release_ref=RELEASE,
                migration_seal=SEAL,normalization=NORMALIZATION,generator_tooling_sha=generator,
                source_manifest_sha256=hashed(facts['candidate']),
                expected_sets_sha256=hashed({k:sorted(facts[k]) for k in ('baseline','candidate')}),
                protected_blobs=blobs,sql_hashes=query_hashes(),sql_bundle_sha256=hashed(query_hashes()))


def load_reference(root, series, facts, blobs, tooling):
    path=f'scripts/ci/e858_audit_reference/{series}.json'
    blob_file(root,path,tooling)
    ref=json.loads((root/path).read_text())
    expected=reference_binding(facts,blobs,ref['generator_tooling_sha'])
    if any(ref.get(k)!=v for k,v in expected.items()) or ref.get('engine')!=series or ref.get('independent_build_count')!=2:
        raise Block('REFERENCE_BINDING_REJECTED')
    git(root,'merge-base','--is-ancestor',ref['generator_tooling_sha'],tooling)
    for path,blob in blobs.items():
        if git(root,'rev-parse',ref['generator_tooling_sha']+':'+path)!=blob:
            raise Block('GENERATOR_SOURCE_REJECTED')
    return ref


def environment_guard():
    if any(os.environ.get(k) for k in ('MYSQL_PWD','MYSQL_TEST_LOGIN_FILE','MYSQL_HOST','MYSQL_TCP_PORT',
            'MYSQL_HOME','DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD','DB_URL','DATABASE_URL',
            'CANOVIA_P0_LOGIN_PATH','CANOVIA_P0_DATABASE','CANOVIA_P0_CA_FILE')):
        raise Block('ENVIRONMENT_OVERRIDE_REJECTED')
