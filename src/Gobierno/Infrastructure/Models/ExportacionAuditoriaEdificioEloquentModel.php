<?php

namespace Src\Gobierno\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Src\Gobierno\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ExportacionAuditoriaEdificioEloquentModel extends Model
{
    use UsesApplicationSchema;

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'exportacion_id', 'edificio_id', 'nombre_snapshot', 'timezone_snapshot',
        'permisos_fuente', 'detalle_incluido', 'created_at',
    ];

    protected $primaryKey = null;

    public function getTable(): string
    {
        return $this->qualifiedTable('exportacion_auditoria_edificios');
    }

    protected function casts(): array
    {
        return [
            'permisos_fuente' => 'array',
            'detalle_incluido' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
