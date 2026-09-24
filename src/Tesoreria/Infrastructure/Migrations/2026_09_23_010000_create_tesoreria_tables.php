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
        $edificios = $table('edificios');
        $desembolsos = $table('desembolsos');
        $cuentas = $table('cuentas_tesoreria');
        $movimientos = $table('movimientos_tesoreria');
        $conciliaciones = $table('conciliaciones_tesoreria');

        Schema::table($desembolsos, function (Blueprint $table): void {
            $table->unique(['edificio_id', 'id'], 'desembolsos_edificio_id_uq');
        });
        Schema::create($cuentas, function (Blueprint $table) use ($edificios, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->string('codigo', 30);
            $table->string('nombre', 120);
            $table->enum('tipo', ['bancaria', 'caja']);
            $table->string('entidad_financiera', 120)->nullable();
            $table->string('tipo_cuenta_bancaria', 60)->nullable();
            $table->string('numero_cuenta', 120)->nullable();
            $table->enum('estado', ['activa', 'inactiva']);
            $table->uuid('registrado_por')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'cuentas_tesoreria_scope_uq');
            $table->unique(['edificio_id', 'codigo'], 'cuentas_tesoreria_codigo_uq');
            $table->unique(['edificio_id', 'numero_cuenta'], 'cuentas_tesoreria_numero_uq');
            $table->index(['edificio_id', 'estado', 'tipo'], 'cuentas_tesoreria_estado_tipo_idx');
            $table->foreign('edificio_id', 'cuentas_tesoreria_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign('registrado_por', 'cuentas_tesoreria_registrado_por_fk')->references('id')->on($users)->restrictOnDelete();
        });
        Schema::create($movimientos, function (Blueprint $table) use ($cuentas, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('cuenta_id');
            $table->date('fecha_movimiento');
            $table->enum('naturaleza', ['ingreso', 'egreso']);
            $table->decimal('monto', 14, 4);
            $table->string('referencia', 120)->nullable();
            $table->string('descripcion', 255);
            $table->enum('estado', ['registrado', 'anulado']);
            $table->uuid('registrado_por')->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestampTz('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'cuenta_id', 'id'], 'movimientos_tesoreria_scope_uq');
            $table->index(['edificio_id', 'fecha_movimiento'], 'movimientos_tesoreria_fecha_idx');
            $table->index(['cuenta_id', 'estado', 'naturaleza'], 'movimientos_tesoreria_cuenta_estado_idx');
            $table->foreign(['edificio_id', 'cuenta_id'], 'movimientos_tesoreria_cuenta_fk')
                ->references(['edificio_id', 'id'])->on($cuentas)->restrictOnDelete();
            $table->foreign('registrado_por', 'movimientos_tesoreria_registrado_por_fk')->references('id')->on($users)->restrictOnDelete();
            $table->foreign('anulado_por', 'movimientos_tesoreria_anulado_por_fk')->references('id')->on($users)->restrictOnDelete();
        });
        Schema::create($conciliaciones, function (Blueprint $table) use ($movimientos, $desembolsos, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('cuenta_id');
            $table->uuid('movimiento_id');
            $table->uuid('desembolso_id');
            $table->enum('estado', ['vigente', 'revertida']);
            $table->uuid('conciliado_por')->nullable();
            $table->timestampTz('conciliado_at');
            $table->text('nota')->nullable();
            $table->uuid('revertido_por')->nullable();
            $table->timestampTz('revertido_at')->nullable();
            $table->text('motivo_reversion')->nullable();
            $table->timestampsTz();

            $table->index(['movimiento_id', 'estado'], 'conciliaciones_tesoreria_movimiento_idx');
            $table->index(['desembolso_id', 'estado'], 'conciliaciones_tesoreria_desembolso_idx');
            $table->foreign(['edificio_id', 'cuenta_id', 'movimiento_id'], 'conciliaciones_tesoreria_movimiento_fk')
                ->references(['edificio_id', 'cuenta_id', 'id'])->on($movimientos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'desembolso_id'], 'conciliaciones_tesoreria_desembolso_fk')
                ->references(['edificio_id', 'id'])->on($desembolsos)->restrictOnDelete();
            $table->foreign('conciliado_por', 'conciliaciones_tesoreria_conciliado_por_fk')->references('id')->on($users)->restrictOnDelete();
            $table->foreign('revertido_por', 'conciliaciones_tesoreria_revertido_por_fk')->references('id')->on($users)->restrictOnDelete();
        });

        $this->createGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        foreach (['conciliaciones_tesoreria', 'movimientos_tesoreria', 'cuentas_tesoreria'] as $name) {
            if (Schema::hasTable($table($name)) && DB::table($table($name))->exists()) {
                throw new LogicException('No se puede revertir ETAPA 15 porque existe historial de tesorería que debe conservarse.');
            }
        }
        $this->dropGuards($pgsql, $schema);
        Schema::dropIfExists($table('conciliaciones_tesoreria'));
        Schema::dropIfExists($table('movimientos_tesoreria'));
        Schema::dropIfExists($table('cuentas_tesoreria'));
        Schema::table($table('desembolsos'), function (Blueprint $table): void {
            $table->dropUnique('desembolsos_edificio_id_uq');
        });
    }

    private function createGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            $this->createSqliteGuards();

            return;
        }

        DB::unprepared(<<<SQL
            CREATE UNIQUE INDEX conciliaciones_tesoreria_movimiento_vigente_uq
            ON "{$schema}"."conciliaciones_tesoreria" (movimiento_id) WHERE estado = 'vigente';
            CREATE UNIQUE INDEX conciliaciones_tesoreria_desembolso_vigente_uq
            ON "{$schema}"."conciliaciones_tesoreria" (desembolso_id) WHERE estado = 'vigente';

            ALTER TABLE "{$schema}"."movimientos_tesoreria"
                ADD CONSTRAINT movimientos_tesoreria_monto_check CHECK (monto > 0);
            ALTER TABLE "{$schema}"."cuentas_tesoreria"
                ADD CONSTRAINT cuentas_tesoreria_campos_check CHECK (
                    (tipo = 'bancaria'
                        AND entidad_financiera IS NOT NULL AND BTRIM(entidad_financiera) <> ''
                        AND tipo_cuenta_bancaria IS NOT NULL AND BTRIM(tipo_cuenta_bancaria) <> ''
                        AND numero_cuenta IS NOT NULL AND BTRIM(numero_cuenta) <> '')
                    OR (tipo = 'caja' AND entidad_financiera IS NULL
                        AND tipo_cuenta_bancaria IS NULL AND numero_cuenta IS NULL)
                );

            CREATE FUNCTION "{$schema}"."guard_cuenta_tesoreria"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las cuentas de tesorería no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF COALESCE(BTRIM(NEW.codigo), '') = '' OR NEW.codigo <> UPPER(BTRIM(NEW.codigo))
                   OR COALESCE(BTRIM(NEW.nombre), '') = ''
                   OR (NEW.tipo = 'bancaria' AND (
                        NEW.entidad_financiera IS NULL OR BTRIM(NEW.entidad_financiera) = ''
                        OR NEW.tipo_cuenta_bancaria IS NULL OR BTRIM(NEW.tipo_cuenta_bancaria) = ''
                        OR NEW.numero_cuenta IS NULL OR BTRIM(NEW.numero_cuenta) = ''))
                   OR (NEW.tipo = 'caja' AND (NEW.entidad_financiera IS NOT NULL
                        OR NEW.tipo_cuenta_bancaria IS NOT NULL OR NEW.numero_cuenta IS NOT NULL)) THEN
                    RAISE EXCEPTION 'Los datos de la cuenta de tesorería no son válidos.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'activa' OR NEW.registrado_por IS NULL THEN
                        RAISE EXCEPTION 'Una cuenta debe crearse activa y con actor.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.registrado_por IS DISTINCT FROM OLD.registrado_por
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'El origen de la cuenta de tesorería es inmutable.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM "{$schema}"."movimientos_tesoreria" WHERE cuenta_id = OLD.id)
                   AND (NEW.tipo IS DISTINCT FROM OLD.tipo OR NEW.entidad_financiera IS DISTINCT FROM OLD.entidad_financiera
                        OR NEW.tipo_cuenta_bancaria IS DISTINCT FROM OLD.tipo_cuenta_bancaria
                        OR NEW.numero_cuenta IS DISTINCT FROM OLD.numero_cuenta) THEN
                    RAISE EXCEPTION 'La identidad de una cuenta con movimientos es inmutable.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_movimiento_tesoreria"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE account_state text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los movimientos de tesorería no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT estado INTO account_state FROM "{$schema}"."cuentas_tesoreria"
                    WHERE id = NEW.cuenta_id AND edificio_id = NEW.edificio_id FOR UPDATE;
                    IF account_state IS DISTINCT FROM 'activa' OR NEW.estado <> 'registrado'
                       OR NEW.monto <= 0 OR NEW.fecha_movimiento > CURRENT_DATE
                       OR COALESCE(BTRIM(NEW.descripcion), '') = '' OR NEW.registrado_por IS NULL
                       OR NEW.anulado_por IS NOT NULL OR NEW.anulado_at IS NOT NULL
                       OR NEW.motivo_anulacion IS NOT NULL THEN
                        RAISE EXCEPTION 'El movimiento debe registrarse en una cuenta activa con datos válidos.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.cuenta_id IS DISTINCT FROM OLD.cuenta_id
                   OR NEW.fecha_movimiento IS DISTINCT FROM OLD.fecha_movimiento
                   OR NEW.naturaleza IS DISTINCT FROM OLD.naturaleza OR NEW.monto IS DISTINCT FROM OLD.monto
                   OR NEW.referencia IS DISTINCT FROM OLD.referencia OR NEW.descripcion IS DISTINCT FROM OLD.descripcion
                   OR NEW.registrado_por IS DISTINCT FROM OLD.registrado_por
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Los datos financieros del movimiento son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'registrado' AND NEW.estado = 'registrado'
                   AND NEW.anulado_por IS NULL AND NEW.anulado_at IS NULL AND NEW.motivo_anulacion IS NULL THEN
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'registrado' AND NEW.estado = 'anulado'
                   AND NEW.anulado_por IS NOT NULL AND NEW.anulado_at IS NOT NULL
                   AND COALESCE(BTRIM(NEW.motivo_anulacion), '') <> '' THEN
                    IF EXISTS (SELECT 1 FROM "{$schema}"."conciliaciones_tesoreria"
                               WHERE movimiento_id = OLD.id AND estado = 'vigente') THEN
                        RAISE EXCEPTION 'Debe revertirse la conciliación antes de anular el movimiento.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'El movimiento sólo puede anularse una vez.' USING ERRCODE = '23514';
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_conciliacion_tesoreria"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE movement record; account record; payment record;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las conciliaciones de tesorería no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT * INTO account FROM "{$schema}"."cuentas_tesoreria"
                    WHERE id = NEW.cuenta_id AND edificio_id = NEW.edificio_id FOR KEY SHARE;
                    SELECT * INTO movement FROM "{$schema}"."movimientos_tesoreria"
                    WHERE id = NEW.movimiento_id AND cuenta_id = NEW.cuenta_id
                      AND edificio_id = NEW.edificio_id FOR UPDATE;
                    SELECT * INTO payment FROM "{$schema}"."desembolsos"
                    WHERE id = NEW.desembolso_id AND edificio_id = NEW.edificio_id FOR UPDATE;
                    IF account.id IS NULL OR movement.id IS NULL OR payment.id IS NULL
                       OR NEW.estado <> 'vigente' OR NEW.conciliado_por IS NULL OR NEW.conciliado_at IS NULL
                       OR NEW.revertido_por IS NOT NULL OR NEW.revertido_at IS NOT NULL OR NEW.motivo_reversion IS NOT NULL
                       OR movement.estado <> 'registrado' OR movement.naturaleza <> 'egreso'
                       OR payment.estado <> 'registrado' OR movement.monto <> payment.monto
                       OR (account.tipo = 'caja' AND payment.forma_pago <> 'efectivo')
                       OR (account.tipo = 'bancaria' AND payment.forma_pago = 'efectivo') THEN
                        RAISE EXCEPTION 'La conciliación no cumple monto, estado o forma de pago.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.cuenta_id IS DISTINCT FROM OLD.cuenta_id OR NEW.movimiento_id IS DISTINCT FROM OLD.movimiento_id
                   OR NEW.desembolso_id IS DISTINCT FROM OLD.desembolso_id
                   OR NEW.conciliado_por IS DISTINCT FROM OLD.conciliado_por
                   OR NEW.conciliado_at IS DISTINCT FROM OLD.conciliado_at OR NEW.nota IS DISTINCT FROM OLD.nota
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Los datos de origen de la conciliación son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'vigente' AND NEW.estado = 'revertida'
                   AND NEW.revertido_por IS NOT NULL AND NEW.revertido_at IS NOT NULL
                   AND COALESCE(BTRIM(NEW.motivo_reversion), '') <> '' THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'La conciliación sólo puede revertirse una vez.' USING ERRCODE = '23514';
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_desembolso_conciliado"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.estado = 'registrado' AND NEW.estado = 'anulado'
                   AND EXISTS (SELECT 1 FROM "{$schema}"."conciliaciones_tesoreria"
                               WHERE desembolso_id = OLD.id AND estado = 'vigente') THEN
                    RAISE EXCEPTION 'Debe revertirse la conciliación antes de anular el desembolso.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER cuentas_tesoreria_history_guard
            BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."cuentas_tesoreria"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_cuenta_tesoreria"();
            CREATE TRIGGER movimientos_tesoreria_history_guard
            BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."movimientos_tesoreria"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_movimiento_tesoreria"();
            CREATE TRIGGER conciliaciones_tesoreria_history_guard
            BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."conciliaciones_tesoreria"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_conciliacion_tesoreria"();
            CREATE TRIGGER desembolsos_conciliacion_guard
            BEFORE UPDATE ON "{$schema}"."desembolsos"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_desembolso_conciliado"();
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX conciliaciones_tesoreria_movimiento_vigente_uq
            ON conciliaciones_tesoreria (movimiento_id) WHERE estado = 'vigente';
            CREATE UNIQUE INDEX conciliaciones_tesoreria_desembolso_vigente_uq
            ON conciliaciones_tesoreria (desembolso_id) WHERE estado = 'vigente';

            CREATE TRIGGER cuentas_tesoreria_insert_guard BEFORE INSERT ON cuentas_tesoreria
            WHEN COALESCE(TRIM(NEW.codigo), '') = '' OR NEW.codigo <> UPPER(TRIM(NEW.codigo))
              OR COALESCE(TRIM(NEW.nombre), '') = '' OR NEW.estado <> 'activa' OR NEW.registrado_por IS NULL
              OR (NEW.tipo = 'bancaria' AND (NEW.entidad_financiera IS NULL OR TRIM(NEW.entidad_financiera) = ''
                  OR NEW.tipo_cuenta_bancaria IS NULL OR TRIM(NEW.tipo_cuenta_bancaria) = ''
                  OR NEW.numero_cuenta IS NULL OR TRIM(NEW.numero_cuenta) = ''))
              OR (NEW.tipo = 'caja' AND (NEW.entidad_financiera IS NOT NULL
                  OR NEW.tipo_cuenta_bancaria IS NOT NULL OR NEW.numero_cuenta IS NOT NULL))
            BEGIN SELECT RAISE(ABORT, 'Los datos de la cuenta de tesorería no son válidos.'); END;
            CREATE TRIGGER cuentas_tesoreria_update_guard BEFORE UPDATE ON cuentas_tesoreria
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id
              OR NEW.registrado_por IS NOT OLD.registrado_por OR NEW.created_at IS NOT OLD.created_at
              OR COALESCE(TRIM(NEW.codigo), '') = '' OR NEW.codigo <> UPPER(TRIM(NEW.codigo))
              OR COALESCE(TRIM(NEW.nombre), '') = ''
              OR (NEW.tipo = 'bancaria' AND (NEW.entidad_financiera IS NULL OR TRIM(NEW.entidad_financiera) = ''
                  OR NEW.tipo_cuenta_bancaria IS NULL OR TRIM(NEW.tipo_cuenta_bancaria) = ''
                  OR NEW.numero_cuenta IS NULL OR TRIM(NEW.numero_cuenta) = ''))
              OR (NEW.tipo = 'caja' AND (NEW.entidad_financiera IS NOT NULL
                  OR NEW.tipo_cuenta_bancaria IS NOT NULL OR NEW.numero_cuenta IS NOT NULL))
              OR (EXISTS (SELECT 1 FROM movimientos_tesoreria WHERE cuenta_id = OLD.id) AND (
                  NEW.tipo IS NOT OLD.tipo OR NEW.entidad_financiera IS NOT OLD.entidad_financiera
                  OR NEW.tipo_cuenta_bancaria IS NOT OLD.tipo_cuenta_bancaria
                  OR NEW.numero_cuenta IS NOT OLD.numero_cuenta))
            BEGIN SELECT RAISE(ABORT, 'La cuenta de tesorería no admite este cambio.'); END;
            CREATE TRIGGER cuentas_tesoreria_delete_guard BEFORE DELETE ON cuentas_tesoreria
            BEGIN SELECT RAISE(ABORT, 'Las cuentas de tesorería no pueden eliminarse.'); END;

            CREATE TRIGGER movimientos_tesoreria_insert_guard BEFORE INSERT ON movimientos_tesoreria
            WHEN NEW.estado <> 'registrado' OR NEW.monto <= 0
              OR DATE(NEW.fecha_movimiento) IS NULL OR DATE(NEW.fecha_movimiento) > DATE('now')
              OR COALESCE(TRIM(NEW.descripcion), '') = '' OR NEW.registrado_por IS NULL
              OR NEW.anulado_por IS NOT NULL OR NEW.anulado_at IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
              OR NOT EXISTS (SELECT 1 FROM cuentas_tesoreria
                  WHERE id = NEW.cuenta_id AND edificio_id = NEW.edificio_id AND estado = 'activa')
            BEGIN SELECT RAISE(ABORT, 'El movimiento debe registrarse en una cuenta activa con datos válidos.'); END;
            CREATE TRIGGER movimientos_tesoreria_update_guard BEFORE UPDATE ON movimientos_tesoreria
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.cuenta_id IS NOT OLD.cuenta_id
              OR NEW.fecha_movimiento IS NOT OLD.fecha_movimiento OR NEW.naturaleza IS NOT OLD.naturaleza
              OR NEW.monto IS NOT OLD.monto OR NEW.referencia IS NOT OLD.referencia
              OR NEW.descripcion IS NOT OLD.descripcion OR NEW.registrado_por IS NOT OLD.registrado_por
              OR NEW.created_at IS NOT OLD.created_at
              OR NOT (
                (OLD.estado = 'registrado' AND NEW.estado = 'registrado'
                  AND NEW.anulado_por IS NULL AND NEW.anulado_at IS NULL AND NEW.motivo_anulacion IS NULL)
                OR (OLD.estado = 'registrado' AND NEW.estado = 'anulado'
                  AND NEW.anulado_por IS NOT NULL AND NEW.anulado_at IS NOT NULL
                  AND COALESCE(TRIM(NEW.motivo_anulacion), '') <> ''
                  AND NOT EXISTS (SELECT 1 FROM conciliaciones_tesoreria
                      WHERE movimiento_id = OLD.id AND estado = 'vigente')))
            BEGIN SELECT RAISE(ABORT, 'El movimiento sólo admite una anulación válida.'); END;
            CREATE TRIGGER movimientos_tesoreria_delete_guard BEFORE DELETE ON movimientos_tesoreria
            BEGIN SELECT RAISE(ABORT, 'Los movimientos de tesorería no pueden eliminarse.'); END;

            CREATE TRIGGER conciliaciones_tesoreria_insert_guard BEFORE INSERT ON conciliaciones_tesoreria
            WHEN NEW.estado <> 'vigente' OR NEW.conciliado_por IS NULL OR NEW.conciliado_at IS NULL
              OR NEW.revertido_por IS NOT NULL OR NEW.revertido_at IS NOT NULL OR NEW.motivo_reversion IS NOT NULL
              OR NOT EXISTS (SELECT 1 FROM movimientos_tesoreria
                  WHERE id = NEW.movimiento_id AND edificio_id = NEW.edificio_id AND cuenta_id = NEW.cuenta_id
                    AND estado = 'registrado' AND naturaleza = 'egreso')
              OR NOT EXISTS (SELECT 1 FROM desembolsos
                  WHERE id = NEW.desembolso_id AND edificio_id = NEW.edificio_id AND estado = 'registrado')
              OR ROUND((SELECT monto FROM movimientos_tesoreria WHERE id = NEW.movimiento_id) * 10000)
                 <> ROUND((SELECT monto FROM desembolsos WHERE id = NEW.desembolso_id) * 10000)
              OR ((SELECT tipo FROM cuentas_tesoreria WHERE id = NEW.cuenta_id) = 'caja'
                  AND (SELECT forma_pago FROM desembolsos WHERE id = NEW.desembolso_id) <> 'efectivo')
              OR ((SELECT tipo FROM cuentas_tesoreria WHERE id = NEW.cuenta_id) = 'bancaria'
                  AND (SELECT forma_pago FROM desembolsos WHERE id = NEW.desembolso_id) = 'efectivo')
            BEGIN SELECT RAISE(ABORT, 'La conciliación no cumple monto, estado o forma de pago.'); END;
            CREATE TRIGGER conciliaciones_tesoreria_update_guard BEFORE UPDATE ON conciliaciones_tesoreria
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.cuenta_id IS NOT OLD.cuenta_id
              OR NEW.movimiento_id IS NOT OLD.movimiento_id OR NEW.desembolso_id IS NOT OLD.desembolso_id
              OR NEW.conciliado_por IS NOT OLD.conciliado_por OR NEW.conciliado_at IS NOT OLD.conciliado_at
              OR NEW.nota IS NOT OLD.nota OR NEW.created_at IS NOT OLD.created_at
              OR NOT (OLD.estado = 'vigente' AND NEW.estado = 'revertida'
                  AND NEW.revertido_por IS NOT NULL AND NEW.revertido_at IS NOT NULL
                  AND COALESCE(TRIM(NEW.motivo_reversion), '') <> '')
            BEGIN SELECT RAISE(ABORT, 'La conciliación sólo puede revertirse una vez.'); END;
            CREATE TRIGGER conciliaciones_tesoreria_delete_guard BEFORE DELETE ON conciliaciones_tesoreria
            BEGIN SELECT RAISE(ABORT, 'Las conciliaciones de tesorería no pueden eliminarse.'); END;

            CREATE TRIGGER desembolsos_conciliacion_guard BEFORE UPDATE ON desembolsos
            WHEN OLD.estado = 'registrado' AND NEW.estado = 'anulado'
              AND EXISTS (SELECT 1 FROM conciliaciones_tesoreria
                  WHERE desembolso_id = OLD.id AND estado = 'vigente')
            BEGIN SELECT RAISE(ABORT, 'Debe revertirse la conciliación antes de anular el desembolso.'); END;
            SQL);
    }

    private function dropGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            foreach ([
                'desembolsos_conciliacion_guard',
                'conciliaciones_tesoreria_delete_guard', 'conciliaciones_tesoreria_update_guard',
                'conciliaciones_tesoreria_insert_guard', 'movimientos_tesoreria_delete_guard',
                'movimientos_tesoreria_update_guard', 'movimientos_tesoreria_insert_guard',
                'cuentas_tesoreria_delete_guard', 'cuentas_tesoreria_update_guard',
                'cuentas_tesoreria_insert_guard',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS desembolsos_conciliacion_guard ON "{$schema}"."desembolsos";
            DROP TRIGGER IF EXISTS conciliaciones_tesoreria_history_guard ON "{$schema}"."conciliaciones_tesoreria";
            DROP TRIGGER IF EXISTS movimientos_tesoreria_history_guard ON "{$schema}"."movimientos_tesoreria";
            DROP TRIGGER IF EXISTS cuentas_tesoreria_history_guard ON "{$schema}"."cuentas_tesoreria";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_desembolso_conciliado"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_conciliacion_tesoreria"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_movimiento_tesoreria"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_cuenta_tesoreria"();
            SQL);
    }
};
