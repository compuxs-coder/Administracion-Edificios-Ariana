<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Domain\Enums\EstadoEdificio;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;

final class EdificioEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'id',
        'nombre',
        'ruc',
        'direccion',
        'ciudad',
        'telefono',
        'correo',
        'responsable',
        'estado',
        'created_at',
        'updated_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('edificios');
    }

    /** @return BelongsToMany<UserEloquentModel, $this> */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            UserEloquentModel::class,
            $this->qualifiedTable('edificio_usuario'),
            'edificio_id',
            'user_id',
        )->wherePivotNull('revoked_at')->withPivot(['creado_por_user_id', 'revocado_por_user_id', 'revoked_at'])->withTimestamps();
    }

    /** @return HasMany<TorreEloquentModel, $this> */
    public function torres(): HasMany
    {
        return $this->hasMany(TorreEloquentModel::class, 'edificio_id');
    }

    /** @return HasMany<PisoEloquentModel, $this> */
    public function pisos(): HasMany
    {
        return $this->hasMany(PisoEloquentModel::class, 'edificio_id');
    }

    /** @return HasMany<DepartamentoEloquentModel, $this> */
    public function departamentos(): HasMany
    {
        return $this->hasMany(DepartamentoEloquentModel::class, 'edificio_id');
    }

    /** @return HasMany<ParqueaderoEloquentModel, $this> */
    public function parqueaderos(): HasMany
    {
        return $this->hasMany(ParqueaderoEloquentModel::class, 'edificio_id');
    }

    /** @return HasMany<BodegaEloquentModel, $this> */
    public function bodegas(): HasMany
    {
        return $this->hasMany(BodegaEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsToMany<PropietarioEloquentModel, $this> */
    public function propietarios(): BelongsToMany
    {
        return $this->belongsToMany(
            PropietarioEloquentModel::class,
            $this->qualifiedTable('propietario_edificio'),
            'edificio_id',
            'propietario_id',
        )->withTimestamps();
    }

    /** @return BelongsToMany<ResidenteEloquentModel, $this> */
    public function residentes(): BelongsToMany
    {
        return $this->belongsToMany(
            ResidenteEloquentModel::class,
            $this->qualifiedTable('residente_edificio'),
            'edificio_id',
            'residente_id',
        )->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoEdificio::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

}
