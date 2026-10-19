<?php

namespace Src\Comunicacion\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Comunicacion\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class DocumentoComunicadoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = [
        'edificio_id', 'comunicado_id', 'disco', 'nombre_original', 'mime_type', 'tamano_bytes',
        'sha256', 'ruta_privada', 'descripcion', 'subido_por_user_id', 'created_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('documentos_comunicado');
    }

    protected function casts(): array
    {
        return ['tamano_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
