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
        $propietarios = $table('propietarios');
        $cargos = $table('cargos');
        $pagos = $table('pagos');
        $aplicaciones = $table('aplicaciones_pago');
        $consecutivos = $table('consecutivos_pago');

        Schema::table($cargos, function (Blueprint $table): void {
            $table->unique(['edificio_id', 'id', 'departamento_id'], 'cargos_edificio_id_id_departamento_id_uq');
        });

        Schema::create($consecutivos, function (Blueprint $table): void {
            $table->string('clave', 30)->primary();
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestampsTz();
        });

        Schema::create($pagos, function (Blueprint $table) use ($edificios, $departamentos, $propietarios, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('propietario_id')->nullable();
            $table->string('numero', 30)->unique();
            $table->date('fecha_pago');
            $table->decimal('monto_recibido', 14, 4);
            $table->enum('forma_pago', ['efectivo', 'transferencia', 'deposito', 'tarjeta', 'cheque', 'otro']);
            $table->string('referencia', 120)->nullable();
            $table->text('observacion')->nullable();
            $table->enum('estado', ['registrado', 'anulado'])->default('registrado');
            $table->enum('origen', ['manual', 'importado', 'ajuste'])->default('manual');
            $table->json('metadata')->nullable();
            $table->uuid('registrado_por')->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestampTz('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id', 'departamento_id'], 'pagos_edificio_id_id_departamento_id_uq');
            $table->index(['edificio_id', 'departamento_id', 'fecha_pago'], 'pagos_departamento_fecha_idx');
            $table->index(['edificio_id', 'estado', 'fecha_pago'], 'pagos_estado_fecha_idx');
            $table->index('propietario_id', 'pagos_propietario_idx');
            $table->foreign('edificio_id', 'pagos_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'departamento_id'], 'pagos_departamento_fk')->references(['edificio_id', 'id'])->on($departamentos)->restrictOnDelete();
            $table->foreign('propietario_id', 'pagos_propietario_fk')->references('id')->on($propietarios)->restrictOnDelete();
            $table->foreign('registrado_por', 'pagos_registrado_por_fk')->references('id')->on($users)->nullOnDelete();
            $table->foreign('anulado_por', 'pagos_anulado_por_fk')->references('id')->on($users)->nullOnDelete();
        });

        Schema::create($aplicaciones, function (Blueprint $table) use ($pagos, $cargos): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('pago_id');
            $table->uuid('cargo_id');
            $table->decimal('monto_aplicado', 14, 4);
            $table->timestampsTz();

            $table->unique(['pago_id', 'cargo_id'], 'aplicaciones_pago_cargo_uq');
            $table->index('pago_id', 'aplicaciones_pago_pago_idx');
            $table->index('cargo_id', 'aplicaciones_pago_cargo_idx');
            $table->foreign(['edificio_id', 'pago_id', 'departamento_id'], 'aplicaciones_pago_pago_fk')
                ->references(['edificio_id', 'id', 'departamento_id'])->on($pagos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'cargo_id', 'departamento_id'], 'aplicaciones_pago_cargo_fk')
                ->references(['edificio_id', 'id', 'departamento_id'])->on($cargos)->restrictOnDelete();
        });

        if ($pgsql) {
            $this->createIntegrityGuards($schema);
        }
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        if ($pgsql) {
            DB::unprepared("DROP TRIGGER IF EXISTS pagos_integridad_guard ON \"{$schema}\".\"pagos\"");
            DB::unprepared("DROP TRIGGER IF EXISTS aplicaciones_pago_integridad_guard ON \"{$schema}\".\"aplicaciones_pago\"");
            DB::unprepared("DROP TRIGGER IF EXISTS aplicaciones_pago_balance_guard ON \"{$schema}\".\"aplicaciones_pago\"");
            DB::unprepared("DROP TRIGGER IF EXISTS cargos_pago_balance_guard ON \"{$schema}\".\"cargos\"");
            DB::unprepared("DROP TRIGGER IF EXISTS pagos_cargos_balance_guard ON \"{$schema}\".\"pagos\"");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_pago_integridad\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_aplicacion_pago_integridad\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"validar_aplicacion_pago_balance\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"validar_cargo_pago_balance\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"validar_pago_cargos_balance\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"validar_cargo_pago_balance_for_cargo\"(uuid)");
        }

        Schema::dropIfExists($table('aplicaciones_pago'));
        Schema::dropIfExists($table('pagos'));
        Schema::dropIfExists($table('consecutivos_pago'));
        Schema::table($table('cargos'), function (Blueprint $table): void {
            $table->dropUnique('cargos_edificio_id_id_departamento_id_uq');
        });
    }

    private function createIntegrityGuards(string $schema): void
    {
        $prefix = '"'.$schema.'".';
        DB::statement("ALTER TABLE {$prefix}\"pagos\" ADD CONSTRAINT pagos_monto_check CHECK (monto_recibido > 0)");
        DB::statement("ALTER TABLE {$prefix}\"aplicaciones_pago\" ADD CONSTRAINT aplicaciones_pago_monto_check CHECK (monto_aplicado > 0)");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_pago_integridad"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los pagos financieros no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                       OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id
                       OR NEW.propietario_id IS DISTINCT FROM OLD.propietario_id
                       OR NEW.numero IS DISTINCT FROM OLD.numero
                       OR NEW.fecha_pago IS DISTINCT FROM OLD.fecha_pago
                       OR NEW.monto_recibido IS DISTINCT FROM OLD.monto_recibido
                       OR NEW.forma_pago IS DISTINCT FROM OLD.forma_pago
                       OR NEW.referencia IS DISTINCT FROM OLD.referencia
                       OR NEW.origen IS DISTINCT FROM OLD.origen
                       OR NEW.registrado_por IS DISTINCT FROM OLD.registrado_por THEN
                        RAISE EXCEPTION 'Los datos financieros de un pago son inmutables.' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.estado <> 'registrado' OR NEW.estado <> 'anulado' THEN
                        RAISE EXCEPTION 'Un pago sólo puede anularse una vez.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL OR COALESCE(BTRIM(NEW.motivo_anulacion), '') = '' THEN
                        RAISE EXCEPTION 'La anulación requiere usuario, fecha y motivo.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_aplicacion_pago_integridad"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE estado_pago text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Las aplicaciones de pago son inmutables.' USING ERRCODE = '23514';
                END IF;
                SELECT estado INTO estado_pago FROM "{$schema}"."pagos" WHERE id = NEW.pago_id FOR KEY SHARE;
                IF estado_pago <> 'registrado' THEN
                    RAISE EXCEPTION 'No se pueden aplicar pagos anulados.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."validar_cargo_pago_balance"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE aplicado numeric(14,4); esperado numeric(14,4); estado_esperado text;
            BEGIN
                IF NEW.estado = 'anulado' THEN
                    SELECT COALESCE(SUM(a.monto_aplicado), 0) INTO aplicado
                    FROM "{$schema}"."aplicaciones_pago" a
                    INNER JOIN "{$schema}"."pagos" p ON p.id = a.pago_id
                    WHERE a.cargo_id = NEW.id AND p.estado = 'registrado';
                    IF aplicado <> 0 THEN
                        RAISE EXCEPTION 'Un cargo anulado no puede tener pagos activos.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END IF;
                SELECT COALESCE(SUM(a.monto_aplicado), 0) INTO aplicado
                FROM "{$schema}"."aplicaciones_pago" a
                INNER JOIN "{$schema}"."pagos" p ON p.id = a.pago_id
                WHERE a.cargo_id = NEW.id AND p.estado = 'registrado';
                esperado := NEW.valor_original - aplicado;
                estado_esperado := CASE WHEN esperado = NEW.valor_original THEN 'pendiente' WHEN esperado = 0 THEN 'pagado' ELSE 'parcial' END;
                IF esperado < 0 OR NEW.saldo <> esperado OR NEW.estado <> estado_esperado THEN
                    RAISE EXCEPTION 'El saldo y estado del cargo deben corresponder a sus pagos aplicados.' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."validar_aplicacion_pago_balance"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE total_aplicado numeric(14,4); monto_recibido numeric(14,4);
            BEGIN
                SELECT COALESCE(SUM(monto_aplicado), 0) INTO total_aplicado FROM "{$schema}"."aplicaciones_pago" WHERE pago_id = NEW.pago_id;
                SELECT monto_recibido INTO monto_recibido FROM "{$schema}"."pagos" WHERE id = NEW.pago_id;
                IF total_aplicado > monto_recibido THEN
                    RAISE EXCEPTION 'Las aplicaciones exceden el valor recibido.' USING ERRCODE = '23514';
                END IF;
                PERFORM "{$schema}"."validar_cargo_pago_balance_for_cargo"(NEW.cargo_id);
                RETURN NULL;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."validar_pago_cargos_balance"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE total_aplicado numeric(14,4); cargo record;
            BEGIN
                SELECT COALESCE(SUM(monto_aplicado), 0) INTO total_aplicado FROM "{$schema}"."aplicaciones_pago" WHERE pago_id = NEW.id;
                IF NEW.estado = 'registrado' AND total_aplicado > NEW.monto_recibido THEN
                    RAISE EXCEPTION 'Las aplicaciones exceden el valor recibido.' USING ERRCODE = '23514';
                END IF;
                FOR cargo IN SELECT DISTINCT c.* FROM "{$schema}"."cargos" c INNER JOIN "{$schema}"."aplicaciones_pago" a ON a.cargo_id = c.id WHERE a.pago_id = NEW.id LOOP
                    PERFORM "{$schema}"."validar_cargo_pago_balance_for_cargo"(cargo.id);
                END LOOP;
                RETURN NULL;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."validar_cargo_pago_balance_for_cargo"(cargo_uuid uuid)
            RETURNS void
            LANGUAGE plpgsql
            AS \$\$
            DECLARE cargo record; aplicado numeric(14,4); esperado numeric(14,4); estado_esperado text;
            BEGIN
                SELECT * INTO cargo FROM "{$schema}"."cargos" WHERE id = cargo_uuid;
                SELECT COALESCE(SUM(a.monto_aplicado), 0) INTO aplicado FROM "{$schema}"."aplicaciones_pago" a INNER JOIN "{$schema}"."pagos" p ON p.id = a.pago_id WHERE a.cargo_id = cargo_uuid AND p.estado = 'registrado';
                IF cargo.estado = 'anulado' THEN
                    IF aplicado <> 0 THEN RAISE EXCEPTION 'Un cargo anulado no puede tener pagos activos.' USING ERRCODE = '23514'; END IF;
                    RETURN;
                END IF;
                esperado := cargo.valor_original - aplicado;
                estado_esperado := CASE WHEN esperado = cargo.valor_original THEN 'pendiente' WHEN esperado = 0 THEN 'pagado' ELSE 'parcial' END;
                IF esperado < 0 OR cargo.saldo <> esperado OR cargo.estado <> estado_esperado THEN
                    RAISE EXCEPTION 'El saldo y estado del cargo deben corresponder a sus pagos aplicados.' USING ERRCODE = '23514';
                END IF;
            END;
            \$\$;

            CREATE TRIGGER pagos_integridad_guard BEFORE UPDATE OR DELETE ON "{$schema}"."pagos" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_pago_integridad"();
            CREATE TRIGGER aplicaciones_pago_integridad_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."aplicaciones_pago" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_aplicacion_pago_integridad"();
            CREATE CONSTRAINT TRIGGER aplicaciones_pago_balance_guard AFTER INSERT ON "{$schema}"."aplicaciones_pago" DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."validar_aplicacion_pago_balance"();
            CREATE CONSTRAINT TRIGGER cargos_pago_balance_guard AFTER INSERT OR UPDATE OF saldo, estado ON "{$schema}"."cargos" DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."validar_cargo_pago_balance"();
            CREATE CONSTRAINT TRIGGER pagos_cargos_balance_guard AFTER UPDATE OF estado ON "{$schema}"."pagos" DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."validar_pago_cargos_balance"();
            SQL);
    }
};
