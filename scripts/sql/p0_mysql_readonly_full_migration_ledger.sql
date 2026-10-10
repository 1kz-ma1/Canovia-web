-- P0 READ ONLY: complete Laravel migration ledger (identifiers and batches only).
-- Run only after privately verifying the intended Aiven MySQL service/database,
-- through an existing SELECT-only account. Never upload raw SQL output or
-- connection credentials. No row from users/sessions/learning is accessed.
--
-- MySQL client --batch --raw --skip-column-names yields:
-- migration_ledger<TAB>2026_...<TAB>integer_batch
-- This is separate from targeted FK/index inventory. It is not a backup test.
SELECT 'migration_ledger' AS section,
       migration AS object_id,
       batch AS observed_batch
FROM migrations
ORDER BY migration;
