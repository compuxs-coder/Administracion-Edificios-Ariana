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
        $users = $pgsql ? 'public.users' : 'users';
        $edificios = $table('edificios');
        $departamentos = $table('departamentos');
        $conceptos = $table('conceptos_cobro');
        $tarifas = $table('tarifas_concepto');
        $propietarios = $table('propietarios');
        $lotes = $table('lotes_generacion_cargos');
        $cargos = $table('cargos');

        Schema::create($lotes, function (Blueprint $table) use ($edificios, $conceptos, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('concepto_cobro_id')->nullable();
            $table->date('periodo');
            $table->uuid('ejecutado_por')->nullable();
            $table->timestampTz('fecha_ejecucion');
            $table->enum('estado', ['pendiente', 'procesando', 'completado', 'completado_con_errores', 'fallido']);
            $table->unsignedInteger('total_departamentos')->default(0);
            $table->unsignedInteger('cargos_creados')->default(0);
            $table->unsignedInteger('cargos_omitidos')->default(0);
            $table->json('errores')->nullable();
            $table->decimal('total_valor', 14, 4)->default(0);
            $table->enum('origen', ['automatico', 'manual', 'importado', 'ajuste'])->default('automatico');
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'lotes_cargos_edificio_id_id_uq');
            $table->index(['edificio_id', 'periodo', 'estado'], 'lotes_cargos_periodo_estado_idx');
            $table->foreign('edificio_id', 'lotes_cargos_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'concepto_cobro_id'], 'lotes_cargos_concepto_fk')
                ->references(['edificio_id', 'id'])->on($conceptos)->restrictOnDelete();
            $table->foreign('ejecutado_por', 'lotes_cargos_ejecutado_por_fk')->references('id')->on($users)->nullOnDelete();
        });

        Schema::create($cargos, function (Blueprint $table) use ($edificios, $departamentos, $conceptos, $tarifas, $propietarios, $lotes, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('concepto_cobro_id');
            $table->uuid('tarifa_id')->nullable();
            $table->uuid('propietario_id')->nullable();
            $table->uuid('lote_generacion_id')->nullable();
            $table->date('periodo');
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento');
            $table->string('descripcion', 255);
            $table->decimal('valor_original', 14, 4);
            $table->decimal('saldo', 14, 4);
            $table->enum('estado', ['pendiente', 'parcial', 'pagado', 'anulado'])->default('pendiente');
            $table->enum('origen', ['automatico', 'manual', 'importado', 'ajuste']);
            $table->string('referencia_generacion', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestampTz('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'cargos_edificio_id_id_uq');
            $table->index(['edificio_id', 'periodo', 'estado'], 'cargos_periodo_estado_idx');
            $table->index(['edificio_id', 'departamento_id', 'periodo'], 'cargos_departamento_periodo_idx');
            $table->index(['edificio_id', 'concepto_cobro_id', 'periodo'], 'cargos_concepto_periodo_idx');
            $table->index('fecha_vencimiento', 'cargos_vencimiento_idx');
            $table->foreign('edificio_id', 'cargos_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'departamento_id'], 'cargos_departamento_fk')
                ->references(['edificio_id', 'id'])->on($departamentos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'concepto_cobro_id'], 'cargos_concepto_fk')
                ->references(['edificio_id', 'id'])->on($conceptos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'tarifa_id'], 'cargos_tarifa_fk')
                ->references(['edificio_id', 'id'])->on($tarifas)->restrictOnDelete();
            $table->foreign('propietario_id', 'cargos_propietario_fk')->references('id')->on($propietarios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'lote_generacion_id'], 'cargos_lote_fk')
                ->references(['edificio_id', 'id'])->on($lotes)->restrictOnDelete();
            $table->foreign('created_by', 'cargos_created_by_fk')->references('id')->on($users)->nullOnDelete();
            $table->foreign('anulado_por', 'cargos_anulado_por_fk')->references('id')->on($users)->nullOnDelete();
        });

        $this->createIntegrityGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        if ($pgsql) {
            DB::unprepared("DROP TRIGGER IF EXISTS cargos_integridad_guard ON \"{$schema}\".\"cargos\"");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_cargo_integridad\"()");
        }

        Schema::dropIfExists($table('cargos'));
        Schema::dropIfExists($table('lotes_generacion_cargos'));
    }

    private function createIntegrityGuards(bool $pgsql, string $schema): void
    {
        $prefix = $pgsql ? '"'.$schema.'".' : '';
        DB::statement("CREATE UNIQUE INDEX cargos_automaticos_idempotencia_uq ON {$prefix}\"cargos\" (\"edificio_id\", \"departamento_id\", \"concepto_cobro_id\", \"periodo\") WHERE \"origen\" = 'automatico'");

        if (! $pgsql) {
            return;
        }

        DB::statement("ALTER TABLE {$prefix}\"cargos\" ADD CONSTRAINT cargos_periodo_check CHECK (date_trunc('month', periodo)::date = periodo)");
        DB::statement("ALTER TABLE {$prefix}\"cargos\" ADD CONSTRAINT cargos_montos_check CHECK (valor_original >= 0 AND saldo >= 0 AND saldo <= valor_original)");
        DB::statement("ALTER TABLE {$prefix}\"cargos\" ADD CONSTRAINT cargos_fechas_check CHECK (fecha_vencimiento >= fecha_emision)");
        DB::statement("ALTER TABLE {$prefix}\"lotes_generacion_cargos\" ADD CONSTRAINT lotes_cargos_periodo_check CHECK (date_trunc('month', periodo)::date = periodo)");
        DB::statement("ALTER TABLE {$prefix}\"lotes_generacion_cargos\" ADD CONSTRAINT lotes_cargos_total_check CHECK (total_valor >= 0)");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_cargo_integridad"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los cargos financieros no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                       OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id
                       OR NEW.concepto_cobro_id IS DISTINCT FROM OLD.concepto_cobro_id
                       OR NEW.tarifa_id IS DISTINCT FROM OLD.tarifa_id
                       OR NEW.periodo IS DISTINCT FROM OLD.periodo
                       OR NEW.fecha_emision IS DISTINCT FROM OLD.fecha_emision
                       OR NEW.fecha_vencimiento IS DISTINCT FROM OLD.fecha_vencimiento
                       OR NEW.valor_original IS DISTINCT FROM OLD.valor_original
                       OR NEW.origen IS DISTINCT FROM OLD.origen THEN
                        RAISE EXCEPTION 'Los datos financieros de un cargo son inmutables.' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.estado = 'anulado' AND OLD.saldo <> OLD.valor_original THEN
                        RAISE EXCEPTION 'No se puede anular un cargo con pagos aplicados.' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER cargos_integridad_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON "{$schema}"."cargos"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_cargo_integridad"();
            SQL);
    }
};
