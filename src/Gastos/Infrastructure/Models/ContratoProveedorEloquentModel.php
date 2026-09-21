<?php

namespace Src\Gastos\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Enums\EstadoContratoProveedor;
use Src\Gastos\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ContratoProveedorEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'edificio_id', 'proveedor_id', 'referencia', 'objeto', 'fecha_inicio', 'fecha_fin',
        'monto_total', 'observaciones', 'estado', 'proveedor_snapshot', 'registrado_por',
        'registrado_at', 'anulado_por', 'anulado_at', 'motivo_anulacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('contratos_proveedor');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsTo<ProveedorEloquentModel, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorEloquentModel::class, 'proveedor_id');
    }

    /** @return HasMany<GastoEloquentModel, $this> */
    public function gastos(): HasMany
    {
        return $this->hasMany(GastoEloquentModel::class, 'contrato_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'registrado_por');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoContratoProveedor::class,
            'fecha_inicio' => 'immutable_date',
            'fecha_fin' => 'immutable_date',
            'monto_total' => 'decimal:4',
            'proveedor_snapshot' => 'array',
            'registrado_at' => 'immutable_datetime',
            'anulado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
