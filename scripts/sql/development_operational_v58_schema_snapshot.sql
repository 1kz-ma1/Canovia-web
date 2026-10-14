-- V5.8 SELECT-only schema metadata, for authorized operator and disposable CI.
-- No application rows, SQL DDL, writes, identifiers from users, or secrets.
-- Run against a separately verified exact target/schema; output only fixed enums.
SELECT 'v58_schema' AS source, spec.item AS item,
       CASE
         WHEN spec.expected_type = 'TABLE'
           THEN IF(tbl.TABLE_NAME IS NULL, 'MISSING', 'PRESENT')
         WHEN col.COLUMN_NAME IS NULL THEN 'ABSENT'
         WHEN col.COLUMN_TYPE = spec.expected_type
           AND col.IS_NULLABLE = spec.expected_nullability
           AND COALESCE(col.COLUMN_DEFAULT, '') = COALESCE(spec.expected_default, '')
           THEN 'VALID'
         ELSE 'MISMATCH'
       END AS value
  FROM (
    SELECT 'profile_table' AS item, 'development_operational_profiles' AS table_name,
           NULL AS column_name, 'TABLE' AS expected_type, NULL AS expected_nullability, NULL AS expected_default
    UNION ALL SELECT 'target_table', 'development_operational_targets', NULL, 'TABLE', NULL, NULL
    UNION ALL SELECT 'service_checked_at', 'development_operational_targets', 'service_checked_at', 'timestamp', 'YES', NULL
    UNION ALL SELECT 'service_checked_revision', 'development_operational_targets', 'service_checked_revision', 'int unsigned', 'YES', NULL
    UNION ALL SELECT 'service_checked_environment', 'development_operational_targets', 'service_checked_environment', 'varchar(24)', 'YES', NULL
    UNION ALL SELECT 'data_store_revision', 'development_operational_profiles', 'data_store_revision', 'int unsigned', 'NO', '1'
    UNION ALL SELECT 'backup_store_revision', 'development_operational_targets', 'backup_store_revision', 'int unsigned', 'YES', NULL
    UNION ALL SELECT 'restore_store_revision', 'development_operational_targets', 'restore_store_revision', 'int unsigned', 'YES', NULL
  ) AS spec
  LEFT JOIN information_schema.TABLES AS tbl
    ON tbl.TABLE_SCHEMA = DATABASE() AND tbl.TABLE_NAME = spec.table_name
  LEFT JOIN information_schema.COLUMNS AS col
    ON col.TABLE_SCHEMA = DATABASE()
   AND col.TABLE_NAME = spec.table_name AND col.COLUMN_NAME = spec.column_name
 ORDER BY spec.item;
