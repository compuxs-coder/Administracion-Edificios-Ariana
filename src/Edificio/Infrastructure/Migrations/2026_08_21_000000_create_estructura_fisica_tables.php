<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        $edificios = $table('edificios');
        $torres = $table('torres');
        $pisos = $table('pisos');
        $departamentos = $table('departamentos');
        $parqueaderos = $table('parqueaderos');
        $bodegas = $table('bodegas');
        $departamentoParqueaderos = $table('departamento_parqueaderos');
        $departamentoBodegas = $table('departamento_bodegas');

        Schema::create($torres, function (Blueprint $table) use ($edificios): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->string('codigo', 30);
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->boolean('es_predeterminada')->default(false);
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'torres_edificio_id_id_uq');
            $table->unique(['edificio_id', 'codigo'], 'torres_edificio_codigo_uq');
            $table->index(['edificio_id', 'estado'], 'torres_edificio_estado_idx');
            $table->foreign('edificio_id', 'torres_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
        });

        Schema::create($pisos, function (Blueprint $table) use ($torres): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('torre_id');
            $table->string('numero', 20);
            $table->string('nombre', 100)->nullable();
            $table->integer('orden');
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'pisos_edificio_id_id_uq');
            $table->unique(['edificio_id', 'torre_id', 'numero'], 'pisos_torre_numero_uq');
            $table->unique(['edificio_id', 'torre_id', 'orden'], 'pisos_torre_orden_uq');
            $table->index(['edificio_id', 'torre_id', 'estado'], 'pisos_torre_estado_idx');
            $table->foreign(['edificio_id', 'torre_id'], 'pisos_torre_fk')
                ->references(['edificio_id', 'id'])
                ->on($torres)
                ->restrictOnDelete();
        });

        Schema::create($departamentos, function (Blueprint $table) use ($pisos): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('piso_id');
            $table->string('codigo', 40);
            $table->string('nombre', 100);
            $table->decimal('alicuota', 9, 6)->default(0);
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'departamentos_edificio_id_id_uq');
            $table->unique(['edificio_id', 'codigo'], 'departamentos_edificio_codigo_uq');
            $table->index(['edificio_id', 'piso_id', 'estado'], 'departamentos_piso_estado_idx');
            $table->foreign(['edificio_id', 'piso_id'], 'departamentos_piso_fk')
                ->references(['edificio_id', 'id'])
                ->on($pisos)
                ->restrictOnDelete();
        });

        Schema::create($parqueaderos, function (Blueprint $table) use ($edificios, $torres): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('torre_id')->nullable();
            $table->string('codigo', 40);
            $table->string('ubicacion', 150)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'parqueaderos_edificio_id_id_uq');
            $table->unique(['edificio_id', 'codigo'], 'parqueaderos_edificio_codigo_uq');
            $table->index(['edificio_id', 'estado'], 'parqueaderos_edificio_estado_idx');
            $table->foreign('edificio_id', 'parqueaderos_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'torre_id'], 'parqueaderos_torre_fk')
                ->references(['edificio_id', 'id'])
                ->on($torres)
                ->restrictOnDelete();
        });

        Schema::create($bodegas, function (Blueprint $table) use ($edificios, $torres): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('torre_id')->nullable();
            $table->string('codigo', 40);
            $table->string('ubicacion', 150)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'bodegas_edificio_id_id_uq');
            $table->unique(['edificio_id', 'codigo'], 'bodegas_edificio_codigo_uq');
            $table->index(['edificio_id', 'estado'], 'bodegas_edificio_estado_idx');
            $table->foreign('edificio_id', 'bodegas_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'torre_id'], 'bodegas_torre_fk')
                ->references(['edificio_id', 'id'])
                ->on($torres)
                ->restrictOnDelete();
        });

        Schema::create($departamentoParqueaderos, function (Blueprint $table) use ($departamentos, $parqueaderos): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('parqueadero_id');
            $table->timestampTz('fecha_inicio');
            $table->timestampTz('fecha_fin')->nullable();
            $table->timestampsTz();

            $table->index(['edificio_id', 'departamento_id', 'fecha_inicio'], 'dep_parq_departamento_fecha_idx');
            $table->index(['edificio_id', 'parqueadero_id', 'fecha_inicio'], 'dep_parq_parqueadero_fecha_idx');
            $table->foreign(['edificio_id', 'departamento_id'], 'dep_parq_departamento_fk')
                ->references(['edificio_id', 'id'])
                ->on($departamentos)
                ->restrictOnDelete();
            $table->foreign(['edificio_id', 'parqueadero_id'], 'dep_parq_parqueadero_fk')
                ->references(['edificio_id', 'id'])
                ->on($parqueaderos)
                ->restrictOnDelete();
        });

        Schema::create($departamentoBodegas, function (Blueprint $table) use ($departamentos, $bodegas): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('bodega_id');
            $table->timestampTz('fecha_inicio');
            $table->timestampTz('fecha_fin')->nullable();
            $table->timestampsTz();

            $table->index(['edificio_id', 'departamento_id', 'fecha_inicio'], 'dep_bod_departamento_fecha_idx');
            $table->index(['edificio_id', 'bodega_id', 'fecha_inicio'], 'dep_bod_bodega_fecha_idx');
            $table->foreign(['edificio_id', 'departamento_id'], 'dep_bod_departamento_fk')
                ->references(['edificio_id', 'id'])
                ->on($departamentos)
                ->restrictOnDelete();
            $table->foreign(['edificio_id', 'bodega_id'], 'dep_bod_bodega_fk')
                ->references(['edificio_id', 'id'])
                ->on($bodegas)
                ->restrictOnDelete();
        });

        $this->createIntegrityIndexes($pgsql, $schema);
        $this->backfillDefaultTowers($edificios, $torres);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        Schema::dropIfExists($table('departamento_bodegas'));
        Schema::dropIfExists($table('departamento_parqueaderos'));
        Schema::dropIfExists($table('bodegas'));
        Schema::dropIfExists($table('parqueaderos'));
        Schema::dropIfExists($table('departamentos'));
        Schema::dropIfExists($table('pisos'));
        Schema::dropIfExists($table('torres'));
    }

    private function createIntegrityIndexes(bool $pgsql, string $schema): void
    {
        $prefix = $pgsql ? '"'.$schema.'".' : '';
        $booleanTrue = $pgsql ? 'true' : '1';

        DB::statement("CREATE UNIQUE INDEX torres_edificio_predeterminada_unique ON {$prefix}\"torres\" (\"edificio_id\") WHERE \"es_predeterminada\" = {$booleanTrue}");
        DB::statement("CREATE UNIQUE INDEX departamento_parqueaderos_abierto_unique ON {$prefix}\"departamento_parqueaderos\" (\"parqueadero_id\") WHERE \"fecha_fin\" IS NULL");
        DB::statement("CREATE UNIQUE INDEX departamento_bodegas_abierto_unique ON {$prefix}\"departamento_bodegas\" (\"bodega_id\") WHERE \"fecha_fin\" IS NULL");

        if ($pgsql) {
            DB::statement("ALTER TABLE {$prefix}\"departamentos\" ADD CONSTRAINT departamentos_alicuota_check CHECK (alicuota >= 0 AND alicuota <= 100)");
            DB::statement("ALTER TABLE {$prefix}\"departamento_parqueaderos\" ADD CONSTRAINT departamento_parqueaderos_fechas_check CHECK (fecha_fin IS NULL OR fecha_fin > fecha_inicio)");
            DB::statement("ALTER TABLE {$prefix}\"departamento_bodegas\" ADD CONSTRAINT departamento_bodegas_fechas_check CHECK (fecha_fin IS NULL OR fecha_fin > fecha_inicio)");
        }
    }

    private function backfillDefaultTowers(string $edificios, string $torres): void
    {
        $now = now();

        DB::table($edificios)
            ->select('id')
            ->orderBy('id')
            ->each(function (object $edificio) use ($torres, $now): void {
                DB::table($torres)->insert([
                    'id' => (string) Str::uuid(),
                    'edificio_id' => $edificio->id,
                    'codigo' => 'PRINCIPAL',
                    'nombre' => 'Torre principal',
                    'descripcion' => null,
                    'es_predeterminada' => true,
                    'estado' => 'activo',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }
};
