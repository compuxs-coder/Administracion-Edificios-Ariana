<?php

namespace Src\Operaciones\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Operaciones\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class EvidenciaOrdenOperativaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = [
        'edificio_id', 'orden_operativa_id', 'nombre_original', 'mime_type', 'tamano_bytes',
        'sha256', 'ruta_privada', 'descripcion', 'subido_por_user_id', 'created_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('evidencias_orden_operativa');
    }

    /** @return BelongsTo<OrdenOperativaEloquentModel, $this> */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenOperativaEloquentModel::class, 'orden_operativa_id');
    }

    protected function casts(): array
    {
        return [
            'tamano_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
