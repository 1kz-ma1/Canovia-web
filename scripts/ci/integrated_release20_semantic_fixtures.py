"""Called ONLY inside the labelled disposable MySQL harness, never by operators."""
from integrated_release20_audit import snapshot
from integrated_release20_diagnose import CANDIDATE, projections
from integrated_release20_semantics import parse, semantic_diagnose


def canonical_cases(sql, src, ledger, legacy, reference, diagnostic, semantic, metadata_sql, supplement):
    reports = {}

    def check(label, expected):
        raw = sql(metadata_sql)
        result = semantic_diagnose(src, ledger, raw, reference, diagnostic, sql(supplement), semantic)
        actual = {row['section']: row['classification'] for row in result.get('semantic_sections', [])}
        if actual != expected or result['release_authorized']:
            raise ValueError()
        if any(v != 'MATCH' for v in expected.values()) and result['result'] != 'BLOCK':
            raise ValueError()
        reports[label] = dict(result=result['result'], classifications=actual)
        return result

    check('semantic_normal94', dict(index='MATCH', foreign_key='MATCH'))
    # Names used here are fixtures from public source, never private output.
    table = 'intelligence_decision_traces'
    index = 'intelligence_decision_traces_domain_index'
    sql(f'ALTER TABLE {table} RENAME INDEX {index} TO diagnostic_alias_idx')
    fk = next(row.split('\t') for row in legacy.splitlines() if row.startswith('foreign_key\t' + table + '\tintelligence_decision_traces_user_id_foreign\t'))
    if fk[7] not in {'NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL'} or fk[8] != 'SET NULL':
        raise ValueError()
    sql(f'ALTER TABLE {table} DROP FOREIGN KEY intelligence_decision_traces_user_id_foreign, ADD CONSTRAINT diagnostic_alias_fk FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE {fk[7]} ON DELETE {fk[8]}')
    check('semantic_names_only94', dict(index='NAME_ONLY_DIFFERENCE', foreign_key='NAME_ONLY_DIFFERENCE'))
    sql(f'ALTER TABLE {table} DROP FOREIGN KEY diagnostic_alias_fk, ADD CONSTRAINT intelligence_decision_traces_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE {fk[7]} ON DELETE {fk[8]}')
    sql(f'ALTER TABLE {table} RENAME INDEX diagnostic_alias_idx TO {index}')
    old = 'mcp_linked_subjects_identity_fingerprint_unique'
    sql(f'ALTER TABLE mcp_linked_subjects RENAME INDEX {old} TO diagnostic_identity_alias')
    result = check('semantic_named_transition_alias94', dict(index='NAME_ONLY_DIFFERENCE', foreign_key='MATCH'))
    if result['migration_name_dependency']['classification'] != 'SEMANTIC_ALIAS_ONLY_BLOCK':
        raise ValueError()
    sql(f'ALTER TABLE mcp_linked_subjects RENAME INDEX diagnostic_identity_alias TO {old}')
    if snapshot(sql(metadata_sql)) != reference['baseline94']:
        raise ValueError()
    return reports


def structural_cases(sql, metadata_sql, supplement):
    """Additional synthetic composite structures, outside canonical references.

    The per-fixture reference is in memory only. It NEVER replaces the reviewed
    canonical 94/114 hashes. No source/reference generation uses these tables.
    """
    reports = {}
    sql('''CREATE TABLE diagnostic_parent (
      a INT NOT NULL, b INT NOT NULL, txt VARCHAR(100),
      PRIMARY KEY (a), UNIQUE KEY parent_pair (a,b), UNIQUE KEY parent_reverse (b,a),
      KEY text_prefix (txt(12)), KEY expression_key ((a+1)), KEY ordered_key (a,b),
      KEY visibility_key (b));
      CREATE TABLE diagnostic_other LIKE diagnostic_parent;
      CREATE TABLE diagnostic_child (a INT NOT NULL, b INT NOT NULL,
      KEY child_pair (a,b), KEY child_reverse (b,a),
      CONSTRAINT diagnostic_fk FOREIGN KEY (a,b) REFERENCES diagnostic_parent(a,b)
      ON UPDATE RESTRICT ON DELETE RESTRICT);''')
    def capture():
        return parse(sql(supplement), sql(metadata_sql))[0]['semantics']
    before = capture()

    def mutation(label, statement, restore, section):
        sql(statement)
        after = capture()
        if before[section] == after[section] or before[section]['row_count'] != after[section]['row_count']:
            raise ValueError()
        reports[label] = dict(classification='SEMANTIC_DEFINITION_DIFFERENCE',
                              equal_row_count=True, definition_rejected=True)
        sql(restore)
        if capture() != before:
            raise ValueError()

    try:
        mutation('index_order', 'ALTER TABLE diagnostic_parent DROP INDEX ordered_key, ADD KEY ordered_key(b,a)',
                 'ALTER TABLE diagnostic_parent DROP INDEX ordered_key, ADD KEY ordered_key(a,b)', 'index')
        mutation('index_unique', 'ALTER TABLE diagnostic_parent DROP INDEX ordered_key, ADD UNIQUE KEY ordered_key(a,b)',
                 'ALTER TABLE diagnostic_parent DROP INDEX ordered_key, ADD KEY ordered_key(a,b)', 'index')
        mutation('index_prefix', 'ALTER TABLE diagnostic_parent DROP INDEX text_prefix, ADD KEY text_prefix(txt(16))',
                 'ALTER TABLE diagnostic_parent DROP INDEX text_prefix, ADD KEY text_prefix(txt(12))', 'index')
        mutation('index_expression', 'ALTER TABLE diagnostic_parent DROP INDEX expression_key, ADD KEY expression_key((a+2))',
                 'ALTER TABLE diagnostic_parent DROP INDEX expression_key, ADD KEY expression_key((a+1))', 'index')
        mutation('index_direction', 'ALTER TABLE diagnostic_parent DROP INDEX ordered_key, ADD KEY ordered_key(a DESC,b)',
                 'ALTER TABLE diagnostic_parent DROP INDEX ordered_key, ADD KEY ordered_key(a,b)', 'index')
        mutation('index_visibility', 'ALTER TABLE diagnostic_parent ALTER INDEX visibility_key INVISIBLE',
                 'ALTER TABLE diagnostic_parent ALTER INDEX visibility_key VISIBLE', 'index')
        # Equal row count + equal object count, but one missing and another
        # different (duplicate) constraint added. Multiset must reject it.
        mutation('index_missing_replaced', 'ALTER TABLE diagnostic_parent DROP INDEX text_prefix, ADD KEY replacement_key(b)',
                 'ALTER TABLE diagnostic_parent DROP INDEX replacement_key, ADD KEY text_prefix(txt(12))', 'index')
        prefix = 'ALTER TABLE diagnostic_child DROP FOREIGN KEY diagnostic_fk; ALTER TABLE diagnostic_child ADD CONSTRAINT diagnostic_fk FOREIGN KEY '
        original = prefix + '(a,b) REFERENCES diagnostic_parent(a,b) ON UPDATE RESTRICT ON DELETE RESTRICT'
        for label, definition in {
            'foreign_child_order': '(b,a) REFERENCES diagnostic_parent(a,b) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'foreign_reference_order': '(a,b) REFERENCES diagnostic_parent(b,a) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'foreign_reference_table': '(a,b) REFERENCES diagnostic_other(a,b) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'foreign_update': '(a,b) REFERENCES diagnostic_parent(a,b) ON UPDATE CASCADE ON DELETE RESTRICT',
            'foreign_delete': '(a,b) REFERENCES diagnostic_parent(a,b) ON UPDATE RESTRICT ON DELETE CASCADE',
        }.items():
            mutation(label, prefix + definition, original, 'foreign_key')
        sql('ALTER TABLE diagnostic_child ADD CONSTRAINT diagnostic_fk_second FOREIGN KEY (a,b) REFERENCES diagnostic_other(a,b) ON UPDATE RESTRICT ON DELETE RESTRICT')
        before = capture()
        mutation('foreign_missing_replaced',
                 'ALTER TABLE diagnostic_child DROP FOREIGN KEY diagnostic_fk, ADD CONSTRAINT replacement_fk FOREIGN KEY (a,b) REFERENCES diagnostic_other(a,b) ON UPDATE RESTRICT ON DELETE RESTRICT',
                 'ALTER TABLE diagnostic_child DROP FOREIGN KEY replacement_fk, ADD CONSTRAINT diagnostic_fk FOREIGN KEY (a,b) REFERENCES diagnostic_parent(a,b) ON UPDATE RESTRICT ON DELETE RESTRICT',
                 'foreign_key')
    finally:
        sql('DROP TABLE diagnostic_child; DROP TABLE diagnostic_other; DROP TABLE diagnostic_parent')
    return reports
