<?php

namespace Src\Operaciones\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Operaciones\Domain\Enums\TipoEventoOrdenOperativa;
use Src\Operaciones\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class BitacoraOrdenOperativaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['edificio_id', 'orden_operativa_id', 'tipo', 'actor_user_id', 'detalle', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('bitacora_orden_operativa');
    }

    /** @return BelongsTo<OrdenOperativaEloquentModel, $this> */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenOperativaEloquentModel::class, 'orden_operativa_id');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoOrdenOperativa::class,
            'detalle' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
