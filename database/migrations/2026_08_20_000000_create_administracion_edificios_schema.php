<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) config('database.application_schema');

        if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $schema) !== 1) {
            throw new RuntimeException('DB_SCHEMA must be a lowercase PostgreSQL identifier.');
        }

        $connection->statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schema));

        $state = $connection->selectOne(
            <<<'SQL'
                SELECT
                    has_schema_privilege(current_user, ?, 'USAGE') AS can_use_schema,
                    has_schema_privilege(current_user, ?, 'CREATE') AS can_create_objects,
                    current_schema()::text = ? AS is_current_schema
            SQL,
            [$schema, $schema, $schema],
        );

        if (! $state->can_use_schema || ! $state->can_create_objects || ! $state->is_current_schema) {
            throw new RuntimeException('The PostgreSQL role cannot use the configured application schema.');
        }
    }

    public function down(): void
    {
        // Preserve the schema because later modules may already contain data in it.
    }
};
