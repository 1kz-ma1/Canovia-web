#!/usr/bin/env python3
"""Offline, additive schema diagnosis. Original exact-hash verdict is authoritative.

Only fixed section IDs, enums, booleans and aggregate counts leave this module.
Projection hashes and object identifiers remain private; they never authorize DDL.
"""
import hashlib
import json
from pathlib import Path
import sys

sys.dont_write_bytecode = True
from integrated_release20_audit import BASE, WIDTHS, compare, snapshot, InvalidLedger

CANDIDATE = 'c09a8fc718c338f98a4045a4b5f2260c7571fdaa'
RELEASE_REF = 'release/2026-10-19-c09a8fc7'
KIND = 'DISPOSABLE_DIAGNOSTIC_PROJECTIONS_ONLY'
IDENTITY = {'table': (1,), 'column': (1, 3), 'index': (1, 2, 4),
            'foreign_key': (1, 2, 3), 'check': (1, 2), 'trigger': (1, 2)}
COLLATION = {'table': 4, 'column': 8}
ROOT = Path(__file__).resolve().parents[2]


def digest(rows):
    return hashlib.sha256(json.dumps(sorted(rows), separators=(',', ':')).encode()).hexdigest()


def projections(raw):
    # Always apply the original strict parser first. Never normalize its input
    # or substitute a relaxed digest into the original comparison.
    physical = snapshot(raw)
    rows = {key: [] for key in WIDTHS}
    for line in raw.splitlines():
        cells = tuple(line.split('\t'))
        if cells[0] != 'engine':
            rows[cells[0]].append(cells)
    projected = {}
    for section, entries in rows.items():
        identities = [tuple(row[i] for i in IDENTITY[section]) for row in entries]
        if len(set(identities)) != len(entries):
            raise InvalidLedger()
        pos = COLLATION.get(section)
        projected[section] = dict(
            identity=digest(identities),
            without_collation=digest([tuple(v for i, v in enumerate(row) if i != pos) for row in entries]),
            collations=digest([identity + (row[pos],) for identity, row in zip(identities, entries)])
            if pos is not None else None)
    return {'physical': physical, 'projections': projected}


def diagnose(src, ledger, raw, reference, diagnostic_reference):
    observed = projections(raw)
    result = compare(src, ledger, observed['physical'], reference)
    result['diagnosis_authorizes_release'] = False
    result['trigger_visibility'] = 'ABSENCE_OR_INVISIBILITY_NOT_DISTINGUISHABLE'
    result['metadata_visibility'] = 'NOT_INDEPENDENTLY_PROVEN'
    # No stage is guessed for unknown/partial history. Retain that exact BLOCK.
    applied = set(ledger)
    stage = ('baseline94' if applied == set(src['baseline']) else
             'candidate114' if applied == set(src['candidate']) else None)
    try:
        if (diagnostic_reference['kind'] != KIND or diagnostic_reference['candidate_sha'] != CANDIDATE
                or diagnostic_reference['baseline_sha'] != BASE
                or diagnostic_reference['migration_seal'] != src['migration_seal']
                or diagnostic_reference['engine'] != observed['physical']['engine']):
            raise InvalidLedger()
        if stage is None:
            result['diagnosis_code'] = 'LEDGER_NOT_AT_REFERENCE_BOUNDARY'
            return result
        expected = diagnostic_reference[stage]
        # Bind auxiliary projections to the UNMODIFIED original reference.
        if expected['physical'] != reference[stage]:
            raise InvalidLedger()
        if set(expected['projections']) != set(WIDTHS):
            raise InvalidLedger()
        sections = []
        for section in WIDTHS:
            before, after = reference[stage]['sections'][section], observed['physical']['sections'][section]
            aux, obs = expected['projections'][section], observed['projections'][section]
            if set(aux) != {'identity', 'without_collation', 'collations'}:
                raise InvalidLedger()
            for key in ('identity', 'without_collation', 'collations'):
                value = aux[key]
                if key == 'collations' and section not in COLLATION:
                    if value is not None:
                        raise InvalidLedger()
                elif not isinstance(value, str) or len(value) != 64 or any(c not in '0123456789abcdef' for c in value):
                    raise InvalidLedger()
            collation_diff = None
            if before == after:
                classification = 'MATCH'
            elif before['count'] != after['count']:
                classification = 'COUNT_DIFFERENCE'
            elif aux['identity'] != obs['identity']:
                classification = 'OBJECT_IDENTITY_DIFFERENCE'
            else:
                collation_diff = aux['collations'] != obs['collations'] if section in COLLATION else None
                classification = ('COLLATION_ONLY_DIFFERENCE' if section in COLLATION
                                  and aux['without_collation'] == obs['without_collation']
                                  else 'DEFINITION_DIFFERENCE')
            sections.append(dict(section=section, classification=classification,
                                 expected_count=before['count'], observed_count=after['count'],
                                 collation_difference=collation_diff))
        result['diagnosis_code'] = 'SECTION_CLASSIFICATION_ONLY'
        result['sections'] = sections
        return result
    except (InvalidLedger, KeyError, TypeError, ValueError):
        # Auxiliary evidence can only make the decision stricter.
        result.update(result='BLOCK', diagnosis_code='DIAGNOSTIC_REFERENCE_REJECTED')
        return result
