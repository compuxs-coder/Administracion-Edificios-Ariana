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
        Schema::table($this->table('edificios'), function (Blueprint $table): void {
            $table->string('timezone', 100)->default('America/Guayaquil')->after('ciudad');
        });
        Schema::table($this->table('departamento_residentes'), function (Blueprint $table): void {
            $table->unique(['edificio_id', 'id', 'departamento_id', 'residente_id'], 'ocupaciones_reserva_scope_uq');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('departamento_residentes'), function (Blueprint $table): void {
            $table->dropUnique('ocupaciones_reserva_scope_uq');
        });
        Schema::table($this->table('edificios'), function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
