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
        $terceros = $table('terceros');
        $proveedores = $table('proveedores');
        $asociaciones = $table('proveedor_edificio');
        $contratos = $table('contratos_proveedor');
        $gastos = $table('gastos');
        $cuentas = $table('cuentas_por_pagar');
        $consecutivos = $table('consecutivos_gasto');

        Schema::create($proveedores, function (Blueprint $table) use ($terceros): void {
            $table->uuid('id')->primary();
            $table->uuid('tercero_id')->unique('proveedores_tercero_uq');
            $table->timestampsTz();
            $table->foreign('tercero_id', 'proveedores_tercero_fk')
                ->references('id')->on($terceros)->restrictOnDelete();
        });

        Schema::create($asociaciones, function (Blueprint $table) use ($edificios, $proveedores): void {
            $table->uuid('edificio_id');
            $table->uuid('proveedor_id');
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->string('nombre_comercial', 180)->nullable();
            $table->string('contacto', 180)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->string('direccion')->nullable();
            $table->unsignedSmallInteger('dias_credito')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestampsTz();
            $table->primary(['edificio_id', 'proveedor_id'], 'proveedor_edificio_pk');
            $table->index(['edificio_id', 'estado'], 'proveedor_edificio_estado_idx');
            $table->foreign('edificio_id', 'proveedor_edificio_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign('proveedor_id', 'proveedor_edificio_proveedor_fk')
                ->references('id')->on($proveedores)->restrictOnDelete();
        });

        Schema::create($contratos, function (Blueprint $table) use ($asociaciones, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('proveedor_id');
            $table->string('referencia', 80);
            $table->string('objeto', 255);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->decimal('monto_total', 14, 4)->nullable();
            $table->text('observaciones')->nullable();
            $table->enum('estado', ['borrador', 'registrado', 'anulado'])->default('borrador');
            $table->json('proveedor_snapshot')->nullable();
            $table->uuid('registrado_por')->nullable();
            $table->timestampTz('registrado_at')->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestampTz('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampsTz();
            $table->unique(['edificio_id', 'referencia'], 'contratos_edificio_referencia_uq');
            $table->unique(['edificio_id', 'id', 'proveedor_id'], 'contratos_scope_uq');
            $table->index(['edificio_id', 'estado', 'fecha_inicio'], 'contratos_estado_fecha_idx');
            $table->foreign(['edificio_id', 'proveedor_id'], 'contratos_proveedor_edificio_fk')
                ->references(['edificio_id', 'proveedor_id'])->on($asociaciones)->restrictOnDelete();
            $table->foreign('registrado_por', 'contratos_registrado_por_fk')
                ->references('id')->on($users)->nullOnDelete();
            $table->foreign('anulado_por', 'contratos_anulado_por_fk')
                ->references('id')->on($users)->nullOnDelete();
        });

        Schema::create($consecutivos, function (Blueprint $table): void {
            $table->unsignedSmallInteger('anio')->primary();
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestampsTz();
        });

        Schema::create($gastos, function (Blueprint $table) use ($asociaciones, $contratos, $edificios, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('proveedor_id');
            $table->uuid('contrato_id')->nullable();
            $table->string('numero', 30)->nullable()->unique('gastos_numero_uq');
            $table->date('fecha_gasto');
            $table->date('fecha_vencimiento')->nullable();
            $table->string('concepto', 255);
            $table->string('referencia', 120)->nullable();
            $table->decimal('monto', 14, 4);
            $table->enum('tipo_pago', ['contado', 'credito']);
            $table->enum('estado_pago', ['pendiente', 'pagado', 'anulado'])->nullable();
            $table->timestampTz('pagado_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->enum('estado', ['borrador', 'registrado', 'anulado'])->default('borrador');
            $table->json('proveedor_snapshot')->nullable();
            $table->json('contrato_snapshot')->nullable();
            $table->uuid('registrado_por')->nullable();
            $table->timestampTz('registrado_at')->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestampTz('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampsTz();
            $table->unique(['edificio_id', 'id', 'proveedor_id'], 'gastos_scope_uq');
            $table->index(['edificio_id', 'estado', 'fecha_gasto'], 'gastos_estado_fecha_idx');
            $table->index(['edificio_id', 'estado_pago', 'fecha_vencimiento'], 'gastos_pago_idx');
            $table->foreign('edificio_id', 'gastos_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'proveedor_id'], 'gastos_proveedor_edificio_fk')
                ->references(['edificio_id', 'proveedor_id'])->on($asociaciones)->restrictOnDelete();
            $table->foreign(['edificio_id', 'contrato_id', 'proveedor_id'], 'gastos_contrato_fk')
                ->references(['edificio_id', 'id', 'proveedor_id'])->on($contratos)->restrictOnDelete();
            $table->foreign('registrado_por', 'gastos_registrado_por_fk')
                ->references('id')->on($users)->nullOnDelete();
            $table->foreign('anulado_por', 'gastos_anulado_por_fk')
                ->references('id')->on($users)->nullOnDelete();
        });

        Schema::create($cuentas, function (Blueprint $table) use ($gastos): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('gasto_id')->unique('cuentas_por_pagar_gasto_uq');
            $table->uuid('proveedor_id');
            $table->date('fecha_vencimiento');
            $table->decimal('monto_original', 14, 4);
            $table->decimal('saldo', 14, 4);
            $table->enum('estado', ['pendiente', 'anulada'])->default('pendiente');
            $table->timestampTz('anulado_at')->nullable();
            $table->timestampsTz();
            $table->index(['edificio_id', 'estado', 'fecha_vencimiento'], 'cuentas_estado_vencimiento_idx');
            $table->foreign(['edificio_id', 'gasto_id', 'proveedor_id'], 'cuentas_gasto_fk')
                ->references(['edificio_id', 'id', 'proveedor_id'])->on($gastos)->restrictOnDelete();
        });

        $this->createGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        $this->dropGuards($pgsql, $schema);
        Schema::dropIfExists($table('cuentas_por_pagar'));
        Schema::dropIfExists($table('gastos'));
        Schema::dropIfExists($table('consecutivos_gasto'));
        Schema::dropIfExists($table('contratos_proveedor'));
        Schema::dropIfExists($table('proveedor_edificio'));
        Schema::dropIfExists($table('proveedores'));
    }

    private function createGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            $this->createSqliteGuards();

            return;
        }

        DB::statement("ALTER TABLE \"{$schema}\".\"contratos_proveedor\" ADD CONSTRAINT contratos_fechas_check CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio)");
        DB::statement("ALTER TABLE \"{$schema}\".\"contratos_proveedor\" ADD CONSTRAINT contratos_monto_check CHECK (monto_total IS NULL OR monto_total > 0)");
        DB::statement("ALTER TABLE \"{$schema}\".\"gastos\" ADD CONSTRAINT gastos_monto_check CHECK (monto > 0)");
        DB::statement("ALTER TABLE \"{$schema}\".\"gastos\" ADD CONSTRAINT gastos_credito_vencimiento_check CHECK (tipo_pago <> 'credito' OR fecha_vencimiento IS NOT NULL)");
        DB::statement("ALTER TABLE \"{$schema}\".\"cuentas_por_pagar\" ADD CONSTRAINT cuentas_montos_check CHECK (monto_original > 0 AND saldo >= 0 AND saldo <= monto_original)");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_proveedor_history"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los proveedores y sus asociaciones no pueden eliminarse; utilice el estado.' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'proveedores' AND NEW.tercero_id IS DISTINCT FROM OLD.tercero_id THEN
                    RAISE EXCEPTION 'La identidad global de un proveedor es inmutable.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER proveedores_history_guard BEFORE UPDATE OR DELETE ON "{$schema}"."proveedores"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_proveedor_history"();
            CREATE TRIGGER proveedor_edificio_delete_guard BEFORE DELETE ON "{$schema}"."proveedor_edificio"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_proveedor_history"();

            CREATE FUNCTION "{$schema}"."guard_contrato_proveedor"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'borrador' OR NEW.proveedor_snapshot IS NOT NULL OR NEW.registrado_at IS NOT NULL
                       OR NEW.registrado_por IS NOT NULL OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL
                       OR NEW.motivo_anulacion IS NOT NULL THEN
                        RAISE EXCEPTION 'Un contrato debe crearse como borrador.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los contratos no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'borrador' THEN
                    IF NEW.estado = 'borrador' THEN
                        IF NEW.proveedor_snapshot IS NOT NULL OR NEW.registrado_at IS NOT NULL OR NEW.registrado_por IS NOT NULL
                           OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL THEN
                            RAISE EXCEPTION 'Un contrato borrador no puede tener trazabilidad de registro o anulación.' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NEW.estado <> 'registrado' OR NEW.proveedor_snapshot IS NULL
                       OR NEW.registrado_at IS NULL OR NEW.registrado_por IS NULL
                       OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL THEN
                        RAISE EXCEPTION 'La transición válida del contrato es borrador a registrado.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'registrado' AND NEW.estado = 'anulado' THEN
                    IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id
                       OR NEW.referencia IS DISTINCT FROM OLD.referencia OR NEW.objeto IS DISTINCT FROM OLD.objeto
                       OR NEW.fecha_inicio IS DISTINCT FROM OLD.fecha_inicio OR NEW.fecha_fin IS DISTINCT FROM OLD.fecha_fin
                       OR NEW.monto_total IS DISTINCT FROM OLD.monto_total OR NEW.observaciones IS DISTINCT FROM OLD.observaciones
                       OR NEW.proveedor_snapshot::text IS DISTINCT FROM OLD.proveedor_snapshot::text
                       OR NEW.registrado_at IS DISTINCT FROM OLD.registrado_at OR NEW.registrado_por IS DISTINCT FROM OLD.registrado_por
                       OR NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL OR COALESCE(BTRIM(NEW.motivo_anulacion), '') = '' THEN
                        RAISE EXCEPTION 'Los datos del contrato registrado son inmutables.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'El ciclo de vida del contrato es borrador, registrado y anulado.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER contratos_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."contratos_proveedor"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_contrato_proveedor"();

            CREATE FUNCTION "{$schema}"."guard_gasto"()
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
                       OR NEW.registrado_at IS NULL OR NEW.registrado_por IS NULL
                       OR NEW.estado_pago IS NULL OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL
                       OR NEW.motivo_anulacion IS NOT NULL
                       OR (NEW.contrato_id IS NOT NULL AND NEW.contrato_snapshot IS NULL) THEN
                        RAISE EXCEPTION 'La transición válida del gasto es borrador a registrado.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'registrado' AND NEW.estado = 'anulado' THEN
                    IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id
                       OR NEW.contrato_id IS DISTINCT FROM OLD.contrato_id OR NEW.numero IS DISTINCT FROM OLD.numero
                       OR NEW.fecha_gasto IS DISTINCT FROM OLD.fecha_gasto OR NEW.fecha_vencimiento IS DISTINCT FROM OLD.fecha_vencimiento
                       OR NEW.concepto IS DISTINCT FROM OLD.concepto OR NEW.referencia IS DISTINCT FROM OLD.referencia
                       OR NEW.monto IS DISTINCT FROM OLD.monto OR NEW.tipo_pago IS DISTINCT FROM OLD.tipo_pago
                       OR NEW.pagado_at IS DISTINCT FROM OLD.pagado_at OR NEW.observaciones IS DISTINCT FROM OLD.observaciones
                       OR NEW.proveedor_snapshot::text IS DISTINCT FROM OLD.proveedor_snapshot::text
                       OR NEW.contrato_snapshot::text IS DISTINCT FROM OLD.contrato_snapshot::text
                       OR NEW.registrado_at IS DISTINCT FROM OLD.registrado_at OR NEW.registrado_por IS DISTINCT FROM OLD.registrado_por
                       OR NEW.estado_pago <> 'anulado' OR NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL
                       OR COALESCE(BTRIM(NEW.motivo_anulacion), '') = '' THEN
                        RAISE EXCEPTION 'Los datos del gasto registrado son inmutables.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'El ciclo de vida del gasto es borrador, registrado y anulado.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER gastos_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."gastos"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_gasto"();

            CREATE FUNCTION "{$schema}"."guard_cuenta_por_pagar"()
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
                IF OLD.estado <> 'pendiente' OR NEW.estado <> 'anulada'
                   OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.gasto_id IS DISTINCT FROM OLD.gasto_id
                   OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id OR NEW.fecha_vencimiento IS DISTINCT FROM OLD.fecha_vencimiento
                   OR NEW.monto_original IS DISTINCT FROM OLD.monto_original OR NEW.saldo IS DISTINCT FROM OLD.saldo
                   OR NEW.anulado_at IS NULL THEN
                    RAISE EXCEPTION 'La cuenta por pagar es inmutable salvo por su anulación.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER cuentas_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."cuentas_por_pagar"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_cuenta_por_pagar"();

            CREATE FUNCTION "{$schema}"."validate_gasto_cuenta_correspondence"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE target_id uuid; expense record; account record; account_count integer;
            BEGIN
                IF TG_TABLE_NAME = 'gastos' THEN
                    target_id := COALESCE(NEW.id, OLD.id);
                ELSE
                    target_id := COALESCE(NEW.gasto_id, OLD.gasto_id);
                END IF;
                SELECT * INTO expense FROM "{$schema}"."gastos" WHERE id = target_id;
                SELECT COUNT(*) INTO account_count
                FROM "{$schema}"."cuentas_por_pagar" WHERE gasto_id = target_id;
                IF account_count > 0 THEN
                    SELECT * INTO account FROM "{$schema}"."cuentas_por_pagar" WHERE gasto_id = target_id;
                END IF;
                IF expense.estado = 'borrador' AND account_count <> 0 THEN
                    RAISE EXCEPTION 'Un gasto borrador no puede tener cuenta por pagar.' USING ERRCODE = '23514';
                END IF;
                IF expense.estado = 'registrado' AND expense.tipo_pago = 'contado' THEN
                    IF account_count <> 0 OR expense.estado_pago <> 'pagado' OR expense.pagado_at IS NULL THEN
                        RAISE EXCEPTION 'Un gasto de contado debe quedar pagado y no genera cuenta por pagar.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF expense.estado = 'registrado' AND expense.tipo_pago = 'credito' THEN
                    IF account_count <> 1 OR account.estado <> 'pendiente' OR expense.estado_pago <> 'pendiente'
                       OR account.edificio_id <> expense.edificio_id OR account.proveedor_id <> expense.proveedor_id
                       OR account.fecha_vencimiento <> expense.fecha_vencimiento OR account.monto_original <> expense.monto
                       OR account.saldo <> expense.monto OR expense.pagado_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Un gasto a crédito registrado requiere exactamente una cuenta por pagar pendiente.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF expense.estado = 'anulado' THEN
                    IF expense.estado_pago <> 'anulado'
                       OR (expense.tipo_pago = 'contado' AND account_count <> 0)
                       OR (expense.tipo_pago = 'credito' AND (account_count <> 1 OR account.estado <> 'anulada')) THEN
                        RAISE EXCEPTION 'La cuenta por pagar debe corresponder al estado del gasto anulado.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NULL;
            END;
            \$\$;
            CREATE CONSTRAINT TRIGGER gastos_cuenta_correspondence_guard
            AFTER INSERT OR UPDATE OR DELETE ON "{$schema}"."gastos"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."validate_gasto_cuenta_correspondence"();
            CREATE CONSTRAINT TRIGGER cuentas_gasto_correspondence_guard
            AFTER INSERT OR UPDATE OR DELETE ON "{$schema}"."cuentas_por_pagar"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."validate_gasto_cuenta_correspondence"();
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER proveedores_delete_guard BEFORE DELETE ON proveedores
            BEGIN SELECT RAISE(ABORT, 'Los proveedores no pueden eliminarse.'); END;
            CREATE TRIGGER proveedores_identity_guard BEFORE UPDATE OF tercero_id ON proveedores
            WHEN NEW.tercero_id IS NOT OLD.tercero_id
            BEGIN SELECT RAISE(ABORT, 'La identidad global de un proveedor es inmutable.'); END;
            CREATE TRIGGER proveedor_edificio_delete_guard BEFORE DELETE ON proveedor_edificio
            BEGIN SELECT RAISE(ABORT, 'La asociación comercial no puede eliminarse.'); END;

            CREATE TRIGGER contratos_insert_guard BEFORE INSERT ON contratos_proveedor
            WHEN NEW.estado <> 'borrador' OR NEW.proveedor_snapshot IS NOT NULL OR NEW.registrado_at IS NOT NULL
              OR NEW.registrado_por IS NOT NULL OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL
              OR NEW.motivo_anulacion IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'Un contrato debe crearse como borrador.'); END;
            CREATE TRIGGER contratos_delete_guard BEFORE DELETE ON contratos_proveedor
            BEGIN SELECT RAISE(ABORT, 'Los contratos no pueden eliminarse.'); END;
            CREATE TRIGGER contratos_transition_guard BEFORE UPDATE ON contratos_proveedor
            WHEN (OLD.estado = 'borrador' AND NEW.estado NOT IN ('borrador', 'registrado'))
              OR (OLD.estado = 'registrado' AND NEW.estado <> 'anulado') OR OLD.estado = 'anulado'
            BEGIN SELECT RAISE(ABORT, 'Ciclo de vida de contrato inválido.'); END;
            CREATE TRIGGER contratos_draft_guard BEFORE UPDATE ON contratos_proveedor
            WHEN OLD.estado = 'borrador' AND NEW.estado = 'borrador' AND (
                NEW.proveedor_snapshot IS NOT NULL OR NEW.registrado_at IS NOT NULL OR NEW.registrado_por IS NOT NULL
                OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
            )
            BEGIN SELECT RAISE(ABORT, 'Un contrato borrador no puede tener trazabilidad.'); END;
            CREATE TRIGGER contratos_register_guard BEFORE UPDATE ON contratos_proveedor
            WHEN OLD.estado = 'borrador' AND NEW.estado = 'registrado' AND (
                NEW.proveedor_snapshot IS NULL OR NEW.registrado_at IS NULL OR NEW.registrado_por IS NULL
                OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
            )
            BEGIN SELECT RAISE(ABORT, 'El registro del contrato está incompleto.'); END;
            CREATE TRIGGER contratos_registered_immutable_guard BEFORE UPDATE ON contratos_proveedor
            WHEN OLD.estado = 'registrado' AND (
                NEW.edificio_id IS NOT OLD.edificio_id OR NEW.proveedor_id IS NOT OLD.proveedor_id
                OR NEW.referencia IS NOT OLD.referencia OR NEW.objeto IS NOT OLD.objeto
                OR NEW.fecha_inicio IS NOT OLD.fecha_inicio OR NEW.fecha_fin IS NOT OLD.fecha_fin
                OR NEW.monto_total IS NOT OLD.monto_total OR NEW.observaciones IS NOT OLD.observaciones
                OR NEW.proveedor_snapshot IS NOT OLD.proveedor_snapshot OR NEW.registrado_at IS NOT OLD.registrado_at
                OR NEW.registrado_por IS NOT OLD.registrado_por OR NEW.anulado_at IS NULL
                OR NEW.anulado_por IS NULL OR COALESCE(TRIM(NEW.motivo_anulacion), '') = '')
            BEGIN SELECT RAISE(ABORT, 'Los datos del contrato registrado son inmutables.'); END;

            CREATE TRIGGER gastos_insert_guard BEFORE INSERT ON gastos
            WHEN NEW.estado <> 'borrador' OR NEW.numero IS NOT NULL OR NEW.estado_pago IS NOT NULL
              OR NEW.pagado_at IS NOT NULL OR NEW.proveedor_snapshot IS NOT NULL OR NEW.contrato_snapshot IS NOT NULL
              OR NEW.registrado_at IS NOT NULL OR NEW.registrado_por IS NOT NULL
              OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'Un gasto debe crearse como borrador.'); END;
            CREATE TRIGGER gastos_delete_guard BEFORE DELETE ON gastos
            BEGIN SELECT RAISE(ABORT, 'Los gastos no pueden eliminarse.'); END;
            CREATE TRIGGER gastos_transition_guard BEFORE UPDATE ON gastos
            WHEN (OLD.estado = 'borrador' AND NEW.estado NOT IN ('borrador', 'registrado'))
              OR (OLD.estado = 'registrado' AND NEW.estado <> 'anulado') OR OLD.estado = 'anulado'
            BEGIN SELECT RAISE(ABORT, 'Ciclo de vida de gasto inválido.'); END;
            CREATE TRIGGER gastos_draft_guard BEFORE UPDATE ON gastos
            WHEN OLD.estado = 'borrador' AND NEW.estado = 'borrador' AND (
                NEW.numero IS NOT NULL OR NEW.estado_pago IS NOT NULL OR NEW.pagado_at IS NOT NULL
                OR NEW.proveedor_snapshot IS NOT NULL OR NEW.contrato_snapshot IS NOT NULL
                OR NEW.registrado_at IS NOT NULL OR NEW.registrado_por IS NOT NULL
                OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
            )
            BEGIN SELECT RAISE(ABORT, 'Un gasto borrador no puede tener efectos financieros ni trazabilidad.'); END;
            CREATE TRIGGER gastos_register_guard BEFORE UPDATE ON gastos
            WHEN OLD.estado = 'borrador' AND NEW.estado = 'registrado' AND (
                NEW.numero IS NULL OR NEW.estado_pago IS NULL OR NEW.proveedor_snapshot IS NULL
                OR NEW.registrado_at IS NULL OR NEW.registrado_por IS NULL
                OR NEW.anulado_at IS NOT NULL OR NEW.anulado_por IS NOT NULL OR NEW.motivo_anulacion IS NOT NULL
                OR (NEW.contrato_id IS NOT NULL AND NEW.contrato_snapshot IS NULL)
                OR (NEW.tipo_pago = 'contado' AND (NEW.estado_pago <> 'pagado' OR NEW.pagado_at IS NULL))
                OR (NEW.tipo_pago = 'credito' AND (NEW.estado_pago <> 'pendiente' OR NEW.pagado_at IS NOT NULL))
            )
            BEGIN SELECT RAISE(ABORT, 'El registro del gasto está incompleto.'); END;
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
    }

    private function dropGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            foreach ([
                'proveedores_delete_guard', 'proveedores_identity_guard', 'proveedor_edificio_delete_guard',
                'contratos_insert_guard', 'contratos_delete_guard', 'contratos_transition_guard',
                'contratos_draft_guard', 'contratos_register_guard', 'contratos_registered_immutable_guard',
                'gastos_insert_guard', 'gastos_delete_guard', 'gastos_transition_guard',
                'gastos_draft_guard', 'gastos_register_guard', 'gastos_registered_immutable_guard', 'cuentas_insert_guard',
                'cuentas_delete_guard', 'cuentas_update_guard',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS cuentas_gasto_correspondence_guard ON "{$schema}"."cuentas_por_pagar";
            DROP TRIGGER IF EXISTS gastos_cuenta_correspondence_guard ON "{$schema}"."gastos";
            DROP TRIGGER IF EXISTS cuentas_history_guard ON "{$schema}"."cuentas_por_pagar";
            DROP TRIGGER IF EXISTS gastos_history_guard ON "{$schema}"."gastos";
            DROP TRIGGER IF EXISTS contratos_history_guard ON "{$schema}"."contratos_proveedor";
            DROP TRIGGER IF EXISTS proveedor_edificio_delete_guard ON "{$schema}"."proveedor_edificio";
            DROP TRIGGER IF EXISTS proveedores_history_guard ON "{$schema}"."proveedores";
            DROP FUNCTION IF EXISTS "{$schema}"."validate_gasto_cuenta_correspondence"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_cuenta_por_pagar"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_gasto"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_contrato_proveedor"();
            DROP FUNCTION IF EXISTS "{$schema}"."guard_proveedor_history"();
            SQL);
    }
};
