<?php

namespace Src\Propiedad\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Propiedad\Domain\Enums\TipoIdentificacion;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class TerceroEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'tipo_persona',
        'nombres',
        'apellidos',
        'razon_social',
        'tipo_identificacion',
        'identificacion',
        'telefono',
        'celular',
        'correo',
        'direccion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('terceros');
    }

    /** @return HasOne<PropietarioEloquentModel, $this> */
    public function propietario(): HasOne
    {
        return $this->hasOne(PropietarioEloquentModel::class, 'tercero_id');
    }

    /** @return HasOne<ResidenteEloquentModel, $this> */
    public function residente(): HasOne
    {
        return $this->hasOne(ResidenteEloquentModel::class, 'tercero_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_persona' => TipoPersona::class,
            'tipo_identificacion' => TipoIdentificacion::class,
        ];
    }
}
