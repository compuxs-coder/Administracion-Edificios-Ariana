<?php

namespace Src\Comunicacion\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Comunicacion\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class DestinatarioComunicadoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = [
        'edificio_id', 'comunicado_id', 'entrega_id', 'tercero_id', 'nombre_snapshot',
        'email_snapshot', 'email_normalizado', 'es_propietario', 'es_residente',
        'departamentos_snapshot', 'estado', 'motivo_omision', 'created_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('destinatarios_comunicado');
    }

    protected function casts(): array
    {
        return [
            'es_propietario' => 'boolean',
            'es_residente' => 'boolean',
            'departamentos_snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
