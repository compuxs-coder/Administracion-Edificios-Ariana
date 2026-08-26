<?php

namespace Src\Propiedad\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\EstadoPropietario;
use Src\Propiedad\Domain\Enums\TipoIdentificacion;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class PropietarioEloquentModel extends Model
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
        'estado',
        'observaciones',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('propietarios');
    }

    /** @return BelongsToMany<EdificioEloquentModel, $this> */
    public function edificios(): BelongsToMany
    {
        return $this->belongsToMany(
            EdificioEloquentModel::class,
            $this->qualifiedTable('propietario_edificio'),
            'propietario_id',
            'edificio_id',
        )->withTimestamps();
    }

    /** @return HasMany<DepartamentoPropietarioEloquentModel, $this> */
    public function titularidades(): HasMany
    {
        return $this->hasMany(DepartamentoPropietarioEloquentModel::class, 'propietario_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_persona' => TipoPersona::class,
            'tipo_identificacion' => TipoIdentificacion::class,
            'estado' => EstadoPropietario::class,
        ];
    }
}
