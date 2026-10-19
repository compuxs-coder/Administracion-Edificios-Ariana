<?php

namespace Src\Comunicacion\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Comunicacion\Domain\Enums\TipoEventoComunicacion;
use Src\Comunicacion\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class BitacoraComunicacionEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['edificio_id', 'comunicado_id', 'tipo', 'actor_user_id', 'detalle', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('bitacora_comunicacion');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoComunicacion::class,
            'detalle' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
