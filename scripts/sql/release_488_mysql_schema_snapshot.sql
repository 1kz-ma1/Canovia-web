-- Release #488: SELECT-only, predeployment schema comparison.
-- No application rows, names, secrets, grant changes, DML, DDL or SET.
-- MySQL --batch --raw --skip-column-names yields exactly 3 fixed status rows.
SELECT 'release_488_schema' AS section, 'base_table' AS object_id,
    IF(EXISTS (
        SELECT 1 FROM information_schema.TABLES AS t
         WHERE t.TABLE_SCHEMA = DATABASE()
           AND t.TABLE_NAME = 'development_activity_observations'
    ), 'PRESENT', 'MISSING') AS status
UNION ALL
SELECT 'release_488_schema', 'source_updated_at',
    CASE WHEN c.COLUMN_NAME IS NULL THEN 'ABSENT'
         WHEN c.IS_NULLABLE = 'YES' AND c.DATA_TYPE = 'timestamp'
           AND c.COLUMN_DEFAULT IS NULL THEN 'VALID'
         ELSE 'MISMATCH' END
    FROM (SELECT 1 AS fixture) AS one
    LEFT JOIN information_schema.COLUMNS AS c
      ON c.TABLE_SCHEMA = DATABASE()
     AND c.TABLE_NAME = 'development_activity_observations'
     AND c.COLUMN_NAME = 'source_updated_at'
UNION ALL
SELECT 'release_488_schema', 'source_revision_id',
    CASE WHEN c.COLUMN_NAME IS NULL THEN 'ABSENT'
         WHEN c.IS_NULLABLE = 'YES' AND c.DATA_TYPE = 'bigint'
           AND c.COLUMN_TYPE = 'bigint unsigned'
           AND c.COLUMN_DEFAULT IS NULL THEN 'VALID'
         ELSE 'MISMATCH' END
    FROM (SELECT 1 AS fixture) AS one
    LEFT JOIN information_schema.COLUMNS AS c
      ON c.TABLE_SCHEMA = DATABASE()
     AND c.TABLE_NAME = 'development_activity_observations'
     AND c.COLUMN_NAME = 'source_revision_id';
