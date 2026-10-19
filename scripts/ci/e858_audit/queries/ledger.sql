SELECT /*+ MAX_EXECUTION_TIME(5000) */ 'migration_ledger', migration, batch FROM migrations ORDER BY migration LIMIT 116;
