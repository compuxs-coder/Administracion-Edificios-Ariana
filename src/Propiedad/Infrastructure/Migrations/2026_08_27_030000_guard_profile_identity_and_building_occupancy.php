<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->createPostgresGuards((string) config('database.application_schema'));

            return;
        }

        $this->createSqliteGuards();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $schema = (string) config('database.application_schema');

            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS edificios_ocupacion_guard ON "{$schema}"."edificios";
                DROP FUNCTION IF EXISTS "{$schema}"."guard_edificio_ocupado"();
                DROP TRIGGER IF EXISTS propietarios_tercero_immutable_guard ON "{$schema}"."propietarios";
                DROP TRIGGER IF EXISTS residentes_tercero_immutable_guard ON "{$schema}"."residentes";
                DROP FUNCTION IF EXISTS "{$schema}"."guard_profile_identity_immutable"();
                SQL);

            return;
        }

        foreach ([
            'edificios_ocupacion_guard',
            'propietarios_tercero_immutable_guard',
            'residentes_tercero_immutable_guard',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createPostgresGuards(string $schema): void
    {
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_profile_identity_immutable"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.tercero_id IS DISTINCT FROM OLD.tercero_id THEN
                    RAISE EXCEPTION 'La identidad global vinculada a un perfil es inmutable.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER propietarios_tercero_immutable_guard BEFORE UPDATE OF tercero_id
            ON "{$schema}"."propietarios" FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_profile_identity_immutable"();

            CREATE TRIGGER residentes_tercero_immutable_guard BEFORE UPDATE OF tercero_id
            ON "{$schema}"."residentes" FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_profile_identity_immutable"();

            CREATE FUNCTION "{$schema}"."guard_edificio_ocupado"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.estado = 'inactivo' AND EXISTS (
                    SELECT 1 FROM "{$schema}"."departamento_residentes"
                    WHERE edificio_id = NEW.id AND estado = 'activa'
                ) THEN
                    RAISE EXCEPTION 'No se puede inactivar un edificio con ocupaciones activas.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER edificios_ocupacion_guard BEFORE UPDATE OF estado
            ON "{$schema}"."edificios" FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_edificio_ocupado"();
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER propietarios_tercero_immutable_guard
            BEFORE UPDATE OF tercero_id ON propietarios
            WHEN NEW.tercero_id IS NOT OLD.tercero_id
            BEGIN SELECT RAISE(ABORT, 'La identidad global vinculada a un perfil es inmutable.'); END;

            CREATE TRIGGER residentes_tercero_immutable_guard
            BEFORE UPDATE OF tercero_id ON residentes
            WHEN NEW.tercero_id IS NOT OLD.tercero_id
            BEGIN SELECT RAISE(ABORT, 'La identidad global vinculada a un perfil es inmutable.'); END;

            CREATE TRIGGER edificios_ocupacion_guard
            BEFORE UPDATE OF estado ON edificios
            WHEN NEW.estado = 'inactivo' AND EXISTS (
                SELECT 1 FROM departamento_residentes WHERE edificio_id = NEW.id AND estado = 'activa'
            )
            BEGIN SELECT RAISE(ABORT, 'No se puede inactivar un edificio con ocupaciones activas.'); END;
            SQL);
    }
};
