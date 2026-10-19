<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Initial read-only collision screen. Does not authorize migrations or release. */
final class Release20PendingSchemaGuard
{
    public static function assertInitialBoundary(): void
    {
        $contract = json_decode(file_get_contents(resource_path('release20_pending_schema_contract.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($contract['absent_tables'] as $table) {
            if (Schema::hasTable($table)) {
                self::block();
            }
        }
        foreach ($contract['absent_columns'] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    self::block();
                }
            }
        }
        foreach ($contract['absent_index_names'] as $table => $names) {
            foreach (Schema::getIndexes($table) as $index) {
                $matches = array_filter($names, fn ($name) => $index['name'] === $name
                    || (in_array(DB::getDriverName(), ['mysql', 'sqlite'], true) && strcasecmp($index['name'], $name) === 0));
                if ($matches !== []) {
                    self::block(); // Index symbols have TABLE scope.
                }
            }
        }
        if (DB::getDriverName() === 'mysql') {
            $database = DB::connection()->getDatabaseName();
            if (DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $database)->whereIn('CONSTRAINT_NAME', $contract['absent_foreign_key_names'])->exists()
                || DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $database)->whereIn('TRIGGER_NAME', $contract['absent_trigger_names'])->exists()) {
                self::block(); // FK/trigger symbols have DATABASE scope.
            }
        }
        Release20IdentityMigrationGuard::assertCompatible();
    }

    private static function block(): never
    {
        throw new RuntimeException('RELEASE20_INITIAL_SCHEMA_BLOCK');
    }
}
