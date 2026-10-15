-- Fixed-SHA 94-to-113 migration preflight. SELECT ONLY, no application rows.
-- Execute with the EXISTING narrow metadata-only account after SHOW GRANTS
-- validation; these are schema metadata and non-secret server capability flags.
-- The absence of trigger metadata is NOT proof a trigger does not exist.
-- The runtime migration account's CREATE/ALTER/TRIGGER privileges are NOT
-- asserted by these results. Output fixed keys/status codes only.
SELECT 'pinned_19_schema','legacy_tables',
 CASE WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
   AND TABLE_NAME IN ('plans','plan_members','development_activity_observations','mcp_linked_subjects','mcp_delegated_grants'))=5
 THEN 'PRESENT' ELSE 'MISSING' END
UNION ALL SELECT 'pinned_19_schema','plan_epoch',
 CASE WHEN NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='authorization_epoch') THEN 'ABSENT'
 WHEN EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='authorization_epoch' AND COLUMN_TYPE='bigint unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1') THEN 'VALID' ELSE 'MISMATCH' END
UNION ALL SELECT 'pinned_19_schema','member_epoch',
 CASE WHEN NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plan_members' AND COLUMN_NAME='authorization_epoch') THEN 'ABSENT'
 WHEN EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plan_members' AND COLUMN_NAME='authorization_epoch' AND COLUMN_TYPE='bigint unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1') THEN 'VALID' ELSE 'MISMATCH' END
UNION ALL SELECT 'pinned_19_schema','mcp_active_fingerprint',
 CASE WHEN NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_linked_subjects' AND COLUMN_NAME='active_identity_fingerprint') THEN 'ABSENT'
 WHEN EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_linked_subjects' AND COLUMN_NAME='active_identity_fingerprint' AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=64 AND IS_NULLABLE='YES') THEN 'VALID' ELSE 'MISMATCH' END
UNION ALL SELECT 'pinned_19_schema','mcp_old_unique',
 CASE WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_linked_subjects' AND INDEX_NAME='mcp_linked_subjects_identity_fingerprint_unique' AND COLUMN_NAME='identity_fingerprint' AND NON_UNIQUE=0) THEN 'PRESENT' ELSE 'ABSENT' END
UNION ALL SELECT 'pinned_19_schema','mcp_active_unique',
 CASE WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_linked_subjects' AND INDEX_NAME='mcp_active_subject_identity_unique' AND COLUMN_NAME='active_identity_fingerprint' AND NON_UNIQUE=0) THEN 'PRESENT' ELSE 'ABSENT' END
UNION ALL SELECT 'pinned_19_schema','mcp_history_index',
 CASE WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_linked_subjects' AND INDEX_NAME='mcp_subject_identity_history_idx' AND COLUMN_NAME='identity_fingerprint') THEN 'PRESENT' ELSE 'ABSENT' END
UNION ALL SELECT 'pinned_19_schema','grant_expiry',
 CASE WHEN EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_delegated_grants' AND COLUMN_NAME='expires_at' AND DATA_TYPE='timestamp' AND IS_NULLABLE='NO') THEN 'OLD'
 WHEN EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_delegated_grants' AND COLUMN_NAME='expires_at' AND DATA_TYPE='timestamp' AND IS_NULLABLE='YES') THEN 'NEW'
 ELSE 'MISMATCH' END
UNION ALL SELECT 'pinned_19_schema','observation_source_cols',
 CASE WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='development_activity_observations' AND COLUMN_NAME IN ('source_revision_id','source_updated_at','source_epoch'))=0 THEN 'ABSENT'
 WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='development_activity_observations' AND (
  (COLUMN_NAME='source_revision_id' AND COLUMN_TYPE='bigint unsigned' AND IS_NULLABLE='YES') OR
  (COLUMN_NAME='source_updated_at' AND DATA_TYPE='timestamp' AND IS_NULLABLE='YES') OR
  (COLUMN_NAME='source_epoch' AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=36 AND IS_NULLABLE='YES')))=3 THEN 'VALID' ELSE 'MISMATCH' END
UNION ALL SELECT 'pinned_19_schema','new_tables',
 CASE WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
 ('private_file_cleanup_entries','private_file_path_fences','private_file_upload_intents','private_file_generation_evidence','private_file_write_attempts','development_operational_profiles','development_operational_targets','mcp_study_result_proposals','learning_context_watch_states','learning_context_change_records'))=0 THEN 'ABSENT'
 WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
 ('private_file_cleanup_entries','private_file_path_fences','private_file_upload_intents','private_file_generation_evidence','private_file_write_attempts','development_operational_profiles','development_operational_targets','mcp_study_result_proposals','learning_context_watch_states','learning_context_change_records'))=10 THEN 'PRESENT' ELSE 'MISMATCH' END
UNION ALL SELECT 'pinned_19_schema','engine_series',
 CASE WHEN VERSION() LIKE '8.0.%' THEN 'MYSQL_8_0' WHEN VERSION() LIKE '8.4.%' THEN 'MYSQL_8_4' ELSE 'OTHER' END
UNION ALL SELECT 'pinned_19_schema','binary_logging',
 CASE WHEN @@GLOBAL.log_bin=1 THEN 'ON' ELSE 'OFF' END
UNION ALL SELECT 'pinned_19_schema','trusted_function_creators',
 CASE WHEN @@GLOBAL.log_bin_trust_function_creators=1 THEN 'ON' ELSE 'OFF' END;
