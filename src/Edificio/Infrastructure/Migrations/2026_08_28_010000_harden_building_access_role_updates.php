<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER membresias_identidad_update_guard
                BEFORE UPDATE OF edificio_id, user_id ON edificio_usuario
                WHEN OLD.edificio_id <> NEW.edificio_id OR OLD.user_id <> NEW.user_id
                BEGIN SELECT RAISE(ABORT, 'La identidad de la membresía es inmutable.'); END;
                CREATE TRIGGER ultimo_administrador_rol_update_guard
                BEFORE UPDATE OF edificio_id, user_id, rol_codigo ON edificio_usuario_roles
                WHEN OLD.rol_codigo = 'administrador'
                  AND (OLD.edificio_id <> NEW.edificio_id OR OLD.user_id <> NEW.user_id OR OLD.rol_codigo <> NEW.rol_codigo)
                  AND EXISTS (
                    SELECT 1 FROM edificio_usuario
                    WHERE edificio_id = OLD.edificio_id AND user_id = OLD.user_id AND revoked_at IS NULL
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM edificio_usuario_roles roles
                    JOIN edificio_usuario membresia ON membresia.edificio_id = roles.edificio_id AND membresia.user_id = roles.user_id
                    WHERE roles.edificio_id = OLD.edificio_id AND roles.rol_codigo = 'administrador'
                      AND membresia.revoked_at IS NULL AND roles.user_id <> OLD.user_id
                  )
                BEGIN SELECT RAISE(ABORT, 'El edificio debe conservar al menos un administrador.'); END;
                SQL);

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."prevent_membership_identity_update"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.edificio_id IS DISTINCT FROM NEW.edificio_id OR OLD.user_id IS DISTINCT FROM NEW.user_id THEN
                    RAISE EXCEPTION 'La identidad de la membresía es inmutable.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER membresias_identidad_update_guard
            BEFORE UPDATE OF edificio_id, user_id ON "{$schema}"."edificio_usuario"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."prevent_membership_identity_update"();

            CREATE FUNCTION "{$schema}"."guard_building_administrator_update"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.edificio_id IS NOT DISTINCT FROM NEW.edificio_id
                   AND OLD.user_id IS NOT DISTINCT FROM NEW.user_id
                   AND OLD.rol_codigo IS NOT DISTINCT FROM NEW.rol_codigo THEN
                    RETURN NEW;
                END IF;
                IF OLD.rol_codigo <> 'administrador' THEN
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."edificio_usuario"
                    WHERE edificio_id = OLD.edificio_id AND user_id = OLD.user_id AND revoked_at IS NULL
                ) THEN
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (
                    SELECT 1
                    FROM "{$schema}"."edificio_usuario_roles" roles
                    JOIN "{$schema}"."edificio_usuario" membership
                      ON membership.edificio_id = roles.edificio_id AND membership.user_id = roles.user_id
                    WHERE roles.edificio_id = OLD.edificio_id
                      AND roles.rol_codigo = 'administrador'
                      AND membership.revoked_at IS NULL
                      AND roles.user_id <> OLD.user_id
                ) THEN
                    RAISE EXCEPTION 'El edificio debe conservar al menos un administrador.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER roles_administrador_update_guard
            BEFORE UPDATE OF edificio_id, user_id, rol_codigo ON "{$schema}"."edificio_usuario_roles"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_building_administrator_update"();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ultimo_administrador_rol_update_guard');
            DB::statement('DROP TRIGGER IF EXISTS membresias_identidad_update_guard');

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS roles_administrador_update_guard ON "{$schema}"."edificio_usuario_roles";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_building_administrator_update"();
            DROP TRIGGER IF EXISTS membresias_identidad_update_guard ON "{$schema}"."edificio_usuario";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_membership_identity_update"();
            SQL);
    }
};
