<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $users = $pgsql ? 'public.users' : 'users';
        $lecturas = $table('lecturas_consumo');
        $edificios = $table('edificios');
        $departamentos = $table('departamentos');
        $conceptos = $table('conceptos_cobro');
        $cargos = $table('cargos');

        Schema::create($lecturas, function (Blueprint $table) use ($edificios, $departamentos, $conceptos, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('concepto_cobro_id');
            $table->date('periodo');
            $table->date('fecha_lectura');
            $table->decimal('lectura_anterior', 14, 4);
            $table->decimal('lectura_actual', 14, 4);
            $table->decimal('consumo', 14, 4);
            $table->string('unidad', 30);
            $table->text('observacion')->nullable();
            $table->uuid('registrado_por')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'lecturas_consumo_edificio_id_id_uq');
            $table->unique(
                ['edificio_id', 'departamento_id', 'concepto_cobro_id', 'periodo'],
                'lecturas_consumo_periodo_uq',
            );
            $table->unique(
                ['edificio_id', 'departamento_id', 'concepto_cobro_id', 'periodo', 'id'],
                'lecturas_consumo_cargo_scope_uq',
            );
            $table->index(['edificio_id', 'periodo'], 'lecturas_consumo_edificio_periodo_idx');
            $table->foreign('edificio_id', 'lecturas_consumo_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'departamento_id'], 'lecturas_consumo_departamento_fk')
                ->references(['edificio_id', 'id'])->on($departamentos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'concepto_cobro_id'], 'lecturas_consumo_concepto_fk')
                ->references(['edificio_id', 'id'])->on($conceptos)->restrictOnDelete();
            $table->foreign('registrado_por', 'lecturas_consumo_actor_fk')
                ->references('id')->on($users)->restrictOnDelete();
        });

        Schema::table($cargos, function (Blueprint $table) use ($lecturas): void {
            $table->uuid('lectura_consumo_id')->nullable()->after('tarifa_id');
            $table->foreign(
                ['edificio_id', 'departamento_id', 'concepto_cobro_id', 'periodo', 'lectura_consumo_id'],
                'cargos_lectura_consumo_fk',
            )->references(
                ['edificio_id', 'departamento_id', 'concepto_cobro_id', 'periodo', 'id'],
            )->on($lecturas)->restrictOnDelete();
        });

        $this->createIntegrityGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        $this->dropIntegrityGuards($pgsql, $schema);
        Schema::table($table('cargos'), function (Blueprint $table): void {
            $table->dropForeign('cargos_lectura_consumo_fk');
            $table->dropColumn('lectura_consumo_id');
        });
        Schema::dropIfExists($table('lecturas_consumo'));
    }

    private function createIntegrityGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER lecturas_consumo_shape_guard
                BEFORE INSERT ON lecturas_consumo
                WHEN julianday(NEW.periodo) IS NULL
                  OR julianday(NEW.fecha_lectura) IS NULL
                  OR date(NEW.periodo, 'start of month') <> date(NEW.periodo)
                  OR strftime('%Y-%m', NEW.fecha_lectura) <> strftime('%Y-%m', NEW.periodo)
                  OR typeof(NEW.lectura_anterior) NOT IN ('integer', 'real')
                  OR typeof(NEW.lectura_actual) NOT IN ('integer', 'real')
                  OR typeof(NEW.consumo) NOT IN ('integer', 'real')
                  OR NEW.lectura_anterior < 0
                  OR NEW.lectura_anterior >= 10000000000
                  OR NEW.lectura_actual >= 10000000000
                  OR NEW.consumo >= 10000000000
                  OR ROUND(NEW.lectura_anterior, 4) <> NEW.lectura_anterior
                  OR ROUND(NEW.lectura_actual, 4) <> NEW.lectura_actual
                  OR ROUND(NEW.consumo, 4) <> NEW.consumo
                  OR NEW.lectura_actual < NEW.lectura_anterior
                  OR ROUND(NEW.consumo, 4) <> ROUND(NEW.lectura_actual - NEW.lectura_anterior, 4)
                  OR trim(NEW.unidad) = ''
                BEGIN SELECT RAISE(ABORT, 'La lectura de consumo no es válida.'); END;

                CREATE TRIGGER lecturas_consumo_update_guard
                BEFORE UPDATE ON lecturas_consumo
                BEGIN SELECT RAISE(ABORT, 'El historial de lecturas es inmutable.'); END;

                CREATE TRIGGER lecturas_consumo_delete_guard
                BEFORE DELETE ON lecturas_consumo
                BEGIN SELECT RAISE(ABORT, 'El historial de lecturas no puede eliminarse.'); END;

                CREATE TRIGGER cargos_lectura_consumo_update_guard
                BEFORE UPDATE OF lectura_consumo_id, metadata ON cargos
                WHEN NEW.lectura_consumo_id IS NOT OLD.lectura_consumo_id
                  OR NEW.metadata IS NOT OLD.metadata
                BEGIN SELECT RAISE(ABORT, 'La lectura y el snapshot de cálculo de un cargo son inmutables.'); END;

                CREATE TRIGGER conceptos_cobro_cargos_history_guard
                BEFORE UPDATE OF tipo, forma_calculo ON conceptos_cobro
                WHEN (NEW.tipo IS NOT OLD.tipo OR NEW.forma_calculo IS NOT OLD.forma_calculo)
                  AND EXISTS (SELECT 1 FROM cargos WHERE concepto_cobro_id = OLD.id)
                BEGIN SELECT RAISE(ABORT, 'No se puede cambiar la clasificación de un concepto con cargos.'); END;
                SQL);

            return;
        }

        DB::statement("ALTER TABLE \"{$schema}\".\"lecturas_consumo\" ADD CONSTRAINT lecturas_consumo_periodo_check CHECK (date_trunc('month', periodo)::date = periodo)");
        DB::statement("ALTER TABLE \"{$schema}\".\"lecturas_consumo\" ADD CONSTRAINT lecturas_consumo_fecha_check CHECK (date_trunc('month', fecha_lectura)::date = periodo)");
        DB::statement("ALTER TABLE \"{$schema}\".\"lecturas_consumo\" ADD CONSTRAINT lecturas_consumo_valores_check CHECK (lectura_anterior >= 0 AND lectura_actual >= lectura_anterior AND consumo = lectura_actual - lectura_anterior)");
        DB::statement("ALTER TABLE \"{$schema}\".\"lecturas_consumo\" ADD CONSTRAINT lecturas_consumo_unidad_check CHECK (btrim(unidad) <> '')");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."prevent_lectura_consumo_mutation"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de lecturas no puede eliminarse.' USING ERRCODE = '23514';
                END IF;
                RAISE EXCEPTION 'El historial de lecturas es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER lecturas_consumo_history_guard BEFORE UPDATE OR DELETE
            ON "{$schema}"."lecturas_consumo" FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."prevent_lectura_consumo_mutation"();

            CREATE FUNCTION "{$schema}"."prevent_cargo_lectura_change"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.lectura_consumo_id IS DISTINCT FROM OLD.lectura_consumo_id
                   OR NEW.metadata::text IS DISTINCT FROM OLD.metadata::text THEN
                    RAISE EXCEPTION 'La lectura y el snapshot de cálculo de un cargo son inmutables.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER cargos_lectura_consumo_history_guard
            BEFORE UPDATE OF lectura_consumo_id, metadata ON "{$schema}"."cargos"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."prevent_cargo_lectura_change"();

            CREATE OR REPLACE FUNCTION "{$schema}"."guard_concepto_cobro_configuracion"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF (NEW.tipo IS DISTINCT FROM OLD.tipo
                    OR NEW.periodicidad IS DISTINCT FROM OLD.periodicidad
                    OR NEW.forma_calculo IS DISTINCT FROM OLD.forma_calculo)
                    AND EXISTS (SELECT 1 FROM "{$schema}"."tarifas_concepto" WHERE concepto_cobro_id = OLD.id) THEN
                    RAISE EXCEPTION 'No se puede cambiar la configuración de un concepto con tarifas.' USING ERRCODE = '23514';
                END IF;
                IF (NEW.tipo IS DISTINCT FROM OLD.tipo OR NEW.forma_calculo IS DISTINCT FROM OLD.forma_calculo)
                    AND EXISTS (SELECT 1 FROM "{$schema}"."cargos" WHERE concepto_cobro_id = OLD.id) THEN
                    RAISE EXCEPTION 'No se puede cambiar la clasificación de un concepto con cargos.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."lock_concepto_cargo_insert"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                PERFORM 1 FROM "{$schema}"."conceptos_cobro"
                WHERE id = NEW.concepto_cobro_id AND edificio_id = NEW.edificio_id
                FOR UPDATE;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER cargos_concepto_classification_lock
            BEFORE INSERT ON "{$schema}"."cargos"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."lock_concepto_cargo_insert"();
            SQL);
    }

    private function dropIntegrityGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            foreach ([
                'lecturas_consumo_shape_guard',
                'lecturas_consumo_update_guard',
                'lecturas_consumo_delete_guard',
                'cargos_lectura_consumo_update_guard',
                'conceptos_cobro_cargos_history_guard',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS cargos_concepto_classification_lock ON "{$schema}"."cargos";
            DROP FUNCTION IF EXISTS "{$schema}"."lock_concepto_cargo_insert"();
            DROP TRIGGER IF EXISTS cargos_lectura_consumo_history_guard ON "{$schema}"."cargos";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_cargo_lectura_change"();
            DROP TRIGGER IF EXISTS lecturas_consumo_history_guard ON "{$schema}"."lecturas_consumo";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_lectura_consumo_mutation"();

            CREATE OR REPLACE FUNCTION "{$schema}"."guard_concepto_cobro_configuracion"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF (NEW.tipo IS DISTINCT FROM OLD.tipo
                    OR NEW.periodicidad IS DISTINCT FROM OLD.periodicidad
                    OR NEW.forma_calculo IS DISTINCT FROM OLD.forma_calculo)
                    AND EXISTS (
                        SELECT 1 FROM "{$schema}"."tarifas_concepto"
                        WHERE concepto_cobro_id = OLD.id
                    ) THEN
                    RAISE EXCEPTION 'No se puede cambiar la configuración de un concepto con tarifas.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL);
    }
};
