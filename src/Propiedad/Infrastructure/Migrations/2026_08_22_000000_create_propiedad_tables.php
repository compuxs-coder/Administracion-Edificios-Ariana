<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        $propietarios = $table('propietarios');
        $propietarioEdificio = $table('propietario_edificio');
        $titularidades = $table('departamento_propietarios');
        $edificios = $table('edificios');
        $departamentos = $table('departamentos');

        Schema::create($propietarios, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('tipo_persona', ['persona_natural', 'persona_juridica']);
            $table->string('nombres', 120)->nullable();
            $table->string('apellidos', 120)->nullable();
            $table->string('razon_social', 180)->nullable();
            $table->enum('tipo_identificacion', ['cedula', 'ruc', 'pasaporte', 'otro']);
            $table->string('identificacion', 40);
            $table->string('telefono', 30)->nullable();
            $table->string('celular', 30)->nullable();
            $table->string('correo', 255)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->unique(['tipo_identificacion', 'identificacion'], 'propietarios_identificacion_uq');
            $table->index(['estado', 'tipo_persona'], 'propietarios_estado_tipo_idx');
            $table->index('apellidos', 'propietarios_apellidos_idx');
            $table->index('razon_social', 'propietarios_razon_social_idx');
        });

        Schema::create($propietarioEdificio, function (Blueprint $table) use ($propietarios, $edificios): void {
            $table->uuid('propietario_id');
            $table->uuid('edificio_id');
            $table->timestampsTz();

            $table->primary(['propietario_id', 'edificio_id'], 'propietario_edificio_pk');
            $table->index('edificio_id', 'propietario_edificio_edificio_idx');
            $table->foreign('propietario_id', 'propietario_edificio_propietario_fk')
                ->references('id')->on($propietarios)->restrictOnDelete();
            $table->foreign('edificio_id', 'propietario_edificio_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
        });

        Schema::create($titularidades, function (Blueprint $table) use ($propietarios, $propietarioEdificio, $departamentos): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('propietario_id');
            $table->string('nombre_propietario', 250);
            $table->string('tipo_identificacion_snapshot', 30);
            $table->string('identificacion_snapshot', 40);
            $table->decimal('porcentaje', 9, 6);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->enum('estado', ['activa', 'finalizada'])->default('activa');
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->index(['edificio_id', 'departamento_id', 'estado'], 'titularidades_departamento_estado_idx');
            $table->index(['propietario_id', 'estado'], 'titularidades_propietario_estado_idx');
            $table->index(['departamento_id', 'propietario_id', 'fecha_inicio'], 'titularidades_historial_idx');
            $table->foreign(['edificio_id', 'departamento_id'], 'titularidades_departamento_fk')
                ->references(['edificio_id', 'id'])->on($departamentos)->restrictOnDelete();
            $table->foreign('propietario_id', 'titularidades_propietario_fk')
                ->references('id')->on($propietarios)->restrictOnDelete();
            $table->foreign(['propietario_id', 'edificio_id'], 'titularidades_propietario_edificio_fk')
                ->references(['propietario_id', 'edificio_id'])->on($propietarioEdificio)->restrictOnDelete();
        });

        $this->createIntegrityGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        Schema::dropIfExists($table('departamento_propietarios'));
        Schema::dropIfExists($table('propietario_edificio'));
        Schema::dropIfExists($table('propietarios'));

        if ($pgsql) {
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_departamento_titularidad\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"prevent_propietario_delete\"()");
        }
    }

    private function createIntegrityGuards(bool $pgsql, string $schema): void
    {
        $prefix = $pgsql ? '"'.$schema.'".' : '';

        DB::statement("CREATE UNIQUE INDEX titularidades_activas_pareja_uq ON {$prefix}\"departamento_propietarios\" (\"departamento_id\", \"propietario_id\") WHERE \"fecha_fin\" IS NULL");

        if (! $pgsql) {
            return;
        }

        DB::statement("ALTER TABLE {$prefix}\"propietarios\" ADD CONSTRAINT propietarios_nombre_check CHECK ((tipo_persona = 'persona_natural' AND nombres IS NOT NULL AND btrim(nombres) <> '' AND apellidos IS NOT NULL AND btrim(apellidos) <> '' AND razon_social IS NULL) OR (tipo_persona = 'persona_juridica' AND razon_social IS NOT NULL AND btrim(razon_social) <> '' AND nombres IS NULL AND apellidos IS NULL))");
        DB::statement("ALTER TABLE {$prefix}\"departamento_propietarios\" ADD CONSTRAINT titularidades_porcentaje_check CHECK (porcentaje > 0 AND porcentaje <= 100)");
        DB::statement("ALTER TABLE {$prefix}\"departamento_propietarios\" ADD CONSTRAINT titularidades_fechas_check CHECK (fecha_fin IS NULL OR fecha_fin > fecha_inicio)");
        DB::statement("ALTER TABLE {$prefix}\"departamento_propietarios\" ADD CONSTRAINT titularidades_estado_fecha_check CHECK ((estado = 'activa' AND fecha_fin IS NULL) OR (estado = 'finalizada' AND fecha_fin IS NOT NULL))");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_departamento_titularidad"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de propiedad no puede eliminarse.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.estado = 'finalizada' THEN
                    RAISE EXCEPTION 'Una titularidad finalizada es inmutable.' USING ERRCODE = '23514';
                END IF;

                PERFORM 1
                FROM "{$schema}"."departamentos"
                WHERE id = NEW.departamento_id AND edificio_id = NEW.edificio_id
                FOR UPDATE;

                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."departamento_propietarios" existing
                    WHERE existing.departamento_id = NEW.departamento_id
                      AND existing.propietario_id = NEW.propietario_id
                      AND existing.id <> NEW.id
                      AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, 'infinity'::date)
                      AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, 'infinity'::date)
                ) THEN
                    RAISE EXCEPTION 'La titularidad se superpone con el historial existente.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    WITH limites AS (
                        SELECT NEW.fecha_inicio AS fecha
                        UNION
                        SELECT GREATEST(NEW.fecha_inicio, existing.fecha_inicio)
                        FROM "{$schema}"."departamento_propietarios" existing
                        WHERE existing.departamento_id = NEW.departamento_id
                          AND existing.id <> NEW.id
                          AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, 'infinity'::date)
                          AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, 'infinity'::date)
                    )
                    SELECT 1
                    FROM limites
                    WHERE limites.fecha < COALESCE(NEW.fecha_fin, 'infinity'::date)
                      AND NEW.porcentaje + COALESCE((
                          SELECT SUM(existing.porcentaje)
                          FROM "{$schema}"."departamento_propietarios" existing
                          WHERE existing.departamento_id = NEW.departamento_id
                            AND existing.id <> NEW.id
                            AND existing.fecha_inicio <= limites.fecha
                            AND (existing.fecha_fin IS NULL OR existing.fecha_fin > limites.fecha)
                      ), 0) > 100
                ) THEN
                    RAISE EXCEPTION 'La participación supera el 100 por ciento durante la vigencia indicada.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER titularidades_integridad_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON "{$schema}"."departamento_propietarios"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_departamento_titularidad"();

            CREATE FUNCTION "{$schema}"."prevent_propietario_delete"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                RAISE EXCEPTION 'Los propietarios no pueden eliminarse; utilice el estado.' USING ERRCODE = '23514';
            END;
            \$\$;

            CREATE TRIGGER propietarios_delete_guard
            BEFORE DELETE ON "{$schema}"."propietarios"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."prevent_propietario_delete"();
            SQL);
    }
};
