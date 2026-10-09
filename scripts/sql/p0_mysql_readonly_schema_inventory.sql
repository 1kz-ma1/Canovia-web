-- Canovia P0 MySQL schema/migration inventory, READ ONLY.
-- Execute only against the privately verified intended production database.
-- Every statement is SELECT, no SET, DDL, DML, user rows, or credentials.
-- Do NOT treat missing results as permission to run migrations.
-- Do NOT paste connection strings, credentials, raw user data or cookies into issues.

SELECT 'connection' AS section,
       'mysql_version' AS object_id,
       VERSION() AS observed_value,
       IF(DATABASE() IS NULL, 'NO_SCHEMA_SELECTED', 'SCHEMA_SELECTED') AS status;

SELECT 'table' AS section,
       expected.table_name AS object_id,
       IF(actual.TABLE_NAME IS NULL, 'MISSING', 'PRESENT') AS status
FROM (
    SELECT 'migrations' AS table_name
    UNION ALL SELECT 'users'
    UNION ALL SELECT 'intelligence_state_snapshots'
    UNION ALL SELECT 'intelligence_decision_traces'
    UNION ALL SELECT 'learning_answer_events'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments'
) AS expected
LEFT JOIN information_schema.TABLES AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

SELECT 'column' AS section,
       CONCAT(expected.table_name, '.', expected.column_name) AS object_id,
       IF(actual.COLUMN_NAME IS NULL, 'MISSING', 'PRESENT') AS status
FROM (
    SELECT 'intelligence_decision_traces' AS table_name, 'id' AS column_name
    UNION ALL SELECT 'intelligence_decision_traces', 'intelligence_state_snapshot_id'
    UNION ALL SELECT 'intelligence_decision_traces', 'decision_reference'
    UNION ALL SELECT 'intelligence_decision_traces', 'domain'
    UNION ALL SELECT 'intelligence_decision_traces', 'scope_type'
    UNION ALL SELECT 'intelligence_decision_traces', 'scope_id'
    UNION ALL SELECT 'intelligence_decision_traces', 'created_at'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments', 'id'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments', 'learning_answer_event_id'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments', 'user_id'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments', 'reason'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments', 'effect'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments', 'actor_token'
    UNION ALL SELECT 'learning_answer_evaluation_adjustments', 'created_at'
) AS expected
LEFT JOIN information_schema.COLUMNS AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name;

SELECT 'foreign_key' AS section,
       expected.expected_name AS object_id,
       COALESCE(actual.CONSTRAINT_NAME, 'NOT_FOUND') AS observed_name,
       CASE
         WHEN actual.CONSTRAINT_NAME IS NULL THEN 'MISSING'
         WHEN actual.CONSTRAINT_NAME = expected.expected_name
          AND actual.REFERENCED_TABLE_NAME = expected.parent_table
          AND actual.REFERENCED_COLUMN_NAME = 'id'
          AND referential.DELETE_RULE = expected.delete_rule
         THEN 'PASS'
         ELSE 'MISMATCH'
       END AS status
FROM (
    SELECT 'intelligence_decision_traces' AS table_name,
           'intelligence_state_snapshot_id' AS column_name,
           'idt_snapshot_fk' AS expected_name,
           'intelligence_state_snapshots' AS parent_table,
           'CASCADE' AS delete_rule
    UNION ALL
    SELECT 'learning_answer_evaluation_adjustments',
           'learning_answer_event_id',
           'laea_answer_event_fk',
           'learning_answer_events',
           'CASCADE'
    UNION ALL
    SELECT 'learning_answer_evaluation_adjustments',
           'user_id',
           'laea_user_fk',
           'users',
           'SET NULL'
) AS expected
LEFT JOIN information_schema.KEY_COLUMN_USAGE AS actual
  ON actual.CONSTRAINT_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.COLUMN_NAME = expected.column_name
 AND actual.REFERENCED_TABLE_NAME IS NOT NULL
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS referential
  ON referential.CONSTRAINT_SCHEMA = actual.CONSTRAINT_SCHEMA
 AND referential.TABLE_NAME = actual.TABLE_NAME
 AND referential.CONSTRAINT_NAME = actual.CONSTRAINT_NAME
ORDER BY expected.table_name, expected.column_name;

SELECT 'index' AS section,
       expected.index_name AS object_id,
       CASE
         WHEN actual.INDEX_NAME IS NULL THEN 'MISSING'
         WHEN actual.index_columns = expected.index_columns
          AND actual.NON_UNIQUE = expected.non_unique
         THEN 'PASS'
         ELSE 'MISMATCH'
       END AS status
FROM (
    SELECT 'learning_answer_evaluation_adjustments' AS table_name,
           'learning_eval_adjustment_event_unique' AS index_name,
           'learning_answer_event_id' AS index_columns,
           0 AS non_unique
    UNION ALL
    SELECT 'intelligence_decision_traces',
           'intelligence_decision_traces_decision_reference_unique',
           'decision_reference',
           0
    UNION ALL
    SELECT 'intelligence_decision_traces',
           'intelligence_decision_scope_created_idx',
           'domain,scope_type,scope_id,created_at',
           1
    UNION ALL
    SELECT 'intelligence_decision_traces',
           'intelligence_decision_plan_domain_idx',
           'plan_id,domain,created_at',
           1
) AS expected
LEFT JOIN (
    SELECT TABLE_NAME, INDEX_NAME,
           GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns,
           MAX(NON_UNIQUE) AS NON_UNIQUE
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN (
          'intelligence_decision_traces',
          'learning_answer_evaluation_adjustments'
      )
    GROUP BY TABLE_NAME, INDEX_NAME
) AS actual
  ON actual.TABLE_NAME = expected.table_name
 AND actual.INDEX_NAME = expected.index_name
ORDER BY expected.table_name, expected.index_name;

-- The Laravel migration ledger contains only migration identifiers and batches.
-- A missing migrations table causes this SELECT to fail, which is a blocker.
SELECT 'migration' AS section,
       expected.migration AS object_id,
       COALESCE(CAST(applied.batch AS CHAR), 'NONE') AS observed_batch,
       IF(applied.migration IS NULL, 'PENDING', 'APPLIED') AS status
FROM (
    SELECT '2026_10_04_000200_create_intelligence_decision_traces_table' AS migration
    UNION ALL SELECT '2026_10_05_000400_reconcile_intelligence_schema_constraints'
    UNION ALL SELECT '2026_10_08_230000_create_learning_answer_evaluation_adjustments'
) AS expected
LEFT JOIN migrations AS applied ON applied.migration = expected.migration
ORDER BY expected.migration;
