<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPostgreSql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $edificios = $isPostgreSql ? "{$schema}.edificios" : 'edificios';
        $assignments = $isPostgreSql ? "{$schema}.edificio_usuario" : 'edificio_usuario';
        $users = $isPostgreSql ? 'public.users' : 'users';

        Schema::create($edificios, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 150);
            $table->string('ruc', 20)->nullable()->unique();
            $table->string('direccion', 255);
            $table->string('ciudad', 100);
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->string('responsable', 150)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->timestampsTz();

            $table->index(['estado', 'ciudad']);
            $table->index('nombre');
        });

        Schema::create($assignments, function (Blueprint $table) use ($edificios, $users): void {
            $table->uuid('edificio_id');
            $table->uuid('user_id');
            $table->timestampsTz();

            $table->primary(['edificio_id', 'user_id']);
            $table->foreign('edificio_id')
                ->references('id')
                ->on($edificios)
                ->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on($users)
                ->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        $isPostgreSql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $edificios = $isPostgreSql ? "{$schema}.edificios" : 'edificios';
        $assignments = $isPostgreSql ? "{$schema}.edificio_usuario" : 'edificio_usuario';

        Schema::dropIfExists($assignments);
        Schema::dropIfExists($edificios);
    }
};
