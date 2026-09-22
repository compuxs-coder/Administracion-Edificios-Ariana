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
        $proveedoresEdificio = $table('proveedor_edificio');
        $cuentas = $table('cuentas_por_pagar');
        $desembolsos = $table('desembolsos');
        $aplicaciones = $table('aplicaciones_desembolso');

        Schema::table($cuentas, function (Blueprint $table): void {
            $table->unique(['edificio_id', 'id', 'proveedor_id'], 'cuentas_por_pagar_scope_uq');
        });
        Schema::create($table('consecutivos_desembolso'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('anio')->primary();
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestampsTz();
        });
        Schema::create($desembolsos, function (Blueprint $table) use ($edificios, $proveedoresEdificio, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('proveedor_id');
            $table->string('numero', 30)->unique('desembolsos_numero_uq');
            $table->date('fecha_desembolso');
            $table->decimal('monto', 14, 4);
            $table->enum('forma_pago', ['efectivo', 'transferencia', 'deposito', 'tarjeta', 'cheque', 'otro']);
            $table->string('referencia', 120)->nullable();
            $table->text('observacion')->nullable();
            $table->string('proveedor_nombre_snapshot', 255);
            $table->string('proveedor_identificacion_snapshot', 30);
            $table->enum('estado', ['preparando', 'registrado', 'anulado']);
            $table->uuid('registrado_por')->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestampTz('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id', 'proveedor_id'], 'desembolsos_scope_uq');
            $table->index(['edificio_id', 'estado', 'fecha_desembolso'], 'desembolsos_estado_fecha_idx');
            $table->index(['edificio_id', 'proveedor_id', 'fecha_desembolso'], 'desembolsos_proveedor_fecha_idx');
            $table->foreign('edificio_id', 'desembolsos_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'proveedor_id'], 'desembolsos_proveedor_fk')
                ->references(['edificio_id', 'proveedor_id'])->on($proveedoresEdificio)->restrictOnDelete();
            $table->foreign('registrado_por', 'desembolsos_registrado_por_fk')->references('id')->on($users)->nullOnDelete();
            $table->foreign('anulado_por', 'desembolsos_anulado_por_fk')->references('id')->on($users)->nullOnDelete();
        });
        Schema::create($aplicaciones, function (Blueprint $table) use ($desembolsos, $cuentas): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('proveedor_id');
            $table->uuid('desembolso_id');
            $table->uuid('cuenta_por_pagar_id');
            $table->decimal('monto_aplicado', 14, 4);
            $table->timestampsTz();

            $table->unique(['desembolso_id', 'cuenta_por_pagar_id'], 'aplicaciones_desembolso_cuenta_uq');
            $table->index('desembolso_id', 'aplicaciones_desembolso_pago_idx');
            $table->index('cuenta_por_pagar_id', 'aplicaciones_desembolso_cuenta_idx');
            $table->foreign(['edificio_id', 'desembolso_id', 'proveedor_id'], 'aplicaciones_desembolso_pago_fk')
                ->references(['edificio_id', 'id', 'proveedor_id'])->on($desembolsos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'cuenta_por_pagar_id', 'proveedor_id'], 'aplicaciones_desembolso_cuenta_fk')
                ->references(['edificio_id', 'id', 'proveedor_id'])->on($cuentas)->restrictOnDelete();
        });

        $this->replaceGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        if (Schema::hasTable($table('desembolsos')) && DB::table($table('desembolsos'))->exists()) {
            throw new LogicException('No se puede revertir ETAPA 14 porque existen desembolsos que deben conservarse.');
        }
        $this->dropNewGuards($pgsql, $schema);
        Schema::dropIfExists($table('aplicaciones_desembolso'));
        Schema::dropIfExists($table('desembolsos'));
        Schema::dropIfExists($table('consecutivos_desembolso'));
        Schema::table($table('cuentas_por_pagar'), function (Blueprint $table): void {
            $table->dropUnique('cuentas_por_pagar_scope_uq');
        });
        $this->restoreStage13Guards($pgsql, $schema);
    }

    private function replaceGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            $this->replaceSqliteGuards();

            return;
        }
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS cuentas_gasto_correspondence_guard ON "{$schema}"."cuentas_por_pagar";
            DROP TRIGGER IF EXISTS gastos_cuenta_correspondence_guard ON "{$schema}"."gastos";
            DROP TRIGGER IF EXISTS cuentas_history_guard ON "{$schema}"."cuentas_por_pagar";
            DROP TRIGGER IF EXISTS gastos_history_guard ON "{$schema}"."gastos";

            ALTER TABLE "{$schema}"."desembolsos" ADD CONSTRAINT desembolsos_monto_check CHECK (monto > 0);
            ALTER TABLE "{$schema}"."aplicaciones_desembolso" ADD CONSTRAINT aplicaciones_desembolso_monto_check CHECK (monto_aplicado > 0);

            CREATE FUNCTION "{$schema}"."guard_gasto_desembolso"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'borrador' OR NEW.numero IS NOT NULL OR NEW.estado_pago IS NOT NULL
                       OR NEW.proveedor_snapshot IS NOT NULL OR NEW.contrato_snapshot IS NOT NULL
                       OR NEW.registrado_at IS NOT NULL OR NEW.registrado_por IS NOT NULL
                       OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL THEN
                        RAISE EXCEPTION 'Un gasto debe crearse como borrador.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los gastos no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'borrador' THEN
                    IF NEW.estado = 'borrador' THEN
                        IF NEW.numero IS NOT NULL OR NEW.estado_pago IS NOT NULL OR NEW.pagado_at IS NOT NULL
                           OR NEW.proveedor_snapshot IS NOT NULL OR NEW.contrato_snapshot IS NOT NULL
                           OR NEW.registrado_at IS NOT NULL OR NEW.registrado_por IS NOT NULL
                           OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL THEN
                            RAISE EXCEPTION 'Un gasto borrador no puede tener efectos financieros ni trazabilidad.' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NEW.estado <> 'registrado' OR NEW.numero IS NULL OR NEW.proveedor_snapshot IS NULL
                       OR NEW.registrado_at IS NULL OR NEW.registrado_por IS NULL OR NEW.estado_pago IS NULL
                       OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
                       OR (NEW.contrato_id IS NOT NULL AND NEW.contrato_snapshot IS NULL) THEN
                        RAISE EXCEPTION 'La transición válida del gasto es borrador a registrado.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'registrado' THEN
                    IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id
                       OR NEW.contrato_id IS DISTINCT FROM OLD.contrato_id OR NEW.numero IS DISTINCT FROM OLD.numero
                       OR NEW.fecha_gasto IS DISTINCT FROM OLD.fecha_gasto OR NEW.fecha_vencimiento IS DISTINCT FROM OLD.fecha_vencimiento
                       OR NEW.concepto IS DISTINCT FROM OLD.concepto OR NEW.referencia IS DISTINCT FROM OLD.referencia
                       OR NEW.monto IS DISTINCT FROM OLD.monto OR NEW.tipo_pago IS DISTINCT FROM OLD.tipo_pago
                       OR NEW.observaciones IS DISTINCT FROM OLD.observaciones
                       OR NEW.proveedor_snapshot::text IS DISTINCT FROM OLD.proveedor_snapshot::text
                       OR NEW.contrato_snapshot::text IS DISTINCT FROM OLD.contrato_snapshot::text
                       OR NEW.registrado_at IS DISTINCT FROM OLD.registrado_at OR NEW.registrado_por IS DISTINCT FROM OLD.registrado_por THEN
                        RAISE EXCEPTION 'Los datos del gasto registrado son inmutables.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.estado = 'registrado' THEN
                        IF NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL THEN
                            RAISE EXCEPTION 'Un gasto registrado no puede tener datos de anulación.' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NEW.estado = 'anulado' THEN
                        IF NEW.pagado_at IS DISTINCT FROM OLD.pagado_at OR NEW.estado_pago IS DISTINCT FROM 'anulado'
                           OR NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL OR COALESCE(BTRIM(NEW.motivo_anulacion), '') = '' THEN
                            RAISE EXCEPTION 'La anulación del gasto requiere trazabilidad completa.' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'El ciclo de vida del gasto es borrador, registrado y anulado.' USING ERRCODE = '23514';
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_cuenta_desembolso"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'pendiente' OR NEW.saldo <> NEW.monto_original OR NEW.anulado_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Una cuenta por pagar debe crearse pendiente por el monto completo.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las cuentas por pagar no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.gasto_id IS DISTINCT FROM OLD.gasto_id
                   OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id OR NEW.fecha_vencimiento IS DISTINCT FROM OLD.fecha_vencimiento
                   OR NEW.monto_original IS DISTINCT FROM OLD.monto_original THEN
                    RAISE EXCEPTION 'Los datos de origen de una cuenta por pagar son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'pendiente' AND NEW.estado = 'pendiente' THEN
                    IF NEW.anulado_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Una cuenta pendiente no puede tener fecha de anulación.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'pendiente' AND NEW.estado = 'anulada' THEN
                    IF NEW.saldo <> NEW.monto_original OR NEW.anulado_at IS NULL THEN
                        RAISE EXCEPTION 'Sólo puede anularse una cuenta sin desembolsos activos.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'La cuenta por pagar es inmutable salvo por saldo y anulación.' USING ERRCODE = '23514';
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_desembolso"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'preparando' OR NEW.numero IS NULL OR NEW.registrado_por IS NULL
                       OR COALESCE(BTRIM(NEW.proveedor_nombre_snapshot), '') = ''
                       OR COALESCE(BTRIM(NEW.proveedor_identificacion_snapshot), '') = ''
                       OR NEW.anulado_por IS NOT NULL OR NEW.anulado_at IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL THEN
                        RAISE EXCEPTION 'El desembolso debe prepararse con trazabilidad completa.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los desembolsos no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id
                   OR NEW.numero IS DISTINCT FROM OLD.numero OR NEW.fecha_desembolso IS DISTINCT FROM OLD.fecha_desembolso
                   OR NEW.monto IS DISTINCT FROM OLD.monto OR NEW.forma_pago IS DISTINCT FROM OLD.forma_pago
                   OR NEW.referencia IS DISTINCT FROM OLD.referencia OR NEW.observacion IS DISTINCT FROM OLD.observacion
                   OR NEW.proveedor_nombre_snapshot IS DISTINCT FROM OLD.proveedor_nombre_snapshot
                   OR NEW.proveedor_identificacion_snapshot IS DISTINCT FROM OLD.proveedor_identificacion_snapshot
                   OR NEW.registrado_por IS DISTINCT FROM OLD.registrado_por THEN
                    RAISE EXCEPTION 'Los datos financieros de un desembolso son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'preparando' AND NEW.estado = 'registrado' THEN
                    IF NEW.anulado_por IS NOT NULL OR NEW.anulado_at IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL THEN
                        RAISE EXCEPTION 'El registro del desembolso no admite datos de anulación.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'registrado' AND NEW.estado = 'anulado' THEN
                    IF NEW.anulado_por IS NULL OR NEW.anulado_at IS NULL OR COALESCE(BTRIM(NEW.motivo_anulacion), '') = '' THEN
                        RAISE EXCEPTION 'La anulación del desembolso requiere usuario, fecha y motivo.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'El desembolso sólo puede registrarse y anularse una vez.' USING ERRCODE = '23514';
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_aplicacion_desembolso"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE payment record; account record;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Las aplicaciones de desembolso son inmutables.' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO payment FROM "{$schema}"."desembolsos" WHERE id = NEW.desembolso_id FOR KEY SHARE;
                SELECT * INTO account FROM "{$schema}"."cuentas_por_pagar" WHERE id = NEW.cuenta_por_pagar_id FOR KEY SHARE;
                IF payment.estado <> 'preparando' OR account.estado <> 'pendiente'
                   OR NEW.monto_aplicado > account.saldo THEN
                    RAISE EXCEPTION 'La aplicación requiere un desembolso registrado y saldo suficiente.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."validate_gasto_cuenta_desembolso"(gasto_uuid uuid)
            RETURNS void LANGUAGE plpgsql AS \$\$
            DECLARE expense record; account record; account_count integer; active_applied numeric(14,4); expected numeric(14,4);
            BEGIN
                SELECT * INTO expense FROM "{$schema}"."gastos" WHERE id = gasto_uuid;
                IF NOT FOUND THEN RETURN; END IF;
                SELECT COUNT(*) INTO account_count FROM "{$schema}"."cuentas_por_pagar" WHERE gasto_id = gasto_uuid;
                IF account_count > 0 THEN SELECT * INTO account FROM "{$schema}"."cuentas_por_pagar" WHERE gasto_id = gasto_uuid; END IF;
                IF expense.estado = 'borrador' AND account_count <> 0 THEN
                    RAISE EXCEPTION 'Un gasto borrador no puede tener cuenta por pagar.' USING ERRCODE = '23514';
                END IF;
                IF expense.estado = 'registrado' AND expense.tipo_pago = 'contado' THEN
                    IF account_count <> 0 OR expense.estado_pago IS DISTINCT FROM 'pagado' OR expense.pagado_at IS NULL THEN
                        RAISE EXCEPTION 'Un gasto de contado debe quedar pagado y no genera cuenta por pagar.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF expense.tipo_pago = 'credito' AND account_count = 1 THEN
                    SELECT COALESCE(SUM(a.monto_aplicado), 0) INTO active_applied
                    FROM "{$schema}"."aplicaciones_desembolso" a
                    INNER JOIN "{$schema}"."desembolsos" d ON d.id = a.desembolso_id
                    WHERE a.cuenta_por_pagar_id = account.id AND d.estado = 'registrado';
                    expected := account.monto_original - active_applied;
                END IF;
                IF expense.estado = 'registrado' AND expense.tipo_pago = 'credito' THEN
                    IF account_count <> 1 OR account.estado <> 'pendiente'
                       OR account.edificio_id <> expense.edificio_id OR account.proveedor_id <> expense.proveedor_id
                       OR account.fecha_vencimiento <> expense.fecha_vencimiento OR account.monto_original <> expense.monto
                       OR expected < 0 OR account.saldo <> expected
                       OR (account.saldo = 0 AND (expense.estado_pago IS DISTINCT FROM 'pagado' OR expense.pagado_at IS NULL))
                       OR (account.saldo > 0 AND (expense.estado_pago IS DISTINCT FROM 'pendiente' OR expense.pagado_at IS NOT NULL)) THEN
                        RAISE EXCEPTION 'El gasto a crédito y su cuenta deben corresponder a los desembolsos activos.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF expense.estado = 'anulado' THEN
                    IF expense.estado_pago IS DISTINCT FROM 'anulado'
                       OR (expense.tipo_pago = 'contado' AND account_count <> 0)
                       OR (expense.tipo_pago = 'credito' AND (account_count <> 1 OR account.estado <> 'anulada'
                           OR account.saldo <> account.monto_original OR active_applied <> 0)) THEN
                        RAISE EXCEPTION 'El gasto anulado no puede conservar desembolsos activos.' USING ERRCODE = '23514';
                    END IF;
                END IF;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."trigger_validate_gasto_cuenta_desembolso"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE target_id uuid;
            BEGIN
                IF TG_TABLE_NAME = 'gastos' THEN
                    target_id := COALESCE(NEW.id, OLD.id);
                ELSE
                    target_id := COALESCE(NEW.gasto_id, OLD.gasto_id);
                END IF;
                PERFORM "{$schema}"."validate_gasto_cuenta_desembolso"(target_id);
                RETURN NULL;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."validate_desembolso_aplicaciones"(desembolso_uuid uuid)
            RETURNS void LANGUAGE plpgsql AS \$\$
            DECLARE payment record; applied numeric(14,4);
            BEGIN
                SELECT * INTO payment FROM "{$schema}"."desembolsos" WHERE id = desembolso_uuid;
                IF NOT FOUND THEN RETURN; END IF;
                SELECT COALESCE(SUM(monto_aplicado), 0) INTO applied
                FROM "{$schema}"."aplicaciones_desembolso" WHERE desembolso_id = desembolso_uuid;
                IF payment.estado = 'preparando' THEN
                    RAISE EXCEPTION 'El desembolso debe finalizar su registro antes del commit.' USING ERRCODE = '23514';
                END IF;
                IF applied <> payment.monto THEN
                    RAISE EXCEPTION 'El monto del desembolso debe aplicarse completamente.' USING ERRCODE = '23514';
                END IF;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."trigger_validate_aplicacion_desembolso"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE gasto_uuid uuid;
            BEGIN
                PERFORM "{$schema}"."validate_desembolso_aplicaciones"(NEW.desembolso_id);
                SELECT gasto_id INTO gasto_uuid FROM "{$schema}"."cuentas_por_pagar" WHERE id = NEW.cuenta_por_pagar_id;
                PERFORM "{$schema}"."validate_gasto_cuenta_desembolso"(gasto_uuid);
                RETURN NULL;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."trigger_validate_desembolso"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE gasto_uuid uuid;
            BEGIN
                PERFORM "{$schema}"."validate_desembolso_aplicaciones"(NEW.id);
                FOR gasto_uuid IN
                    SELECT DISTINCT c.gasto_id FROM "{$schema}"."cuentas_por_pagar" c
                    INNER JOIN "{$schema}"."aplicaciones_desembolso" a ON a.cuenta_por_pagar_id = c.id
                    WHERE a.desembolso_id = NEW.id
                LOOP
                    PERFORM "{$schema}"."validate_gasto_cuenta_desembolso"(gasto_uuid);
                END LOOP;
                RETURN NULL;
            END;
            \$\$;

            CREATE TRIGGER gastos_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."gastos"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_gasto_desembolso"();
            CREATE TRIGGER cuentas_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."cuentas_por_pagar"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_cuenta_desembolso"();
            CREATE TRIGGER desembolsos_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."desembolsos"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_desembolso"();
            CREATE TRIGGER aplicaciones_desembolso_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."aplicaciones_desembolso"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_aplicacion_desembolso"();
            CREATE CONSTRAINT TRIGGER gastos_cuenta_correspondence_guard AFTER INSERT OR UPDATE OR DELETE ON "{$schema}"."gastos"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."trigger_validate_gasto_cuenta_desembolso"();
            CREATE CONSTRAINT TRIGGER cuentas_gasto_correspondence_guard AFTER INSERT OR UPDATE OR DELETE ON "{$schema}"."cuentas_por_pagar"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."trigger_validate_gasto_cuenta_desembolso"();
            CREATE CONSTRAINT TRIGGER aplicaciones_desembolso_balance_guard AFTER INSERT ON "{$schema}"."aplicaciones_desembolso"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."trigger_validate_aplicacion_desembolso"();
            CREATE CONSTRAINT TRIGGER desembolsos_balance_guard AFTER INSERT OR UPDATE OF estado ON "{$schema}"."desembolsos"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."trigger_validate_desembolso"();
            SQL);
    }

    private function replaceSqliteGuards(): void
    {
        foreach ([
            'gastos_transition_guard', 'gastos_registered_immutable_guard',
            'cuentas_insert_guard', 'cuentas_delete_guard', 'cuentas_update_guard',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER gastos_transition_guard BEFORE UPDATE ON gastos
            WHEN (OLD.estado = 'borrador' AND NEW.estado NOT IN ('borrador', 'registrado'))
              OR (OLD.estado = 'registrado' AND NEW.estado NOT IN ('registrado', 'anulado')) OR OLD.estado = 'anulado'
            BEGIN SELECT RAISE(ABORT, 'Ciclo de vida de gasto inválido.'); END;
            CREATE TRIGGER gastos_registered_immutable_guard BEFORE UPDATE ON gastos
            WHEN OLD.estado = 'registrado' AND (
                NEW.edificio_id IS NOT OLD.edificio_id OR NEW.proveedor_id IS NOT OLD.proveedor_id
                OR NEW.contrato_id IS NOT OLD.contrato_id OR NEW.numero IS NOT OLD.numero
                OR NEW.fecha_gasto IS NOT OLD.fecha_gasto OR NEW.fecha_vencimiento IS NOT OLD.fecha_vencimiento
                OR NEW.concepto IS NOT OLD.concepto OR NEW.referencia IS NOT OLD.referencia
                OR NEW.monto IS NOT OLD.monto OR NEW.tipo_pago IS NOT OLD.tipo_pago
                OR NEW.observaciones IS NOT OLD.observaciones OR NEW.proveedor_snapshot IS NOT OLD.proveedor_snapshot
                OR NEW.contrato_snapshot IS NOT OLD.contrato_snapshot OR NEW.registrado_at IS NOT OLD.registrado_at
                OR NEW.registrado_por IS NOT OLD.registrado_por
                OR (NEW.estado = 'registrado' AND (NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL))
                OR (NEW.estado = 'anulado' AND (NEW.pagado_at IS NOT OLD.pagado_at OR NEW.estado_pago IS NOT 'anulado'
                    OR NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL OR COALESCE(TRIM(NEW.motivo_anulacion), '') = ''))
            )
            BEGIN SELECT RAISE(ABORT, 'Los datos del gasto registrado son inmutables.'); END;
            CREATE TRIGGER gastos_payment_state_guard BEFORE UPDATE ON gastos
            WHEN OLD.estado = 'registrado' AND NEW.estado = 'registrado' AND (
                (NEW.tipo_pago = 'contado' AND (NEW.estado_pago IS NOT 'pagado' OR NEW.pagado_at IS NULL))
                OR (NEW.tipo_pago = 'credito' AND (
                    ((SELECT saldo FROM cuentas_por_pagar WHERE gasto_id = NEW.id) = 0
                      AND (NEW.estado_pago IS NOT 'pagado' OR NEW.pagado_at IS NULL))
                    OR ((SELECT saldo FROM cuentas_por_pagar WHERE gasto_id = NEW.id) > 0
                      AND (NEW.estado_pago IS NOT 'pendiente' OR NEW.pagado_at IS NOT NULL))
                ))
            )
            BEGIN SELECT RAISE(ABORT, 'El estado de pago del gasto no corresponde al saldo de su cuenta.'); END;

            CREATE TRIGGER cuentas_insert_guard BEFORE INSERT ON cuentas_por_pagar
            WHEN NEW.estado <> 'pendiente' OR NEW.monto_original <= 0
              OR NEW.saldo <> NEW.monto_original OR NEW.anulado_at IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'La cuenta por pagar debe crearse pendiente.'); END;
            CREATE TRIGGER cuentas_delete_guard BEFORE DELETE ON cuentas_por_pagar
            BEGIN SELECT RAISE(ABORT, 'Las cuentas por pagar no pueden eliminarse.'); END;
            CREATE TRIGGER cuentas_update_guard BEFORE UPDATE ON cuentas_por_pagar
            WHEN NEW.edificio_id IS NOT OLD.edificio_id OR NEW.gasto_id IS NOT OLD.gasto_id
              OR NEW.proveedor_id IS NOT OLD.proveedor_id OR NEW.fecha_vencimiento IS NOT OLD.fecha_vencimiento
              OR NEW.monto_original IS NOT OLD.monto_original OR OLD.estado = 'anulada'
              OR NEW.saldo < 0 OR NEW.saldo > NEW.monto_original
              OR (OLD.estado = 'pendiente' AND NEW.estado = 'pendiente' AND NEW.anulado_at IS NOT NULL)
              OR (OLD.estado = 'pendiente' AND NEW.estado = 'anulada' AND (NEW.saldo <> NEW.monto_original OR NEW.anulado_at IS NULL))
              OR NEW.estado NOT IN ('pendiente', 'anulada')
            BEGIN SELECT RAISE(ABORT, 'La cuenta por pagar sólo permite saldo y anulación válida.'); END;

            CREATE TRIGGER desembolsos_insert_guard BEFORE INSERT ON desembolsos
            WHEN NEW.estado <> 'preparando' OR NEW.numero IS NULL OR NEW.registrado_por IS NULL OR NEW.monto <= 0
              OR COALESCE(TRIM(NEW.proveedor_nombre_snapshot), '') = '' OR COALESCE(TRIM(NEW.proveedor_identificacion_snapshot), '') = ''
              OR NEW.anulado_por IS NOT NULL OR NEW.anulado_at IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'El desembolso debe crearse en preparación.'); END;
            CREATE TRIGGER desembolsos_delete_guard BEFORE DELETE ON desembolsos
            BEGIN SELECT RAISE(ABORT, 'Los desembolsos no pueden eliminarse.'); END;
            CREATE TRIGGER desembolsos_update_guard BEFORE UPDATE ON desembolsos
            WHEN NEW.edificio_id IS NOT OLD.edificio_id OR NEW.proveedor_id IS NOT OLD.proveedor_id
              OR NEW.numero IS NOT OLD.numero OR NEW.fecha_desembolso IS NOT OLD.fecha_desembolso OR NEW.monto IS NOT OLD.monto
              OR NEW.forma_pago IS NOT OLD.forma_pago OR NEW.referencia IS NOT OLD.referencia OR NEW.observacion IS NOT OLD.observacion
              OR NEW.proveedor_nombre_snapshot IS NOT OLD.proveedor_nombre_snapshot
              OR NEW.proveedor_identificacion_snapshot IS NOT OLD.proveedor_identificacion_snapshot
              OR NEW.registrado_por IS NOT OLD.registrado_por
              OR NOT (
                (OLD.estado = 'preparando' AND NEW.estado = 'registrado'
                  AND NEW.anulado_por IS NULL AND NEW.anulado_at IS NULL AND NEW.motivo_anulacion IS NULL
                  AND ROUND((SELECT COALESCE(SUM(monto_aplicado), 0) FROM aplicaciones_desembolso WHERE desembolso_id = NEW.id) * 10000)
                      = ROUND(NEW.monto * 10000)
                  AND NOT EXISTS (
                    SELECT 1
                    FROM aplicaciones_desembolso current_application
                    INNER JOIN cuentas_por_pagar account ON account.id = current_application.cuenta_por_pagar_id
                    INNER JOIN gastos expense ON expense.id = account.gasto_id
                    WHERE current_application.desembolso_id = NEW.id AND (
                      ROUND(account.saldo * 10000) <> ROUND((account.monto_original - (
                        SELECT COALESCE(SUM(application.monto_aplicado), 0)
                        FROM aplicaciones_desembolso application
                        INNER JOIN desembolsos payment ON payment.id = application.desembolso_id
                        WHERE application.cuenta_por_pagar_id = account.id AND payment.estado IN ('preparando', 'registrado')
                      )) * 10000)
                      OR (account.saldo = 0 AND (expense.estado_pago IS NOT 'pagado' OR expense.pagado_at IS NULL))
                      OR (account.saldo > 0 AND (expense.estado_pago IS NOT 'pendiente' OR expense.pagado_at IS NOT NULL))
                    )
                  ))
                OR (OLD.estado = 'registrado' AND NEW.estado = 'anulado'
                  AND NEW.anulado_por IS NOT NULL AND NEW.anulado_at IS NOT NULL AND COALESCE(TRIM(NEW.motivo_anulacion), '') <> ''
                  AND NOT EXISTS (
                    SELECT 1
                    FROM aplicaciones_desembolso current_application
                    INNER JOIN cuentas_por_pagar account ON account.id = current_application.cuenta_por_pagar_id
                    INNER JOIN gastos expense ON expense.id = account.gasto_id
                    WHERE current_application.desembolso_id = NEW.id AND (
                      ROUND(account.saldo * 10000) <> ROUND((account.monto_original - (
                        SELECT COALESCE(SUM(application.monto_aplicado), 0)
                        FROM aplicaciones_desembolso application
                        INNER JOIN desembolsos payment ON payment.id = application.desembolso_id
                        WHERE application.cuenta_por_pagar_id = account.id
                          AND payment.estado = 'registrado' AND payment.id <> NEW.id
                      )) * 10000)
                      OR (account.saldo = 0 AND (expense.estado_pago IS NOT 'pagado' OR expense.pagado_at IS NULL))
                      OR (account.saldo > 0 AND (expense.estado_pago IS NOT 'pendiente' OR expense.pagado_at IS NOT NULL))
                    )
                  ))
              )
            BEGIN SELECT RAISE(ABORT, 'El desembolso sólo puede registrarse completo y anularse una vez.'); END;
            CREATE TRIGGER aplicaciones_desembolso_insert_guard BEFORE INSERT ON aplicaciones_desembolso
            WHEN NEW.monto_aplicado <= 0
              OR (SELECT estado FROM desembolsos WHERE id = NEW.desembolso_id) <> 'preparando'
              OR (SELECT estado FROM cuentas_por_pagar WHERE id = NEW.cuenta_por_pagar_id) <> 'pendiente'
              OR NEW.monto_aplicado > (SELECT saldo FROM cuentas_por_pagar WHERE id = NEW.cuenta_por_pagar_id)
              OR ROUND((NEW.monto_aplicado + (SELECT COALESCE(SUM(monto_aplicado), 0) FROM aplicaciones_desembolso WHERE desembolso_id = NEW.desembolso_id)) * 10000)
                 > ROUND((SELECT monto FROM desembolsos WHERE id = NEW.desembolso_id) * 10000)
            BEGIN SELECT RAISE(ABORT, 'La aplicación de desembolso no es válida.'); END;
            CREATE TRIGGER aplicaciones_desembolso_update_guard BEFORE UPDATE ON aplicaciones_desembolso
            BEGIN SELECT RAISE(ABORT, 'Las aplicaciones de desembolso son inmutables.'); END;
            CREATE TRIGGER aplicaciones_desembolso_delete_guard BEFORE DELETE ON aplicaciones_desembolso
            BEGIN SELECT RAISE(ABORT, 'Las aplicaciones de desembolso no pueden eliminarse.'); END;
            SQL);
    }

    private function dropNewGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            foreach ([
                'gastos_transition_guard', 'gastos_registered_immutable_guard',
                'gastos_payment_state_guard',
                'cuentas_insert_guard', 'cuentas_delete_guard', 'cuentas_update_guard',
                'desembolsos_insert_guard', 'desembolsos_delete_guard', 'desembolsos_update_guard',
                'aplicaciones_desembolso_insert_guard', 'aplicaciones_desembolso_update_guard', 'aplicaciones_desembolso_delete_guard',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS desembolsos_balance_guard ON "{$schema}"."desembolsos";
            DROP TRIGGER IF EXISTS aplicaciones_desembolso_balance_guard ON "{$schema}"."aplicaciones_desembolso";
            DROP TRIGGER IF EXISTS cuentas_gasto_correspondence_guard ON "{$schema}"."cuentas_por_pagar";
            DROP TRIGGER IF EXISTS gastos_cuenta_correspondence_guard ON "{$schema}"."gastos";
            DROP TRIGGER IF EXISTS aplicaciones_desembolso_history_guard ON "{$schema}"."aplicaciones_desembolso";
            DROP TRIGGER IF EXISTS desembolsos_history_guard ON "{$schema}"."desembolsos";
            DROP TRIGGER IF EXISTS cuentas_history_guard ON "{$schema}"."cuentas_por_pagar";
            DROP TRIGGER IF EXISTS gastos_history_guard ON "{$schema}"."gastos";
            DROP FUNCTION IF EXISTS "{$schema}"."trigger_validate_desembolso"();
            DROP FUNCTION IF EXISTS "{$schema}"."trigger_validate_aplicacion_desembolso"();
            DROP FUNCTION IF EXISTS "{$schema}"."validate_desembolso_aplicaciones"(uuid);
            DROP FUNCTION IF EXISTS "{$schema}"."trigger_validate_gasto_cuenta_desembolso"();
            DROP FUNCTION IF EXISTS "{$schema}"."validate_gasto_cuenta_desembolso"(uuid);
            DROP FUNCTION IF EXISTS "{$schema}"."guard_aplicacion_desembolso"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_desembolso"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_cuenta_desembolso"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_gasto_desembolso"();
            SQL);
    }

    private function restoreStage13Guards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER gastos_transition_guard BEFORE UPDATE ON gastos
                WHEN (OLD.estado = 'borrador' AND NEW.estado NOT IN ('borrador', 'registrado'))
                  OR (OLD.estado = 'registrado' AND NEW.estado <> 'anulado') OR OLD.estado = 'anulado'
                BEGIN SELECT RAISE(ABORT, 'Ciclo de vida de gasto inválido.'); END;
                CREATE TRIGGER gastos_registered_immutable_guard BEFORE UPDATE ON gastos
                WHEN OLD.estado = 'registrado' AND (
                    NEW.edificio_id IS NOT OLD.edificio_id OR NEW.proveedor_id IS NOT OLD.proveedor_id
                    OR NEW.contrato_id IS NOT OLD.contrato_id OR NEW.numero IS NOT OLD.numero
                    OR NEW.fecha_gasto IS NOT OLD.fecha_gasto OR NEW.fecha_vencimiento IS NOT OLD.fecha_vencimiento
                    OR NEW.concepto IS NOT OLD.concepto OR NEW.referencia IS NOT OLD.referencia
                    OR NEW.monto IS NOT OLD.monto OR NEW.tipo_pago IS NOT OLD.tipo_pago
                    OR NEW.pagado_at IS NOT OLD.pagado_at OR NEW.observaciones IS NOT OLD.observaciones
                    OR NEW.proveedor_snapshot IS NOT OLD.proveedor_snapshot OR NEW.contrato_snapshot IS NOT OLD.contrato_snapshot
                    OR NEW.registrado_at IS NOT OLD.registrado_at OR NEW.registrado_por IS NOT OLD.registrado_por
                    OR NEW.estado_pago <> 'anulado' OR NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL
                    OR COALESCE(TRIM(NEW.motivo_anulacion), '') = '')
                BEGIN SELECT RAISE(ABORT, 'Los datos del gasto registrado son inmutables.'); END;
                CREATE TRIGGER cuentas_insert_guard BEFORE INSERT ON cuentas_por_pagar
                WHEN NEW.estado <> 'pendiente' OR NEW.saldo <> NEW.monto_original OR NEW.anulado_at IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'La cuenta por pagar debe crearse pendiente.'); END;
                CREATE TRIGGER cuentas_delete_guard BEFORE DELETE ON cuentas_por_pagar
                BEGIN SELECT RAISE(ABORT, 'Las cuentas por pagar no pueden eliminarse.'); END;
                CREATE TRIGGER cuentas_update_guard BEFORE UPDATE ON cuentas_por_pagar
                WHEN OLD.estado <> 'pendiente' OR NEW.estado <> 'anulada'
                  OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.gasto_id IS NOT OLD.gasto_id
                  OR NEW.proveedor_id IS NOT OLD.proveedor_id OR NEW.fecha_vencimiento IS NOT OLD.fecha_vencimiento
                  OR NEW.monto_original IS NOT OLD.monto_original OR NEW.saldo IS NOT OLD.saldo OR NEW.anulado_at IS NULL
                BEGIN SELECT RAISE(ABORT, 'La cuenta por pagar es inmutable salvo por su anulación.'); END;
                SQL);

            return;
        }
        DB::unprepared(<<<SQL
            CREATE TRIGGER gastos_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."gastos"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_gasto"();
            CREATE TRIGGER cuentas_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."cuentas_por_pagar"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_cuenta_por_pagar"();
            CREATE CONSTRAINT TRIGGER gastos_cuenta_correspondence_guard AFTER INSERT OR UPDATE OR DELETE ON "{$schema}"."gastos"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."validate_gasto_cuenta_correspondence"();
            CREATE CONSTRAINT TRIGGER cuentas_gasto_correspondence_guard AFTER INSERT OR UPDATE OR DELETE ON "{$schema}"."cuentas_por_pagar"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."validate_gasto_cuenta_correspondence"();
            SQL);
    }
};
