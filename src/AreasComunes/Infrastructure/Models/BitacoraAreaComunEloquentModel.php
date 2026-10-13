<?php

namespace Src\AreasComunes\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\AreasComunes\Domain\Enums\TipoEventoAreaComun;
use Src\AreasComunes\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class BitacoraAreaComunEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['edificio_id', 'espacio_comun_id', 'regla_id', 'reserva_id', 'tipo', 'actor_user_id', 'detalle', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('bitacora_areas_comunes');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoAreaComun::class,
            'detalle' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
