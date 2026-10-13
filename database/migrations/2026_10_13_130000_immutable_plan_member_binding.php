<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A membership row represents one actor's admission to one Plan.
     *
     * Changing plan_id or user_id in place could carry the same row ID and
     * authorization epoch away from the original permission domain and back.
     * A legitimate transfer must remove the old membership and insert a new
     * row with a new database ID (new admission identity).
     */
    public function up(): void
    {
        switch (DB::getDriverName()) {
            case 'pgsql':
                // CREATE OR REPLACE is intentional: PHPUnit's DatabaseMigrations
                // recreates tables, but PostgreSQL functions outlive table drops.
                DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION canovia_member_binding_immutable_check() RETURNS trigger AS $$
BEGIN
    IF NEW.plan_id IS DISTINCT FROM OLD.plan_id
        OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
        RAISE EXCEPTION 'A collaboration membership binding is immutable; remove and rejoin instead.'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
                DB::unprepared(
                    'CREATE TRIGGER canovia_member_binding_immutable BEFORE UPDATE OF plan_id, user_id '.
                    'ON plan_members FOR EACH ROW EXECUTE FUNCTION canovia_member_binding_immutable_check()'
                );
                break;

            case 'mysql':
                // Preserve the existing epoch trigger; this separate guard
                // rejects identity edits, including direct/bulk SQL writers.
                DB::unprepared(<<<'SQL'
CREATE TRIGGER canovia_member_binding_immutable BEFORE UPDATE ON plan_members FOR EACH ROW
FOLLOWS canovia_member_authorization_epoch
BEGIN
    IF NOT (NEW.plan_id <=> OLD.plan_id)
        OR NOT (NEW.user_id <=> OLD.user_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A collaboration membership binding is immutable; remove and rejoin instead.';
    END IF;
END
SQL);
                break;

            case 'sqlite':
                DB::unprepared(<<<'SQL'
CREATE TRIGGER canovia_member_binding_immutable
BEFORE UPDATE OF plan_id, user_id ON plan_members
FOR EACH ROW
WHEN NEW.plan_id IS NOT OLD.plan_id
    OR NEW.user_id IS NOT OLD.user_id
BEGIN
    SELECT RAISE(ABORT, 'A collaboration membership binding is immutable; remove and rejoin instead.');
END
SQL);
                break;

            default:
                throw new RuntimeException('Unsupported membership binding trigger database driver.');
        }
    }

    public function down(): void
    {
        switch (DB::getDriverName()) {
            case 'pgsql':
                DB::unprepared('DROP TRIGGER IF EXISTS canovia_member_binding_immutable ON plan_members');
                DB::unprepared('DROP FUNCTION IF EXISTS canovia_member_binding_immutable_check()');
                break;
            case 'mysql':
            case 'sqlite':
                DB::unprepared('DROP TRIGGER IF EXISTS canovia_member_binding_immutable');
                break;
            default:
                throw new RuntimeException('Unsupported membership binding trigger database driver.');
        }
    }
};
