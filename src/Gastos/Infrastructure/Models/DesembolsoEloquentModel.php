<?php

namespace Src\Gastos\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Enums\EstadoDesembolso;
use Src\Gastos\Domain\Enums\FormaDesembolso;
use Src\Gastos\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class DesembolsoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $table = 'desembolsos';

    protected $fillable = [
        'edificio_id', 'proveedor_id', 'numero', 'fecha_desembolso', 'monto', 'forma_pago',
        'referencia', 'observacion', 'proveedor_nombre_snapshot', 'proveedor_identificacion_snapshot',
        'estado', 'registrado_por', 'anulado_por', 'anulado_at', 'motivo_anulacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('desembolsos');
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

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'registrado_por');
    }

    /** @return HasMany<AplicacionDesembolsoEloquentModel, $this> */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionDesembolsoEloquentModel::class, 'desembolso_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_desembolso' => 'immutable_date',
            'monto' => 'decimal:4',
            'forma_pago' => FormaDesembolso::class,
            'estado' => EstadoDesembolso::class,
            'anulado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
