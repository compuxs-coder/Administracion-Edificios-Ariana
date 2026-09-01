<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $users = $pgsql ? 'public.users' : 'users';
        $recibos = $table('recibos_pago');
        $evidencias = $table('evidencias_pago');
        $consecutivos = $table('consecutivos_recibo');

        if ($pgsql) {
            $this->dropLegacyGuards($schema);
        }

        Schema::table($recibos, function (Blueprint $table): void {
            $table->dropUnique('recibos_pago_edificio_numero_uq');
        });
        Schema::drop($consecutivos);
        Schema::create($consecutivos, function (Blueprint $table): void {
            $table->unsignedSmallInteger('anio');
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestampsTz();
            $table->primary('anio', 'consecutivos_recibo_pk');
        });

        Schema::table($evidencias, function (Blueprint $table): void {
            $table->string('sha256', 64)->nullable()->after('tamano_bytes');
            $table->index('edificio_id', 'evidencias_pago_edificio_idx');
            $table->index('subido_por', 'evidencias_pago_subido_por_idx');
        });
        Schema::table($recibos, function (Blueprint $table): void {
            $table->index('emitido_por', 'recibos_pago_emitido_por_idx');
            $table->index('anulado_por', 'recibos_pago_anulado_por_idx');
        });

        $this->migrateEvidenceFiles($evidencias);
        Schema::table($evidencias, function (Blueprint $table): void {
            $table->string('sha256', 64)->nullable(false)->change();
        });

        if ($pgsql) {
            Schema::table($recibos, function (Blueprint $table) use ($users): void {
                $table->dropForeign('recibos_pago_emitido_por_fk');
                $table->dropForeign('recibos_pago_anulado_por_fk');
                $table->foreign('emitido_por', 'recibos_pago_emitido_por_fk')->references('id')->on($users)->restrictOnDelete();
                $table->foreign('anulado_por', 'recibos_pago_anulado_por_fk')->references('id')->on($users)->restrictOnDelete();
            });
            Schema::table($evidencias, function (Blueprint $table) use ($users): void {
                $table->dropForeign('evidencias_pago_subido_por_fk');
                $table->foreign('subido_por', 'evidencias_pago_subido_por_fk')->references('id')->on($users)->restrictOnDelete();
            });
        }

        $this->backfillAndRenumberReceipts($table);
        Schema::table($recibos, function (Blueprint $table): void {
            $table->unique('numero', 'recibos_pago_numero_uq');
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
        $users = $pgsql ? 'public.users' : 'users';
        $recibos = $table('recibos_pago');
        $evidencias = $table('evidencias_pago');
        $consecutivos = $table('consecutivos_recibo');

        if ($pgsql) {
            $this->dropIntegrityGuards($schema);
        }
        Schema::table($recibos, function (Blueprint $table): void {
            $table->dropUnique('recibos_pago_numero_uq');
            $table->unique(['edificio_id', 'numero'], 'recibos_pago_edificio_numero_uq');
            $table->dropIndex('recibos_pago_emitido_por_idx');
            $table->dropIndex('recibos_pago_anulado_por_idx');
        });
        Schema::drop($consecutivos);
        $edificios = $table('edificios');
        Schema::create($consecutivos, function (Blueprint $table) use ($edificios): void {
            $table->uuid('edificio_id');
            $table->unsignedSmallInteger('anio');
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestampsTz();
            $table->primary(['edificio_id', 'anio'], 'consecutivos_recibo_pk');
            $table->foreign('edificio_id', 'consecutivos_recibo_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
        });
        if ($pgsql) {
            Schema::table($recibos, function (Blueprint $table) use ($users): void {
                $table->dropForeign('recibos_pago_emitido_por_fk');
                $table->dropForeign('recibos_pago_anulado_por_fk');
                $table->foreign('emitido_por', 'recibos_pago_emitido_por_fk')->references('id')->on($users)->nullOnDelete();
                $table->foreign('anulado_por', 'recibos_pago_anulado_por_fk')->references('id')->on($users)->nullOnDelete();
            });
            Schema::table($evidencias, function (Blueprint $table) use ($users): void {
                $table->dropForeign('evidencias_pago_subido_por_fk');
                $table->foreign('subido_por', 'evidencias_pago_subido_por_fk')->references('id')->on($users)->nullOnDelete();
            });
        }
        Schema::table($evidencias, function (Blueprint $table): void {
            $table->dropIndex('evidencias_pago_edificio_idx');
            $table->dropIndex('evidencias_pago_subido_por_idx');
            $table->dropColumn('sha256');
        });

        if ($pgsql) {
            $this->createLegacyGuards($schema);
        }
    }

    private function migrateEvidenceFiles(string $evidencias): void
    {
        foreach (DB::table($evidencias)->orderBy('id')->get() as $evidencia) {
            if (! Storage::disk('local')->exists($evidencia->ruta_privada)) {
                throw new RuntimeException("No se encontró el archivo de evidencia {$evidencia->id}.");
            }
            $contents = Storage::disk('local')->get($evidencia->ruta_privada);
            $extension = match ($evidencia->mime_type) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                default => throw new RuntimeException("La evidencia {$evidencia->id} tiene un MIME no permitido."),
            };
            $newPath = $evidencia->edificio_id.'/'.$evidencia->pago_id.'/'.$evidencia->id.'.'.$extension;
            Storage::disk('evidence')->put($newPath, $contents);
            DB::table($evidencias)->where('id', $evidencia->id)->update([
                'nombre_original' => $this->safeFilename($evidencia->nombre_original, $extension),
                'tamano_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'ruta_privada' => $newPath,
            ]);
            Storage::disk('local')->delete($evidencia->ruta_privada);
        }
    }

    /** @param Closure(string): string $table */
    private function backfillAndRenumberReceipts(Closure $table): void
    {
        $pagos = $table('pagos');
        $recibos = $table('recibos_pago');
        $edificios = $table('edificios');
        $departamentos = $table('departamentos');
        $aplicaciones = $table('aplicaciones_pago');
        $cargos = $table('cargos');
        $conceptos = $table('conceptos_cobro');
        $consecutivos = $table('consecutivos_recibo');
        $sequences = [];
        $now = now();
        $payments = DB::table($pagos.' as p')
            ->join($edificios.' as e', 'e.id', '=', 'p.edificio_id')
            ->join($departamentos.' as d', 'd.id', '=', 'p.departamento_id')
            ->select('p.*', 'e.nombre as edificio_nombre', 'd.codigo as departamento_codigo', 'd.nombre as departamento_nombre')
            ->orderBy('p.fecha_pago')->orderBy('p.created_at')->orderBy('p.id')->get();

        foreach ($payments as $payment) {
            $year = (int) substr((string) $payment->fecha_pago, 0, 4);
            $sequence = ($sequences[$year] ?? 0) + 1;
            if ($sequence > 999999) {
                throw new RuntimeException("El consecutivo de recibos para {$year} excede seis dígitos.");
            }
            $sequences[$year] = $sequence;
            $number = sprintf('REC-%04d-%06d', $year, $sequence);
            $existing = DB::table($recibos)->where('pago_id', $payment->id)->first();
            if ($existing !== null) {
                DB::table($recibos)->where('id', $existing->id)->update(['numero' => $number, 'updated_at' => $now]);
                continue;
            }
            $metadata = is_string($payment->metadata) ? json_decode($payment->metadata, true) : (array) ($payment->metadata ?? []);
            $items = DB::table($aplicaciones.' as a')
                ->join($cargos.' as c', 'c.id', '=', 'a.cargo_id')
                ->leftJoin($conceptos.' as co', 'co.id', '=', 'c.concepto_cobro_id')
                ->where('a.pago_id', $payment->id)
                ->orderBy('a.created_at')->orderBy('a.id')
                ->get(['a.cargo_id', 'a.monto_aplicado', 'c.descripcion', 'c.periodo', 'c.fecha_vencimiento', 'co.nombre as concepto'])
                ->map(static fn (object $item): array => [
                    'cargoId' => $item->cargo_id,
                    'concepto' => $item->concepto ?? $item->descripcion,
                    'periodo' => substr((string) $item->periodo, 0, 7),
                    'fechaVencimiento' => substr((string) $item->fecha_vencimiento, 0, 10),
                    'saldoAnterior' => null,
                    'valorAplicado' => (string) $item->monto_aplicado,
                    'saldoPosterior' => null,
                ])->all();
            DB::table($recibos)->insert([
                'id' => (string) Str::uuid(),
                'edificio_id' => $payment->edificio_id,
                'departamento_id' => $payment->departamento_id,
                'pago_id' => $payment->id,
                'numero' => $number,
                'fecha_pago_snapshot' => $payment->fecha_pago,
                'edificio_nombre_snapshot' => $payment->edificio_nombre,
                'departamento_codigo_snapshot' => $payment->departamento_codigo,
                'departamento_nombre_snapshot' => $payment->departamento_nombre,
                'titulares_snapshot' => json_encode($metadata['propietarios'] ?? [], JSON_THROW_ON_ERROR),
                'monto_recibido_snapshot' => $payment->monto_recibido,
                'forma_pago_snapshot' => $payment->forma_pago,
                'referencia_snapshot' => $payment->referencia,
                'aplicaciones_snapshot' => json_encode($items, JSON_THROW_ON_ERROR),
                'estado' => $payment->estado === 'anulado' ? 'anulado' : 'emitido',
                'emitido_por' => $payment->registrado_por,
                'anulado_por' => $payment->anulado_por,
                'anulado_at' => $payment->anulado_at,
                'motivo_anulacion' => $payment->motivo_anulacion,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        }
        foreach ($sequences as $year => $sequence) {
            DB::table($consecutivos)->insert(['anio' => $year, 'ultimo_numero' => $sequence, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function createIntegrityGuards(string $schema): void
    {
        $prefix = '"'.$schema.'".';
        DB::statement("ALTER TABLE {$prefix}\"consecutivos_recibo\" ADD CONSTRAINT consecutivos_recibo_numero_check CHECK (ultimo_numero <= 999999)");
        DB::statement("ALTER TABLE {$prefix}\"recibos_pago\" ADD CONSTRAINT recibos_pago_numero_formato_check CHECK (numero ~ '^REC-[0-9]{4}-[0-9]{6}$' AND SUBSTRING(numero FROM 5 FOR 4)::integer = EXTRACT(YEAR FROM fecha_pago_snapshot)::integer)");
        DB::statement("ALTER TABLE {$prefix}\"recibos_pago\" ADD CONSTRAINT recibos_pago_ciclo_check CHECK ((estado = 'emitido' AND anulado_por IS NULL AND anulado_at IS NULL AND motivo_anulacion IS NULL) OR (estado = 'anulado' AND anulado_por IS NOT NULL AND anulado_at IS NOT NULL AND COALESCE(BTRIM(motivo_anulacion), '') <> ''))");
        DB::statement("ALTER TABLE {$prefix}\"evidencias_pago\" ADD CONSTRAINT evidencias_pago_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_recibo_pago_integridad"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE pago record;
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Los recibos no pueden eliminarse.' USING ERRCODE = '23514'; END IF;
                SELECT * INTO pago FROM "{$schema}"."pagos" WHERE id = NEW.pago_id FOR KEY SHARE;
                IF TG_OP = 'INSERT' THEN
                    IF pago.id IS NULL OR NEW.fecha_pago_snapshot <> pago.fecha_pago OR NEW.monto_recibido_snapshot <> pago.monto_recibido OR NEW.forma_pago_snapshot <> pago.forma_pago::text OR NEW.referencia_snapshot IS DISTINCT FROM pago.referencia OR NEW.emitido_por IS DISTINCT FROM pago.registrado_por THEN
                        RAISE EXCEPTION 'El recibo debe corresponder a los datos inmutables del pago.' USING ERRCODE = '23514';
                    END IF;
                    IF (pago.estado = 'registrado' AND NEW.estado <> 'emitido') OR (pago.estado = 'anulado' AND (NEW.estado <> 'anulado' OR NEW.anulado_por IS DISTINCT FROM pago.anulado_por OR NEW.anulado_at IS DISTINCT FROM pago.anulado_at OR NEW.motivo_anulacion IS DISTINCT FROM pago.motivo_anulacion)) THEN
                        RAISE EXCEPTION 'El estado del recibo debe corresponder al pago.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id OR NEW.pago_id IS DISTINCT FROM OLD.pago_id OR NEW.numero IS DISTINCT FROM OLD.numero OR NEW.fecha_pago_snapshot IS DISTINCT FROM OLD.fecha_pago_snapshot OR NEW.edificio_nombre_snapshot IS DISTINCT FROM OLD.edificio_nombre_snapshot OR NEW.departamento_codigo_snapshot IS DISTINCT FROM OLD.departamento_codigo_snapshot OR NEW.departamento_nombre_snapshot IS DISTINCT FROM OLD.departamento_nombre_snapshot OR NEW.titulares_snapshot IS DISTINCT FROM OLD.titulares_snapshot OR NEW.monto_recibido_snapshot IS DISTINCT FROM OLD.monto_recibido_snapshot OR NEW.forma_pago_snapshot IS DISTINCT FROM OLD.forma_pago_snapshot OR NEW.referencia_snapshot IS DISTINCT FROM OLD.referencia_snapshot OR NEW.aplicaciones_snapshot IS DISTINCT FROM OLD.aplicaciones_snapshot OR NEW.emitido_por IS DISTINCT FROM OLD.emitido_por OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Los datos de un recibo son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado <> 'emitido' OR NEW.estado <> 'anulado' OR pago.estado <> 'anulado' OR NEW.anulado_por IS DISTINCT FROM pago.anulado_por OR NEW.anulado_at IS DISTINCT FROM pago.anulado_at OR NEW.motivo_anulacion IS DISTINCT FROM pago.motivo_anulacion THEN
                    RAISE EXCEPTION 'La anulación del recibo debe corresponder a la del pago.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; \$\$;

            CREATE FUNCTION "{$schema}"."guard_evidencia_pago_integridad"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE estado_pago text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Las evidencias de pago son inmutables.' USING ERRCODE = '23514'; END IF;
                SELECT estado INTO estado_pago FROM "{$schema}"."pagos" WHERE id = NEW.pago_id FOR KEY SHARE;
                IF estado_pago <> 'registrado' OR NEW.subido_por IS NULL THEN RAISE EXCEPTION 'La evidencia requiere un pago registrado y un usuario.' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; \$\$;

            CREATE FUNCTION "{$schema}"."validar_pago_recibo"(pago_uuid uuid)
            RETURNS void LANGUAGE plpgsql AS \$\$
            DECLARE pago record; recibo record; cantidad integer;
            BEGIN
                SELECT * INTO pago FROM "{$schema}"."pagos" WHERE id = pago_uuid;
                IF pago.id IS NULL THEN RETURN; END IF;
                SELECT COUNT(*) INTO cantidad FROM "{$schema}"."recibos_pago" WHERE pago_id = pago_uuid;
                IF cantidad <> 1 THEN RAISE EXCEPTION 'Cada pago debe conservar exactamente un recibo.' USING ERRCODE = '23514'; END IF;
                SELECT * INTO recibo FROM "{$schema}"."recibos_pago" WHERE pago_id = pago_uuid;
                IF (pago.estado = 'registrado' AND recibo.estado <> 'emitido') OR (pago.estado = 'anulado' AND (recibo.estado <> 'anulado' OR recibo.anulado_por IS DISTINCT FROM pago.anulado_por OR recibo.anulado_at IS DISTINCT FROM pago.anulado_at OR recibo.motivo_anulacion IS DISTINCT FROM pago.motivo_anulacion)) THEN
                    RAISE EXCEPTION 'El ciclo de vida del recibo no corresponde al pago.' USING ERRCODE = '23514';
                END IF;
            END; \$\$;

            CREATE FUNCTION "{$schema}"."validar_pago_recibo_desde_pago"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN PERFORM "{$schema}"."validar_pago_recibo"(NEW.id); RETURN NULL; END; \$\$;

            CREATE FUNCTION "{$schema}"."validar_pago_recibo_desde_recibo"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN PERFORM "{$schema}"."validar_pago_recibo"(COALESCE(NEW.pago_id, OLD.pago_id)); RETURN NULL; END; \$\$;

            CREATE TRIGGER recibos_pago_integridad_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."recibos_pago" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_recibo_pago_integridad"();
            CREATE TRIGGER evidencias_pago_integridad_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."evidencias_pago" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_evidencia_pago_integridad"();
            CREATE CONSTRAINT TRIGGER pagos_recibo_guard AFTER INSERT OR UPDATE OF estado, anulado_por, anulado_at, motivo_anulacion ON "{$schema}"."pagos" DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."validar_pago_recibo_desde_pago"();
            CREATE CONSTRAINT TRIGGER recibos_pago_correspondencia_guard AFTER INSERT OR UPDATE OR DELETE ON "{$schema}"."recibos_pago" DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."validar_pago_recibo_desde_recibo"();
            SQL);
    }

    private function dropLegacyGuards(string $schema): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS recibos_pago_integridad_guard ON \"{$schema}\".\"recibos_pago\"; DROP TRIGGER IF EXISTS evidencias_pago_integridad_guard ON \"{$schema}\".\"evidencias_pago\"; DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_recibo_pago_integridad\"(); DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_evidencia_pago_integridad\"();");
    }

    private function dropIntegrityGuards(string $schema): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS pagos_recibo_guard ON \"{$schema}\".\"pagos\"; DROP TRIGGER IF EXISTS recibos_pago_correspondencia_guard ON \"{$schema}\".\"recibos_pago\"; DROP TRIGGER IF EXISTS recibos_pago_integridad_guard ON \"{$schema}\".\"recibos_pago\"; DROP TRIGGER IF EXISTS evidencias_pago_integridad_guard ON \"{$schema}\".\"evidencias_pago\"; DROP FUNCTION IF EXISTS \"{$schema}\".\"validar_pago_recibo_desde_pago\"(); DROP FUNCTION IF EXISTS \"{$schema}\".\"validar_pago_recibo_desde_recibo\"(); DROP FUNCTION IF EXISTS \"{$schema}\".\"validar_pago_recibo\"(uuid); DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_recibo_pago_integridad\"(); DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_evidencia_pago_integridad\"(); ALTER TABLE \"{$schema}\".\"consecutivos_recibo\" DROP CONSTRAINT IF EXISTS consecutivos_recibo_numero_check; ALTER TABLE \"{$schema}\".\"recibos_pago\" DROP CONSTRAINT IF EXISTS recibos_pago_numero_formato_check; ALTER TABLE \"{$schema}\".\"recibos_pago\" DROP CONSTRAINT IF EXISTS recibos_pago_ciclo_check; ALTER TABLE \"{$schema}\".\"evidencias_pago\" DROP CONSTRAINT IF EXISTS evidencias_pago_sha256_check;");
    }

    private function createLegacyGuards(string $schema): void
    {
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_recibo_pago_integridad"() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Los recibos no pueden eliminarse.' USING ERRCODE = '23514'; END IF; IF NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id OR NEW.pago_id IS DISTINCT FROM OLD.pago_id OR NEW.numero IS DISTINCT FROM OLD.numero OR NEW.fecha_pago_snapshot IS DISTINCT FROM OLD.fecha_pago_snapshot OR NEW.edificio_nombre_snapshot IS DISTINCT FROM OLD.edificio_nombre_snapshot OR NEW.departamento_codigo_snapshot IS DISTINCT FROM OLD.departamento_codigo_snapshot OR NEW.departamento_nombre_snapshot IS DISTINCT FROM OLD.departamento_nombre_snapshot OR NEW.titulares_snapshot IS DISTINCT FROM OLD.titulares_snapshot OR NEW.monto_recibido_snapshot IS DISTINCT FROM OLD.monto_recibido_snapshot OR NEW.forma_pago_snapshot IS DISTINCT FROM OLD.forma_pago_snapshot OR NEW.referencia_snapshot IS DISTINCT FROM OLD.referencia_snapshot OR NEW.aplicaciones_snapshot IS DISTINCT FROM OLD.aplicaciones_snapshot OR NEW.emitido_por IS DISTINCT FROM OLD.emitido_por THEN RAISE EXCEPTION 'Los datos de un recibo son inmutables.' USING ERRCODE = '23514'; END IF; IF OLD.estado <> 'emitido' OR NEW.estado <> 'anulado' THEN RAISE EXCEPTION 'Un recibo sólo puede anularse una vez.' USING ERRCODE = '23514'; END IF; IF NEW.anulado_at IS NULL OR NEW.anulado_por IS NULL OR COALESCE(BTRIM(NEW.motivo_anulacion), '') = '' THEN RAISE EXCEPTION 'La anulación requiere usuario, fecha y motivo.' USING ERRCODE = '23514'; END IF; RETURN NEW; END; \$\$;
            CREATE FUNCTION "{$schema}"."guard_evidencia_pago_integridad"() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Las evidencias de pago son inmutables.' USING ERRCODE = '23514'; END IF; RETURN NEW; END; \$\$;
            CREATE TRIGGER recibos_pago_integridad_guard BEFORE UPDATE OR DELETE ON "{$schema}"."recibos_pago" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_recibo_pago_integridad"();
            CREATE TRIGGER evidencias_pago_integridad_guard BEFORE UPDATE OR DELETE ON "{$schema}"."evidencias_pago" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_evidencia_pago_integridad"();
            SQL);
    }

    private function safeFilename(string $original, string $extension): string
    {
        $name = Str::ascii(pathinfo($original, PATHINFO_FILENAME));
        $name = preg_replace('/[^A-Za-z0-9_ -]+/', '_', $name) ?? '';
        $name = trim($name, " .-_\t\n\r\0\x0B");
        return Str::limit($name === '' ? 'comprobante' : $name, 180, '').'.'.$extension;
    }
};
