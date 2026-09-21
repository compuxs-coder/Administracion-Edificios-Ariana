<?php

namespace Src\Gastos\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Infrastructure\Models\Concerns\UsesApplicationSchema;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;

final class ProveedorEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['tercero_id'];

    public function getTable(): string
    {
        return $this->qualifiedTable('proveedores');
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
            $this->qualifiedTable('proveedor_edificio'),
            'proveedor_id',
            'edificio_id',
        )->withPivot(['estado', 'nombre_comercial', 'contacto', 'telefono', 'correo', 'direccion', 'dias_credito', 'observaciones'])
            ->withTimestamps();
    }

    /** @return HasMany<ContratoProveedorEloquentModel, $this> */
    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoProveedorEloquentModel::class, 'proveedor_id');
    }

    /** @return HasMany<GastoEloquentModel, $this> */
    public function gastos(): HasMany
    {
        return $this->hasMany(GastoEloquentModel::class, 'proveedor_id');
    }
}
