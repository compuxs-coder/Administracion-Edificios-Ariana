<?php

namespace Src\Tesoreria\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Gastos\Infrastructure\Models\DesembolsoEloquentModel;
use Src\Tesoreria\Domain\Enums\EstadoConciliacionTesoreria;
use Src\Tesoreria\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ConciliacionTesoreriaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $table = 'conciliaciones_tesoreria';

    protected $fillable = [
        'edificio_id', 'cuenta_id', 'movimiento_id', 'desembolso_id', 'estado',
        'conciliado_por', 'conciliado_at', 'nota', 'revertido_por', 'revertido_at',
        'motivo_reversion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('conciliaciones_tesoreria');
    }

    /** @return BelongsTo<MovimientoTesoreriaEloquentModel, $this> */
    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoTesoreriaEloquentModel::class, 'movimiento_id');
    }

    /** @return BelongsTo<CuentaTesoreriaEloquentModel, $this> */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreriaEloquentModel::class, 'cuenta_id');
    }

    /** @return BelongsTo<DesembolsoEloquentModel, $this> */
    public function desembolso(): BelongsTo
    {
        return $this->belongsTo(DesembolsoEloquentModel::class, 'desembolso_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function conciliadoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'conciliado_por');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function revertidoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'revertido_por');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoConciliacionTesoreria::class,
            'conciliado_at' => 'immutable_datetime',
            'revertido_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
