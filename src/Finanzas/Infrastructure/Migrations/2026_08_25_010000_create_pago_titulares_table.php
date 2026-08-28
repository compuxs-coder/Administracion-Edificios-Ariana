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
        $pagos = $table('pagos');
        $propietarios = $table('propietarios');

        Schema::create($table('pago_titulares'), function (Blueprint $table) use ($pagos, $propietarios): void {
            $table->uuid('id')->primary();
            $table->uuid('pago_id');
            $table->uuid('propietario_id');
            $table->string('nombre_snapshot', 255);
            $table->string('identificacion_snapshot', 50);
            $table->decimal('porcentaje', 9, 6);
            $table->timestampsTz();

            $table->unique(['pago_id', 'propietario_id'], 'pago_titulares_pago_propietario_uq');
            $table->index('propietario_id', 'pago_titulares_propietario_idx');
            $table->foreign('pago_id', 'pago_titulares_pago_fk')->references('id')->on($pagos)->restrictOnDelete();
            $table->foreign('propietario_id', 'pago_titulares_propietario_fk')->references('id')->on($propietarios)->restrictOnDelete();
        });

        if ($pgsql) {
            DB::unprepared(<<<SQL
                CREATE FUNCTION "{$schema}"."guard_pago_titular_integridad"()
                RETURNS trigger
                LANGUAGE plpgsql
                AS \$\$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Los titulares de un pago son inmutables.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                \$\$;
                CREATE TRIGGER pago_titulares_integridad_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."pago_titulares" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_pago_titular_integridad"();
                SQL);
        }
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        if ($pgsql) {
            DB::unprepared("DROP TRIGGER IF EXISTS pago_titulares_integridad_guard ON \"{$schema}\".\"pago_titulares\"");
            DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_pago_titular_integridad\"()");
        }
        Schema::dropIfExists($table('pago_titulares'));
    }
};
