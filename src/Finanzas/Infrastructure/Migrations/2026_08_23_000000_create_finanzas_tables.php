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

        $conceptos = $table('conceptos_cobro');
        $tarifas = $table('tarifas_concepto');
        $tarifaDepartamentos = $table('tarifa_departamentos');
        $edificios = $table('edificios');
        $departamentos = $table('departamentos');

        Schema::create($conceptos, function (Blueprint $table) use ($edificios): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->string('codigo', 40);
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->enum('tipo', ['ordinario', 'extraordinario', 'consumo', 'multa', 'interes', 'otro']);
            $table->enum('periodicidad', ['mensual', 'trimestral', 'semestral', 'anual', 'unico', 'manual']);
            $table->enum('forma_calculo', ['valor_fijo', 'por_alicuota', 'porcentaje', 'por_consumo', 'manual']);
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'conceptos_cobro_edificio_id_id_uq');
            $table->unique(['edificio_id', 'codigo'], 'conceptos_cobro_edificio_codigo_uq');
            $table->index(['edificio_id', 'estado'], 'conceptos_cobro_edificio_estado_idx');
            $table->index(['edificio_id', 'tipo'], 'conceptos_cobro_edificio_tipo_idx');
            $table->foreign('edificio_id', 'conceptos_cobro_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
        });

        Schema::create($tarifas, function (Blueprint $table) use ($conceptos): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('concepto_cobro_id');
            $table->decimal('valor', 14, 4)->nullable();
            $table->decimal('porcentaje', 9, 6)->nullable();
            $table->decimal('monto_total', 14, 4)->nullable();
            $table->unsignedSmallInteger('numero_cuotas')->nullable();
            $table->string('unidad', 30)->nullable();
            $table->enum('base_calculo', ['saldo_vencido', 'capital_vencido', 'saldo_total'])->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->enum('alcance', ['todo_el_edificio', 'departamentos_especificos']);
            $table->text('observacion')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'tarifas_concepto_edificio_id_id_uq');
            $table->index(['edificio_id', 'concepto_cobro_id', 'fecha_inicio'], 'tarifas_concepto_vigencia_idx');
            $table->foreign(['edificio_id', 'concepto_cobro_id'], 'tarifas_concepto_concepto_fk')
                ->references(['edificio_id', 'id'])->on($conceptos)->restrictOnDelete();
        });

        Schema::create($tarifaDepartamentos, function (Blueprint $table) use ($tarifas, $departamentos): void {
            $table->uuid('tarifa_id');
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->timestampsTz();

            $table->primary(['tarifa_id', 'departamento_id'], 'tarifa_departamentos_pk');
            $table->index(['edificio_id', 'departamento_id'], 'tarifa_departamentos_departamento_idx');
            $table->foreign(['edificio_id', 'tarifa_id'], 'tarifa_departamentos_tarifa_fk')
                ->references(['edificio_id', 'id'])->on($tarifas)->restrictOnDelete();
            $table->foreign(['edificio_id', 'departamento_id'], 'tarifa_departamentos_departamento_fk')
                ->references(['edificio_id', 'id'])->on($departamentos)->restrictOnDelete();
        });

        $this->createIntegrityGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        if ($pgsql) {
            DB::unprepared("DROP TRIGGER IF EXISTS tarifas_concepto_integridad_guard ON \"{$schema}\".\"tarifas_concepto\"");
            DB::unprepared("DROP TRIGGER IF EXISTS tarifa_departamentos_alcance_guard ON \"{$schema}\".\"tarifa_departamentos\"");
            DB::unprepared("DROP TRIGGER IF EXISTS tarifas_concepto_alcance_complete_guard ON \"{$schema}\".\"tarifas_concepto\"");
            DB::unprepared("DROP TRIGGER IF EXISTS tarifa_departamentos_alcance_complete_guard ON \"{$schema}\".\"tarifa_departamentos\"");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_tarifa_concepto_vigencia\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_tarifa_departamento_alcance\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_tarifa_alcance_completo\"()");
        }

        Schema::dropIfExists($table('tarifa_departamentos'));
        Schema::dropIfExists($table('tarifas_concepto'));
        Schema::dropIfExists($table('conceptos_cobro'));
    }

    private function createIntegrityGuards(bool $pgsql, string $schema): void
    {
        $prefix = $pgsql ? '"'.$schema.'".' : '';

        DB::statement("CREATE UNIQUE INDEX tarifas_concepto_abierta_uq ON {$prefix}\"tarifas_concepto\" (\"concepto_cobro_id\") WHERE \"fecha_fin\" IS NULL");

        if (! $pgsql) {
            return;
        }

        DB::statement("ALTER TABLE {$prefix}\"tarifas_concepto\" ADD CONSTRAINT tarifas_concepto_valor_check CHECK (valor IS NULL OR valor >= 0)");
        DB::statement("ALTER TABLE {$prefix}\"tarifas_concepto\" ADD CONSTRAINT tarifas_concepto_porcentaje_check CHECK (porcentaje IS NULL OR (porcentaje >= 0 AND porcentaje <= 100))");
        DB::statement("ALTER TABLE {$prefix}\"tarifas_concepto\" ADD CONSTRAINT tarifas_concepto_monto_total_check CHECK (monto_total IS NULL OR monto_total >= 0)");
        DB::statement("ALTER TABLE {$prefix}\"tarifas_concepto\" ADD CONSTRAINT tarifas_concepto_cuotas_check CHECK (numero_cuotas IS NULL OR numero_cuotas > 0)");
        DB::statement("ALTER TABLE {$prefix}\"tarifas_concepto\" ADD CONSTRAINT tarifas_concepto_fechas_check CHECK (fecha_fin IS NULL OR fecha_fin > fecha_inicio)");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_tarifa_concepto_vigencia"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de tarifas no puede eliminarse.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF OLD.fecha_fin IS NOT NULL THEN
                        RAISE EXCEPTION 'Una tarifa finalizada es inmutable.' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                       OR NEW.concepto_cobro_id IS DISTINCT FROM OLD.concepto_cobro_id
                       OR NEW.valor IS DISTINCT FROM OLD.valor
                       OR NEW.porcentaje IS DISTINCT FROM OLD.porcentaje
                       OR NEW.monto_total IS DISTINCT FROM OLD.monto_total
                       OR NEW.numero_cuotas IS DISTINCT FROM OLD.numero_cuotas
                       OR NEW.unidad IS DISTINCT FROM OLD.unidad
                       OR NEW.base_calculo IS DISTINCT FROM OLD.base_calculo
                       OR NEW.fecha_inicio IS DISTINCT FROM OLD.fecha_inicio
                       OR NEW.alcance IS DISTINCT FROM OLD.alcance
                       OR NEW.observacion IS DISTINCT FROM OLD.observacion
                       OR NEW.fecha_fin IS NULL THEN
                        RAISE EXCEPTION 'Una tarifa sólo puede modificarse para finalizar su vigencia.' USING ERRCODE = '23514';
                    END IF;
                END IF;

                PERFORM 1
                FROM "{$schema}"."conceptos_cobro"
                WHERE id = NEW.concepto_cobro_id AND edificio_id = NEW.edificio_id
                FOR UPDATE;

                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."tarifas_concepto" existing
                    WHERE existing.concepto_cobro_id = NEW.concepto_cobro_id
                      AND existing.id <> NEW.id
                      AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, 'infinity'::date)
                      AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, 'infinity'::date)
                ) THEN
                    RAISE EXCEPTION 'La tarifa se superpone con otra vigencia del concepto.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER tarifas_concepto_integridad_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON "{$schema}"."tarifas_concepto"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_tarifa_concepto_vigencia"();

            CREATE FUNCTION "{$schema}"."guard_tarifa_departamento_alcance"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM "{$schema}"."tarifas_concepto"
                    WHERE id = NEW.tarifa_id
                      AND edificio_id = NEW.edificio_id
                      AND alcance = 'departamentos_especificos'
                ) THEN
                    RAISE EXCEPTION 'La tarifa no admite departamentos específicos.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER tarifa_departamentos_alcance_guard
            BEFORE INSERT OR UPDATE
            ON "{$schema}"."tarifa_departamentos"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_tarifa_departamento_alcance"();

            CREATE FUNCTION "{$schema}"."guard_tarifa_alcance_completo"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                target_tarifa uuid;
                target_alcance text;
            BEGIN
                target_tarifa := CASE WHEN TG_OP = 'DELETE' THEN OLD.tarifa_id ELSE NEW.tarifa_id END;
                SELECT alcance INTO target_alcance
                FROM "{$schema}"."tarifas_concepto"
                WHERE id = target_tarifa;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                IF target_alcance = 'departamentos_especificos' AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."tarifa_departamentos" WHERE tarifa_id = target_tarifa
                ) THEN
                    RAISE EXCEPTION 'El alcance por departamentos requiere al menos un departamento.' USING ERRCODE = '23514';
                END IF;

                IF target_alcance = 'todo_el_edificio' AND EXISTS (
                    SELECT 1 FROM "{$schema}"."tarifa_departamentos" WHERE tarifa_id = target_tarifa
                ) THEN
                    RAISE EXCEPTION 'El alcance para todo el edificio no admite departamentos específicos.' USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            \$\$;

            CREATE CONSTRAINT TRIGGER tarifas_concepto_alcance_complete_guard
            AFTER INSERT OR UPDATE ON "{$schema}"."tarifas_concepto"
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_tarifa_alcance_completo"();

            CREATE CONSTRAINT TRIGGER tarifa_departamentos_alcance_complete_guard
            AFTER INSERT OR DELETE ON "{$schema}"."tarifa_departamentos"
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_tarifa_alcance_completo"();
            SQL);
    }
};
