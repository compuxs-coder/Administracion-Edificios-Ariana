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
        $pagos = $table('pagos');
        $consecutivos = $table('consecutivos_recibo');
        $recibos = $table('recibos_pago');
        $evidencias = $table('evidencias_pago');

        Schema::create($consecutivos, function (Blueprint $table) use ($edificios): void {
            $table->uuid('edificio_id');
            $table->unsignedSmallInteger('anio');
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestampsTz();

            $table->primary(['edificio_id', 'anio'], 'consecutivos_recibo_pk');
            $table->foreign('edificio_id', 'consecutivos_recibo_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
        });

        Schema::create($recibos, function (Blueprint $table) use ($edificios, $pagos, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('pago_id')->unique();
            $table->string('numero', 30);
            $table->date('fecha_pago_snapshot');
            $table->string('edificio_nombre_snapshot', 160);
            $table->string('departamento_codigo_snapshot', 50);
            $table->string('departamento_nombre_snapshot', 160);
            $table->json('titulares_snapshot')->nullable();
            $table->decimal('monto_recibido_snapshot', 14, 4);
            $table->string('forma_pago_snapshot', 30);
            $table->string('referencia_snapshot', 120)->nullable();
            $table->json('aplicaciones_snapshot')->nullable();
            $table->enum('estado', ['emitido', 'anulado'])->default('emitido');
            $table->uuid('emitido_por')->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestampTz('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'numero'], 'recibos_pago_edificio_numero_uq');
            $table->index(['edificio_id', 'departamento_id', 'fecha_pago_snapshot'], 'recibos_pago_departamento_fecha_idx');
            $table->foreign('edificio_id', 'recibos_pago_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'pago_id', 'departamento_id'], 'recibos_pago_pago_fk')->references(['edificio_id', 'id', 'departamento_id'])->on($pagos)->restrictOnDelete();
            $table->foreign('emitido_por', 'recibos_pago_emitido_por_fk')->references('id')->on($users)->nullOnDelete();
            $table->foreign('anulado_por', 'recibos_pago_anulado_por_fk')->references('id')->on($users)->nullOnDelete();
        });

        Schema::create($evidencias, function (Blueprint $table) use ($edificios, $pagos, $users): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('pago_id');
            $table->string('nombre_original', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('tamano_bytes');
            $table->string('ruta_privada', 500)->unique();
            $table->string('descripcion', 500)->nullable();
            $table->uuid('subido_por')->nullable();
            $table->timestampsTz();

            $table->index('pago_id', 'evidencias_pago_pago_idx');
            $table->foreign('edificio_id', 'evidencias_pago_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'pago_id', 'departamento_id'], 'evidencias_pago_pago_fk')->references(['edificio_id', 'id', 'departamento_id'])->on($pagos)->restrictOnDelete();
            $table->foreign('subido_por', 'evidencias_pago_subido_por_fk')->references('id')->on($users)->nullOnDelete();
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
            DB::unprepared("DROP TRIGGER IF EXISTS recibos_pago_integridad_guard ON \"{$schema}\".\"recibos_pago\"");
            DB::unprepared("DROP TRIGGER IF EXISTS evidencias_pago_integridad_guard ON \"{$schema}\".\"evidencias_pago\"");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_recibo_pago_integridad\"()");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_evidencia_pago_integridad\"()");
        }

        Schema::dropIfExists($table('evidencias_pago'));
        Schema::dropIfExists($table('recibos_pago'));
        Schema::dropIfExists($table('consecutivos_recibo'));
    }

    private function createIntegrityGuards(string $schema): void
    {
        $prefix = '"'.$schema.'".';
        DB::statement("ALTER TABLE {$prefix}\"evidencias_pago\" ADD CONSTRAINT evidencias_pago_tipo_check CHECK (mime_type IN ('application/pdf', 'image/jpeg', 'image/png'))");
        DB::statement("ALTER TABLE {$prefix}\"evidencias_pago\" ADD CONSTRAINT evidencias_pago_tamano_check CHECK (tamano_bytes > 0 AND tamano_bytes <= 10485760)");
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_recibo_pago_integridad"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los recibos no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id
                   OR NEW.pago_id IS DISTINCT FROM OLD.pago_id
                   OR NEW.numero IS DISTINCT FROM OLD.numero
                   OR NEW.fecha_pago_snapshot IS DISTINCT FROM OLD.fecha_pago_snapshot
                   OR NEW.edificio_nombre_snapshot IS DISTINCT FROM OLD.edificio_nombre_snapshot
                   OR NEW.departamento_codigo_snapshot IS DISTINCT FROM OLD.departamento_codigo_snapshot
                   OR NEW.departamento_nombre_snapshot IS DISTINCT FROM OLD.departamento_nombre_snapshot
                   OR NEW.titulares_snapshot IS DISTINCT FROM OLD.titulares_snapshot
                   OR NEW.monto_recibido_snapshot IS DISTINCT FROM OLD.monto_recibido_snapshot
                   OR NEW.forma_pago_snapshot IS DISTINCT FROM OLD.forma_pago_snapshot
                   OR NEW.referencia_snapshot IS DISTINCT FROM OLD.referencia_snapshot
                   OR NEW.aplicaciones_snapshot IS DISTINCT FROM OLD.aplicaciones_snapshot
                   OR NEW.emitido_por IS DISTINCT FROM OLD.emitido_por THEN
                    RAISE EXCEPTION 'Los datos de un recibo son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado <> 'emitido' OR NEW.estado <> 'anulado' THEN
                    RAISE EXCEPTION 'Un recibo sólo puede anularse una vez.' USING ERRCODE = '23514';
                END IF;
                IF NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL OR COALESCE(BTRIM(NEW.motivo_anulacion), '') = '' THEN
                    RAISE EXCEPTION 'La anulación requiere usuario, fecha y motivo.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_evidencia_pago_integridad"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Las evidencias de pago son inmutables.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER recibos_pago_integridad_guard BEFORE UPDATE OR DELETE ON "{$schema}"."recibos_pago" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_recibo_pago_integridad"();
            CREATE TRIGGER evidencias_pago_integridad_guard BEFORE UPDATE OR DELETE ON "{$schema}"."evidencias_pago" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_evidencia_pago_integridad"();
            SQL);
    }
};
