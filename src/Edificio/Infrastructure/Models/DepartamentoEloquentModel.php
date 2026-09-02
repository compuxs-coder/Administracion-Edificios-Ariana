<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoResidenteEloquentModel;

final class DepartamentoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['codigo', 'nombre', 'alicuota', 'estado', 'observaciones'];

    public function getTable(): string
    {
        return $this->qualifiedTable('departamentos');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsTo<PisoEloquentModel, $this> */
    public function piso(): BelongsTo
    {
        return $this->belongsTo(PisoEloquentModel::class, 'piso_id');
    }

    /** @return HasMany<DepartamentoParqueaderoEloquentModel, $this> */
    public function asignacionesParqueaderos(): HasMany
    {
        return $this->hasMany(DepartamentoParqueaderoEloquentModel::class, 'departamento_id');
    }

    /** @return HasMany<DepartamentoBodegaEloquentModel, $this> */
    public function asignacionesBodegas(): HasMany
    {
        return $this->hasMany(DepartamentoBodegaEloquentModel::class, 'departamento_id');
    }

    /** @return HasMany<DepartamentoPropietarioEloquentModel, $this> */
    public function titularidades(): HasMany
    {
        return $this->hasMany(DepartamentoPropietarioEloquentModel::class, 'departamento_id');
    }

    /** @return HasMany<DepartamentoResidenteEloquentModel, $this> */
    public function ocupaciones(): HasMany
    {
        return $this->hasMany(DepartamentoResidenteEloquentModel::class, 'departamento_id');
    }

    protected function casts(): array
    {
        return ['alicuota' => 'decimal:6', 'estado' => EstadoEstructura::class];
    }
}
