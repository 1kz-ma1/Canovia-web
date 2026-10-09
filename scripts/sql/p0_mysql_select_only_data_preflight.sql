-- P0 operator-local data/metadata preflight: SELECT ONLY, no Laravel boot.
-- Requires an independently verified DB/schema AND existing SELECT-only account.
-- Run only AFTER the existing incident inventory and full-ledger comparison.
-- SELECTs contain static public labels, fixed PASS/BLOCK and boolean existence.
-- Never export row values, account identifiers, credentials or raw errors.
-- On missing tables/columns SQL errors are BLOCK, not approval.

SELECT 'engine', 'mysql8', CASE
  WHEN LEFT(VERSION(), 2) = '8.' THEN 'PASS' ELSE 'BLOCK' END;

-- Reference-column type, nullable-delete-action, and database-wide symbol
-- checks. An existing wrongly-formed FK is caught by incident inventory.
SELECT 'fk_metadata', expected.label,
  CASE
    WHEN child.COLUMN_NAME IS NULL OR parent.COLUMN_NAME IS NULL THEN 'BLOCK'
    WHEN LOWER(child.COLUMN_TYPE) <> LOWER(parent.COLUMN_TYPE) THEN 'BLOCK'
    WHEN expected.delete_rule = 'SET NULL' AND child.IS_NULLABLE <> 'YES' THEN 'BLOCK'
    WHEN EXISTS (
      SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS AS collision
      WHERE collision.CONSTRAINT_SCHEMA = DATABASE()
        AND collision.CONSTRAINT_NAME = expected.fk_name
        AND collision.TABLE_NAME <> expected.child_table
    ) THEN 'BLOCK'
    ELSE 'PASS'
  END AS status
FROM (
  SELECT 'idt_user' AS label, 'intelligence_decision_traces' AS child_table,
    'user_id' AS child_column, 'users' AS parent_table,
    'idt_user_fk' AS fk_name, 'SET NULL' AS delete_rule
  UNION ALL SELECT 'idt_plan', 'intelligence_decision_traces', 'plan_id',
    'plans', 'idt_plan_fk', 'SET NULL'
  UNION ALL SELECT 'idt_snapshot', 'intelligence_decision_traces',
    'intelligence_state_snapshot_id', 'intelligence_state_snapshots',
    'idt_snapshot_fk', 'CASCADE'
  UNION ALL SELECT 'laea_event', 'learning_answer_evaluation_adjustments',
    'learning_answer_event_id', 'learning_answer_events',
    'laea_answer_event_fk', 'CASCADE'
  UNION ALL SELECT 'laea_user', 'learning_answer_evaluation_adjustments',
    'user_id', 'users', 'laea_user_fk', 'SET NULL'
) AS expected
LEFT JOIN information_schema.COLUMNS AS child
  ON child.TABLE_SCHEMA = DATABASE()
 AND child.TABLE_NAME = expected.child_table
 AND child.COLUMN_NAME = expected.child_column
LEFT JOIN information_schema.COLUMNS AS parent
  ON parent.TABLE_SCHEMA = DATABASE()
 AND parent.TABLE_NAME = expected.parent_table
 AND parent.COLUMN_NAME = 'id'
ORDER BY expected.label;

-- These are existence-only checks. No affected row or identifier is returned.
SELECT 'orphan', 'idt_user', IF(EXISTS(
  SELECT 1 FROM intelligence_decision_traces AS child
  LEFT JOIN users AS parent ON child.user_id = parent.id
  WHERE child.user_id IS NOT NULL AND parent.id IS NULL
), 'BLOCK', 'PASS');
SELECT 'orphan', 'idt_plan', IF(EXISTS(
  SELECT 1 FROM intelligence_decision_traces AS child
  LEFT JOIN plans AS parent ON child.plan_id = parent.id
  WHERE child.plan_id IS NOT NULL AND parent.id IS NULL
), 'BLOCK', 'PASS');
SELECT 'orphan', 'idt_snapshot', IF(EXISTS(
  SELECT 1 FROM intelligence_decision_traces AS child
  LEFT JOIN intelligence_state_snapshots AS parent
    ON child.intelligence_state_snapshot_id = parent.id
  WHERE child.intelligence_state_snapshot_id IS NOT NULL AND parent.id IS NULL
), 'BLOCK', 'PASS');
SELECT 'orphan', 'laea_event', IF(EXISTS(
  SELECT 1 FROM learning_answer_evaluation_adjustments AS child
  LEFT JOIN learning_answer_events AS parent
    ON child.learning_answer_event_id = parent.id
  WHERE child.learning_answer_event_id IS NOT NULL AND parent.id IS NULL
), 'BLOCK', 'PASS');
SELECT 'orphan', 'laea_user', IF(EXISTS(
  SELECT 1 FROM learning_answer_evaluation_adjustments AS child
  LEFT JOIN users AS parent ON child.user_id = parent.id
  WHERE child.user_id IS NOT NULL AND parent.id IS NULL
), 'BLOCK', 'PASS');

-- Uniqueness checks deliberately exclude NULL because MySQL UNIQUE
-- permits multiple NULL values. Only a boolean status leaves SQL.
SELECT 'unique', 'idt_decision_reference', IF(EXISTS(
  SELECT 1 FROM (
    SELECT decision_reference
    FROM intelligence_decision_traces
    WHERE decision_reference IS NOT NULL
    GROUP BY decision_reference HAVING COUNT(*) > 1 LIMIT 1
  ) AS duplicate_values
), 'BLOCK', 'PASS');
SELECT 'unique', 'laea_answer_event', IF(EXISTS(
  SELECT 1 FROM (
    SELECT learning_answer_event_id
    FROM learning_answer_evaluation_adjustments
    WHERE learning_answer_event_id IS NOT NULL
    GROUP BY learning_answer_event_id HAVING COUNT(*) > 1 LIMIT 1
  ) AS duplicate_values
), 'BLOCK', 'PASS');
