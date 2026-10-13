<?php

namespace Src\AreasComunes\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\AreasComunes\Domain\Enums\EstadoEspacioComun;
use Src\AreasComunes\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class EspacioComunEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['edificio_id', 'codigo', 'nombre', 'descripcion', 'capacidad', 'torre_id', 'piso_id', 'ubicacion_detalle', 'estado', 'creado_por_user_id', 'actualizado_por_user_id', 'estado_actualizado_por', 'estado_actualizado_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('espacios_comunes');
    }

    /** @return HasMany<ReglaEspacioComunEloquentModel, $this> */
    public function reglas(): HasMany
    {
        return $this->hasMany(ReglaEspacioComunEloquentModel::class, 'espacio_comun_id');
    }

    /** @return HasMany<ReservaEspacioComunEloquentModel, $this> */
    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaEspacioComunEloquentModel::class, 'espacio_comun_id');
    }

    /** @return HasMany<BitacoraAreaComunEloquentModel, $this> */
    public function bitacora(): HasMany
    {
        return $this->hasMany(BitacoraAreaComunEloquentModel::class, 'espacio_comun_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoEspacioComun::class,
            'estado_actualizado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
