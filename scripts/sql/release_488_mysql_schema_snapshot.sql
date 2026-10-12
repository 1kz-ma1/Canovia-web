-- Release #488: SELECT-only, predeployment schema comparison.
-- No application rows, names, secrets, grant changes, DML, DDL or SET.
-- MySQL --batch --raw --skip-column-names yields exactly 5 fixed status rows.
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
     AND c.COLUMN_NAME = 'source_revision_id'
UNION ALL
SELECT 'release_488_schema', 'source_epoch',
    CASE WHEN c.COLUMN_NAME IS NULL THEN 'ABSENT'
         WHEN c.IS_NULLABLE = 'YES' AND c.DATA_TYPE = 'char'
           AND c.CHARACTER_MAXIMUM_LENGTH = 36
           AND c.COLUMN_DEFAULT IS NULL THEN 'VALID'
         ELSE 'MISMATCH' END
    FROM (SELECT 1 AS fixture) AS one
    LEFT JOIN information_schema.COLUMNS AS c
      ON c.TABLE_SCHEMA = DATABASE()
     AND c.TABLE_NAME = 'development_activity_observations'
     AND c.COLUMN_NAME = 'source_epoch'
UNION ALL
SELECT 'release_488_schema', 'source_epoch_index',
    CASE WHEN NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS AS c
         WHERE c.TABLE_SCHEMA = DATABASE()
           AND c.TABLE_NAME = 'development_activity_observations'
           AND c.COLUMN_NAME = 'source_epoch'
    ) THEN 'ABSENT'
    WHEN EXISTS (
        SELECT 1 FROM information_schema.STATISTICS AS s
         WHERE s.TABLE_SCHEMA = DATABASE()
           AND s.TABLE_NAME = 'development_activity_observations'
           AND s.INDEX_NAME = 'development_activity_observations_source_epoch_index'
           AND s.COLUMN_NAME = 'source_epoch'
           AND s.NON_UNIQUE = 1 AND s.SEQ_IN_INDEX = 1
    ) THEN 'VALID'
    ELSE 'MISMATCH' END;
