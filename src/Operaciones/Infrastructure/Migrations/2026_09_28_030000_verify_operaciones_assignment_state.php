<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $table = fn (string $name): string => DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
        $hasInvalidAssignment = DB::table($table('asignaciones_orden_operativa').' as assignment')
            ->join($table('ordenes_operativas').' as operation', 'operation.id', '=', 'assignment.orden_operativa_id')
            ->where('operation.estado', 'cancelada')
            ->whereNull('assignment.fecha_fin')
            ->exists();
        if ($hasInvalidAssignment) {
            throw new RuntimeException('Existen órdenes canceladas con responsable vigente; corrija los datos antes de continuar.');
        }
    }

    public function down(): void {}
};
