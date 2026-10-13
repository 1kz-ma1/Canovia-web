<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Authoritative epoch bumps belong to the database. Eloquent observers
     * cannot protect raw query-builder UPDATEs, SQL scripts or other clients.
     */
    public function up(): void
    {
        switch (DB::getDriverName()) {
            case 'pgsql':
                DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION canovia_plan_authorization_epoch_update() RETURNS trigger AS $$
BEGIN
    IF NEW.user_id IS DISTINCT FROM OLD.user_id
        OR NEW.is_collaborative IS DISTINCT FROM OLD.is_collaborative THEN
        NEW.authorization_epoch := OLD.authorization_epoch + 1;
    ELSE
        NEW.authorization_epoch := OLD.authorization_epoch;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
                DB::unprepared('CREATE TRIGGER canovia_plan_authorization_epoch BEFORE UPDATE ON plans FOR EACH ROW EXECUTE FUNCTION canovia_plan_authorization_epoch_update()');

                DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION canovia_member_authorization_epoch_update() RETURNS trigger AS $$
BEGIN
    IF NEW.role IS DISTINCT FROM OLD.role THEN
        NEW.authorization_epoch := OLD.authorization_epoch + 1;
    ELSE
        NEW.authorization_epoch := OLD.authorization_epoch;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
                DB::unprepared('CREATE TRIGGER canovia_member_authorization_epoch BEFORE UPDATE ON plan_members FOR EACH ROW EXECUTE FUNCTION canovia_member_authorization_epoch_update()');
                break;

            case 'mysql':
                DB::unprepared(<<<'SQL'
CREATE TRIGGER canovia_plan_authorization_epoch BEFORE UPDATE ON plans FOR EACH ROW
BEGIN
    IF NOT (NEW.user_id <=> OLD.user_id)
        OR NOT (NEW.is_collaborative <=> OLD.is_collaborative) THEN
        SET NEW.authorization_epoch = OLD.authorization_epoch + 1;
    ELSE
        SET NEW.authorization_epoch = OLD.authorization_epoch;
    END IF;
END
SQL);
                DB::unprepared(<<<'SQL'
CREATE TRIGGER canovia_member_authorization_epoch BEFORE UPDATE ON plan_members FOR EACH ROW
BEGIN
    IF NOT (NEW.role <=> OLD.role) THEN
        SET NEW.authorization_epoch = OLD.authorization_epoch + 1;
    ELSE
        SET NEW.authorization_epoch = OLD.authorization_epoch;
    END IF;
END
SQL);
                break;

            case 'sqlite':
                // SQLite cannot assign to NEW in BEFORE UPDATE triggers. An
                // AFTER UPDATE of the permission fields updates only the epoch;
                // that second update does not touch the watched fields.
                DB::unprepared(<<<'SQL'
CREATE TRIGGER canovia_plan_authorization_epoch
AFTER UPDATE OF user_id, is_collaborative ON plans
FOR EACH ROW
WHEN NEW.user_id IS NOT OLD.user_id
    OR NEW.is_collaborative IS NOT OLD.is_collaborative
BEGIN
    UPDATE plans
       SET authorization_epoch = OLD.authorization_epoch + 1
     WHERE id = NEW.id;
END
SQL);
                DB::unprepared(<<<'SQL'
CREATE TRIGGER canovia_member_authorization_epoch
AFTER UPDATE OF role ON plan_members
FOR EACH ROW
WHEN NEW.role IS NOT OLD.role
BEGIN
    UPDATE plan_members
       SET authorization_epoch = OLD.authorization_epoch + 1
     WHERE id = NEW.id;
END
SQL);
                break;

            default:
                throw new RuntimeException('Unsupported authorization-epoch trigger database driver.');
        }
    }

    public function down(): void
    {
        switch (DB::getDriverName()) {
            case 'pgsql':
                DB::unprepared('DROP TRIGGER IF EXISTS canovia_member_authorization_epoch ON plan_members');
                DB::unprepared('DROP FUNCTION IF EXISTS canovia_member_authorization_epoch_update()');
                DB::unprepared('DROP TRIGGER IF EXISTS canovia_plan_authorization_epoch ON plans');
                DB::unprepared('DROP FUNCTION IF EXISTS canovia_plan_authorization_epoch_update()');
                break;
            case 'mysql':
            case 'sqlite':
                DB::unprepared('DROP TRIGGER IF EXISTS canovia_member_authorization_epoch');
                DB::unprepared('DROP TRIGGER IF EXISTS canovia_plan_authorization_epoch');
                break;
            default:
                throw new RuntimeException('Unsupported authorization-epoch trigger database driver.');
        }
    }
};
