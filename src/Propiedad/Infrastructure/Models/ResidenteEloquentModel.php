<?php

namespace Src\Propiedad\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\EstadoResidente;
use Src\Propiedad\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ResidenteEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['tercero_id', 'estado', 'observaciones'];

    public function getTable(): string
    {
        return $this->qualifiedTable('residentes');
    }

    /** @return BelongsTo<TerceroEloquentModel, $this> */
    public function tercero(): BelongsTo
    {
        return $this->belongsTo(TerceroEloquentModel::class, 'tercero_id');
    }

    /** @return BelongsToMany<EdificioEloquentModel, $this> */
    public function edificios(): BelongsToMany
    {
        return $this->belongsToMany(
            EdificioEloquentModel::class,
            $this->qualifiedTable('residente_edificio'),
            'residente_id',
            'edificio_id',
        )->withTimestamps();
    }

    /** @return HasMany<DepartamentoResidenteEloquentModel, $this> */
    public function ocupaciones(): HasMany
    {
        return $this->hasMany(DepartamentoResidenteEloquentModel::class, 'residente_id');
    }

    protected function casts(): array
    {
        return ['estado' => EstadoResidente::class];
    }
}
