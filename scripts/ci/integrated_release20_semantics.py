#!/usr/bin/env python3
"""Private name-independent MULTISET comparison, additive to strict diagnosis.

Only constraint names are excluded. Tables, ordered parts, primary status,
expressions (exact bytes), uniqueness, prefix lengths, direction, type,
visibility, nullability, references, actions and match option remain compared.
"""
from collections import defaultdict
import re
import sys

sys.dont_write_bytecode = True
from integrated_release20_audit import BASE, MAX_BYTES, InvalidLedger, snapshot
from integrated_release20_diagnose import CANDIDATE, digest, diagnose

KIND = 'DISPOSABLE_CONSTRAINT_SEMANTICS_ONLY_V1'
SECTIONS = ('index', 'foreign_key')
# The fixed candidate's one historical name-dependent UP transition. Never
# print these private lookup values; output uses a fixed migration-risk ID.
DEPENDENCY_ID = 'RELEASE20_IDENTITY_UNIQUE_TRANSITION'
DEPENDENCY_KEY = ('mcp_linked_subjects', 'mcp_linked_subjects_identity_fingerprint_unique')


def parse(raw, legacy_raw):
    legacy = snapshot(legacy_raw)
    if (not raw or len(raw.encode()) > MAX_BYTES or not raw.isascii()
            or '\r' in raw or '\0' in raw or not raw.endswith('\n')):
        raise InvalidLedger()
    groups = {section: defaultdict(list) for section in SECTIONS}
    rows = {section: [] for section in SECTIONS}
    version = False
    for line in raw.splitlines():
        cells = tuple(line.split('\t'))
        if cells == ('semantic_version', '1') and not version:
            version = True
            continue
        section = {'semantic_index': 'index', 'semantic_foreign_key': 'foreign_key'}.get(cells[0])
        if section is None or len(cells) != 12 or any(ord(c) < 32 or ord(c) == 127 for v in cells for c in v):
            raise InvalidLedger()
        if not cells[1] or not cells[2] or not re.fullmatch('[1-9][0-9]{0,4}', cells[4 if section == 'index' else 3]):
            raise InvalidLedger()
        row = (section,) + cells[1:]
        rows[section].append(row[:10])
        groups[section][(row[1], row[2])].append(row)
    if not version:
        raise InvalidLedger()
    original = {s: [] for s in SECTIONS}
    for line in legacy_raw.splitlines():
        row = tuple(line.split('\t'))
        if row[0] in original:
            original[row[0]].append(row)
    # Reject truncated, repeated, cross-database or independently changed
    # metadata instead of comparing a different schema to the strict verdict.
    if any(sorted(rows[s]) != sorted(original[s]) for s in SECTIONS):
        raise InvalidLedger()
    definitions, summaries = {}, {}
    for section in SECTIONS:
        definitions[section] = {}
        for key, parts in groups[section].items():
            seqpos = 4 if section == 'index' else 3
            parts.sort(key=lambda row: int(row[seqpos]))
            if [int(row[seqpos]) for row in parts] != list(range(1, len(parts) + 1)):
                raise InvalidLedger()
            if section == 'index':
                # A primary key cannot be treated as an ordinary unique alias.
                properties = (3, 7, 8)
                header = (key[0], key[1] == 'PRIMARY')
                if key[1] == 'PRIMARY' and parts[0][3] != '0':
                    raise InvalidLedger()
            else:
                properties = (5, 7, 8, 9, 10)
                header = (key[0],)
            if any(len({row[p] for row in parts}) != 1 for p in properties):
                raise InvalidLedger()
            # Keep each composite object's grouping and ordered parts. Sorting
            # a set of rows would lose multiplicity and allow cancellation.
            definition = (header, tuple(row[3:] for row in parts))
            definitions[section][key] = digest([definition])
        signatures = list(definitions[section].values())  # NOT a set
        summaries[section] = dict(object_count=len(signatures), row_count=len(rows[section]),
                                  sha256=digest(signatures))
    dependency = definitions['index'].get(DEPENDENCY_KEY)
    return dict(physical=legacy, semantics=summaries, dependency_sha256=dependency), definitions


def semantic_diagnose(src, ledger, legacy_raw, reference, diagnostic_reference, raw, semantic_reference):
    result = diagnose(src, ledger, legacy_raw, reference, diagnostic_reference)
    try:
        observed, definitions = parse(raw, legacy_raw)
        applied = set(ledger)
        stage = ('baseline94' if applied == set(src['baseline']) else
                 'candidate114' if applied == set(src['candidate']) else None)
        if (semantic_reference['kind'] != KIND or semantic_reference['candidate_sha'] != CANDIDATE
                or semantic_reference['baseline_sha'] != BASE
                or semantic_reference['migration_seal'] != src['migration_seal']
                or semantic_reference['engine'] != observed['physical']['engine']):
            raise InvalidLedger()
        if stage is None:
            result['semantic_code'] = 'LEDGER_NOT_AT_REFERENCE_BOUNDARY'
            return result
        expected = semantic_reference[stage]
        if expected['physical'] != reference[stage] or set(expected['semantics']) != set(SECTIONS):
            raise InvalidLedger()
        sections = []
        for section in SECTIONS:
            before, after = expected['semantics'][section], observed['semantics'][section]
            if (set(before) != {'object_count', 'row_count', 'sha256'}
                    or any(type(before[k]) is not int or before[k] < 0 for k in ('object_count', 'row_count'))
                    or not re.fullmatch('[0-9a-f]{64}', before['sha256'])):
                raise InvalidLedger()
            if before != after:
                classification = 'SEMANTIC_DEFINITION_DIFFERENCE'
                # Additional fields can tighten a legacy match, never relax it.
                if result['result'] != 'BLOCK':
                    result.update(result='BLOCK', code='SUPPLEMENTAL_CONSTRAINT_DIFFERENCE')
            elif reference[stage]['sections'][section] == observed['physical']['sections'][section]:
                classification = 'MATCH'
            else:
                classification = 'NAME_ONLY_DIFFERENCE'
            sections.append(dict(section=section, classification=classification,
                                 expected_object_count=before['object_count'], observed_object_count=after['object_count'],
                                 expected_row_count=before['row_count'], observed_row_count=after['row_count']))
        result.update(semantic_code='CONSTRAINT_SEMANTICS_CLASSIFIED', semantic_sections=sections,
                      name_dependent_up_migration_count=1, name_dependent_up_foreign_key_count=0,
                      future_constraint_name_collisions_verified=False)
        if stage == 'baseline94':
            wanted = expected['dependency_sha256']
            if not isinstance(wanted, str) or not re.fullmatch('[0-9a-f]{64}', wanted):
                raise InvalidLedger()
            actual = definitions['index'].get(DEPENDENCY_KEY)
            aliases = list(definitions['index'].values()).count(wanted)
            status = ('EXPECTED_NAME_AND_DEFINITION' if actual == wanted and aliases == 1 else
                      'SEMANTIC_ALIAS_ONLY_BLOCK' if actual is None and aliases == 1 else
                      'MISSING_INCOMPATIBLE_OR_AMBIGUOUS_BLOCK')
            result['migration_name_dependency'] = dict(dependency=DEPENDENCY_ID, classification=status)
            if status != 'EXPECTED_NAME_AND_DEFINITION':
                result['result'] = 'BLOCK'
        else:
            result['migration_name_dependency'] = dict(dependency=DEPENDENCY_ID, classification='HISTORICAL_TRANSITION_NOT_PROVEN')
        return result
    except (InvalidLedger, KeyError, TypeError, ValueError):
        result.update(result='BLOCK', semantic_code='SEMANTIC_REFERENCE_OR_PRIVATE_INPUT_REJECTED')
        # Do not emit partially constructed classifications after invalid input.
        result.pop('semantic_sections', None)
        result.pop('migration_name_dependency', None)
        return result
