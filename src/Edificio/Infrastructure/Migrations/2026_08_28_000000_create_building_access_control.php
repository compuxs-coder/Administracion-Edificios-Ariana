<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $users = $pgsql ? 'public.users' : 'users';
        $memberships = $table('edificio_usuario');
        $buildings = $table('edificios');
        $permissions = $table('permisos');
        $roles = $table('roles');
        $rolePermissions = $table('rol_permisos');
        $assignments = $table('edificio_usuario_roles');
        $invitations = $table('invitaciones_edificio');
        $events = $table('eventos_acceso_edificio');

        Schema::table($memberships, function (Blueprint $table) use ($users): void {
            $table->uuid('creado_por_user_id')->nullable();
            $table->uuid('revocado_por_user_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreign('creado_por_user_id', 'edificio_usuario_creador_fk')->references('id')->on($users)->nullOnDelete();
            $table->foreign('revocado_por_user_id', 'edificio_usuario_revocador_fk')->references('id')->on($users)->nullOnDelete();
            $table->index(['user_id', 'revoked_at'], 'edificio_usuario_activo_idx');
        });

        Schema::create($permissions, function (Blueprint $table): void {
            $table->string('codigo', 80)->primary();
            $table->string('nombre', 120);
            $table->string('modulo', 40);
            $table->timestampsTz();
        });

        Schema::create($roles, function (Blueprint $table): void {
            $table->string('codigo', 50)->primary();
            $table->string('nombre', 100);
            $table->string('descripcion', 255);
            $table->boolean('es_sistema')->default(true);
            $table->timestampsTz();
        });

        Schema::create($rolePermissions, function (Blueprint $table) use ($roles, $permissions): void {
            $table->string('rol_codigo', 50);
            $table->string('permiso_codigo', 80);
            $table->primary(['rol_codigo', 'permiso_codigo'], 'rol_permisos_pk');
            $table->foreign('rol_codigo', 'rol_permisos_rol_fk')->references('codigo')->on($roles)->restrictOnDelete();
            $table->foreign('permiso_codigo', 'rol_permisos_permiso_fk')->references('codigo')->on($permissions)->restrictOnDelete();
        });

        Schema::create($assignments, function (Blueprint $table) use ($memberships, $roles, $users): void {
            $table->uuid('edificio_id');
            $table->uuid('user_id');
            $table->string('rol_codigo', 50);
            $table->uuid('asignado_por_user_id')->nullable();
            $table->timestampsTz();
            $table->primary(['edificio_id', 'user_id', 'rol_codigo'], 'edificio_usuario_roles_pk');
            $table->foreign(['edificio_id', 'user_id'], 'edificio_usuario_roles_membresia_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->cascadeOnDelete();
            $table->foreign('rol_codigo', 'edificio_usuario_roles_rol_fk')->references('codigo')->on($roles)->restrictOnDelete();
            $table->foreign('asignado_por_user_id', 'edificio_usuario_roles_actor_fk')->references('id')->on($users)->nullOnDelete();
            $table->index(['user_id', 'rol_codigo'], 'edificio_usuario_roles_usuario_idx');
        });

        Schema::create($invitations, function (Blueprint $table) use ($buildings, $roles, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->string('email_normalizado');
            $table->string('rol_codigo', 50);
            $table->char('token_hash', 64)->unique('invitaciones_token_uq');
            $table->enum('estado', ['pendiente', 'aceptada', 'revocada'])->default('pendiente');
            $table->uuid('invitado_por_user_id');
            $table->uuid('aceptado_por_user_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign('edificio_id', 'invitaciones_edificio_fk')->references('id')->on($buildings)->restrictOnDelete();
            $table->foreign('rol_codigo', 'invitaciones_rol_fk')->references('codigo')->on($roles)->restrictOnDelete();
            $table->foreign('invitado_por_user_id', 'invitaciones_actor_fk')->references('id')->on($users)->restrictOnDelete();
            $table->foreign('aceptado_por_user_id', 'invitaciones_aceptado_fk')->references('id')->on($users)->nullOnDelete();
            $table->index(['edificio_id', 'estado', 'expires_at'], 'invitaciones_edificio_estado_idx');
            $table->index('email_normalizado', 'invitaciones_email_idx');
        });

        Schema::create($events, function (Blueprint $table) use ($buildings, $invitations, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('usuario_afectado_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('invitacion_id')->nullable();
            $table->enum('tipo', ['migracion', 'membresia_creada', 'invitacion_creada', 'invitacion_aceptada', 'invitacion_revocada', 'roles_actualizados', 'membresia_revocada']);
            $table->json('roles_anteriores')->nullable();
            $table->json('roles_nuevos')->nullable();
            $table->json('detalle')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('edificio_id', 'eventos_acceso_edificio_fk')->references('id')->on($buildings)->restrictOnDelete();
            $table->foreign('usuario_afectado_id', 'eventos_acceso_usuario_fk')->references('id')->on($users)->restrictOnDelete();
            $table->foreign('actor_user_id', 'eventos_acceso_actor_fk')->references('id')->on($users)->restrictOnDelete();
            $table->foreign('invitacion_id', 'eventos_acceso_invitacion_fk')->references('id')->on($invitations)->restrictOnDelete();
            $table->index(['edificio_id', 'created_at'], 'eventos_acceso_edificio_fecha_idx');
            $table->index('usuario_afectado_id', 'eventos_acceso_usuario_idx');
        });

        $this->seedCatalog($permissions, $roles, $rolePermissions);

        $now = now();
        $existingMemberships = DB::table($memberships)->orderBy('edificio_id')->orderBy('user_id')->get();
        foreach ($existingMemberships as $membership) {
            DB::table($assignments)->insert([
                'edificio_id' => $membership->edificio_id,
                'user_id' => $membership->user_id,
                'rol_codigo' => 'administrador',
                'asignado_por_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table($events)->insert([
                'id' => (string) Str::uuid(),
                'edificio_id' => $membership->edificio_id,
                'usuario_afectado_id' => $membership->user_id,
                'actor_user_id' => null,
                'invitacion_id' => null,
                'tipo' => 'migracion',
                'roles_anteriores' => null,
                'roles_nuevos' => json_encode(['administrador'], JSON_THROW_ON_ERROR),
                'detalle' => json_encode(['origen' => 'backfill_etapa_11'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }

        $prefix = $pgsql ? '"'.$schema.'".' : '';
        DB::statement("CREATE UNIQUE INDEX invitaciones_pendientes_email_uq ON {$prefix}\"invitaciones_edificio\" (\"edificio_id\", \"email_normalizado\") WHERE \"estado\" = 'pendiente'");

        $this->createGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        $this->dropGuards($pgsql, $schema);
        Schema::dropIfExists($table('eventos_acceso_edificio'));
        Schema::dropIfExists($table('invitaciones_edificio'));
        Schema::dropIfExists($table('edificio_usuario_roles'));
        Schema::dropIfExists($table('rol_permisos'));
        Schema::dropIfExists($table('roles'));
        Schema::dropIfExists($table('permisos'));

        Schema::table($table('edificio_usuario'), function (Blueprint $table): void {
            $table->dropForeign('edificio_usuario_creador_fk');
            $table->dropForeign('edificio_usuario_revocador_fk');
            $table->dropIndex('edificio_usuario_activo_idx');
            $table->dropColumn(['creado_por_user_id', 'revocado_por_user_id', 'revoked_at']);
        });
    }

    private function seedCatalog(string $permissions, string $roles, string $rolePermissions): void
    {
        $now = now();
        $permissionCatalog = [
            ['edificio.ver', 'Consultar edificio', 'edificio'],
            ['edificio.editar', 'Editar edificio', 'edificio'],
            ['edificio.cambiar_estado', 'Cambiar estado del edificio', 'edificio'],
            ['miembros.ver', 'Consultar miembros', 'acceso'],
            ['miembros.gestionar', 'Gestionar miembros y roles', 'acceso'],
            ['estructura.ver', 'Consultar estructura', 'estructura'],
            ['estructura.gestionar', 'Gestionar estructura', 'estructura'],
            ['propiedad.ver', 'Consultar propiedad y ocupación', 'propiedad'],
            ['propiedad.gestionar', 'Gestionar propiedad y ocupación', 'propiedad'],
            ['finanzas.ver', 'Consultar información financiera', 'finanzas'],
            ['conceptos.gestionar', 'Gestionar conceptos y tarifas', 'finanzas'],
            ['cargos.generar', 'Generar cargos', 'finanzas'],
            ['cargos.crear', 'Crear cargos manuales', 'finanzas'],
            ['cargos.anular', 'Anular cargos', 'finanzas'],
            ['pagos.registrar', 'Registrar pagos', 'finanzas'],
            ['pagos.aplicar_saldo', 'Aplicar saldo a favor', 'finanzas'],
            ['pagos.anular', 'Anular pagos', 'finanzas'],
            ['comprobantes.ver', 'Consultar recibos y evidencias', 'finanzas'],
            ['evidencias.gestionar', 'Adjuntar evidencias de pago', 'finanzas'],
        ];
        DB::table($permissions)->insert(array_map(static fn (array $permission): array => [
            'codigo' => $permission[0],
            'nombre' => $permission[1],
            'modulo' => $permission[2],
            'created_at' => $now,
            'updated_at' => $now,
        ], $permissionCatalog));

        $roleCatalog = [
            ['administrador', 'Administrador', 'Acceso completo y administración de miembros.'],
            ['gestor_propiedad', 'Gestor de propiedad', 'Gestiona estructura, propietarios, residentes y ocupaciones.'],
            ['gestor_finanzas', 'Gestor financiero', 'Gestiona conceptos, cargos, pagos, cartera y comprobantes.'],
            ['consulta', 'Consulta', 'Acceso de lectura sin operaciones administrativas.'],
        ];
        DB::table($roles)->insert(array_map(static fn (array $role): array => [
            'codigo' => $role[0],
            'nombre' => $role[1],
            'descripcion' => $role[2],
            'es_sistema' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $roleCatalog));

        $all = array_column($permissionCatalog, 0);
        $matrix = [
            'administrador' => $all,
            'gestor_propiedad' => ['edificio.ver', 'estructura.ver', 'estructura.gestionar', 'propiedad.ver', 'propiedad.gestionar'],
            'gestor_finanzas' => ['edificio.ver', 'estructura.ver', 'propiedad.ver', 'finanzas.ver', 'conceptos.gestionar', 'cargos.generar', 'cargos.crear', 'cargos.anular', 'pagos.registrar', 'pagos.aplicar_saldo', 'pagos.anular', 'comprobantes.ver', 'evidencias.gestionar'],
            'consulta' => ['edificio.ver', 'estructura.ver', 'propiedad.ver', 'finanzas.ver', 'comprobantes.ver'],
        ];
        foreach ($matrix as $role => $rolePermissionsList) {
            foreach ($rolePermissionsList as $permission) {
                DB::table($rolePermissions)->insert(['rol_codigo' => $role, 'permiso_codigo' => $permission]);
            }
        }
    }

    private function createGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER eventos_acceso_update_guard BEFORE UPDATE ON eventos_acceso_edificio
                BEGIN SELECT RAISE(ABORT, 'El historial de acceso es inmutable.'); END;
                CREATE TRIGGER eventos_acceso_delete_guard BEFORE DELETE ON eventos_acceso_edificio
                BEGIN SELECT RAISE(ABORT, 'El historial de acceso no puede eliminarse.'); END;
                CREATE TRIGGER membresias_delete_guard BEFORE DELETE ON edificio_usuario
                BEGIN SELECT RAISE(ABORT, 'Las membresías se revocan; no se eliminan.'); END;
                CREATE TRIGGER ultimo_administrador_membresia_guard
                BEFORE UPDATE OF revoked_at ON edificio_usuario
                WHEN OLD.revoked_at IS NULL AND NEW.revoked_at IS NOT NULL
                  AND EXISTS (SELECT 1 FROM edificio_usuario_roles WHERE edificio_id = OLD.edificio_id AND user_id = OLD.user_id AND rol_codigo = 'administrador')
                  AND NOT EXISTS (
                    SELECT 1 FROM edificio_usuario_roles roles
                    JOIN edificio_usuario membresia ON membresia.edificio_id = roles.edificio_id AND membresia.user_id = roles.user_id
                    WHERE roles.edificio_id = OLD.edificio_id AND roles.rol_codigo = 'administrador'
                      AND membresia.revoked_at IS NULL AND roles.user_id <> OLD.user_id
                  )
                BEGIN SELECT RAISE(ABORT, 'El edificio debe conservar al menos un administrador.'); END;
                CREATE TRIGGER ultimo_administrador_rol_guard
                BEFORE DELETE ON edificio_usuario_roles
                WHEN OLD.rol_codigo = 'administrador'
                  AND EXISTS (SELECT 1 FROM edificio_usuario WHERE edificio_id = OLD.edificio_id AND user_id = OLD.user_id AND revoked_at IS NULL)
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

        DB::statement("ALTER TABLE \"{$schema}\".\"invitaciones_edificio\" ADD CONSTRAINT invitaciones_email_normalizado_check CHECK (email_normalizado = lower(btrim(email_normalizado)) AND btrim(email_normalizado) <> '')");
        DB::statement("ALTER TABLE \"{$schema}\".\"invitaciones_edificio\" ADD CONSTRAINT invitaciones_estado_fechas_check CHECK ((estado = 'pendiente' AND accepted_at IS NULL AND revoked_at IS NULL) OR (estado = 'aceptada' AND accepted_at IS NOT NULL AND revoked_at IS NULL) OR (estado = 'revocada' AND accepted_at IS NULL AND revoked_at IS NOT NULL))");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."prevent_access_event_mutation"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION 'El historial de acceso es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER eventos_acceso_history_guard BEFORE UPDATE OR DELETE
            ON "{$schema}"."eventos_acceso_edificio" FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."prevent_access_event_mutation"();

            CREATE FUNCTION "{$schema}"."guard_building_administrator"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE target_building uuid;
            DECLARE target_user uuid;
            BEGIN
                IF TG_TABLE_NAME = 'edificio_usuario' THEN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Las membresías se revocan; no se eliminan.' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL THEN
                        RETURN NEW;
                    END IF;
                    target_building := OLD.edificio_id;
                    target_user := OLD.user_id;
                    IF NOT EXISTS (
                        SELECT 1 FROM "{$schema}"."edificio_usuario_roles"
                        WHERE edificio_id = target_building AND user_id = target_user AND rol_codigo = 'administrador'
                    ) THEN
                        RETURN NEW;
                    END IF;
                ELSE
                    IF OLD.rol_codigo <> 'administrador' THEN
                        RETURN OLD;
                    END IF;
                    target_building := OLD.edificio_id;
                    target_user := OLD.user_id;
                    IF NOT EXISTS (
                        SELECT 1 FROM "{$schema}"."edificio_usuario"
                        WHERE edificio_id = target_building AND user_id = target_user AND revoked_at IS NULL
                    ) THEN
                        RETURN OLD;
                    END IF;
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM "{$schema}"."edificio_usuario_roles" roles
                    JOIN "{$schema}"."edificio_usuario" membership
                      ON membership.edificio_id = roles.edificio_id AND membership.user_id = roles.user_id
                    WHERE roles.edificio_id = target_building
                      AND roles.rol_codigo = 'administrador'
                      AND membership.revoked_at IS NULL
                      AND roles.user_id <> target_user
                ) THEN
                    RAISE EXCEPTION 'El edificio debe conservar al menos un administrador.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER membresias_administrador_guard BEFORE UPDATE OF revoked_at OR DELETE
            ON "{$schema}"."edificio_usuario" FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_building_administrator"();
            CREATE TRIGGER roles_administrador_guard BEFORE DELETE
            ON "{$schema}"."edificio_usuario_roles" FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_building_administrator"();
            SQL);
    }

    private function dropGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            foreach (['eventos_acceso_update_guard', 'eventos_acceso_delete_guard', 'membresias_delete_guard', 'ultimo_administrador_membresia_guard', 'ultimo_administrador_rol_guard'] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS roles_administrador_guard ON "{$schema}"."edificio_usuario_roles";
            DROP TRIGGER IF EXISTS membresias_administrador_guard ON "{$schema}"."edificio_usuario";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_building_administrator"();
            DROP TRIGGER IF EXISTS eventos_acceso_history_guard ON "{$schema}"."eventos_acceso_edificio";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_access_event_mutation"();
            SQL);
    }
};
